<?php

namespace App\Console\Commands;

use App\Models\OperationRun;
use App\Services\DatabaseIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class HardenDatabase extends Command
{
    protected $signature = 'trustfix:harden-database
        {--convert : Convert every base table to InnoDB}
        {--add-constraints : Add the expected foreign keys after a clean audit}
        {--confirm= : Exact active database name required for a write phase}
        {--details : Show every expected relationship instead of problems only}';

    protected $description = 'Audit or explicitly harden TrustFix MySQL/MariaDB storage and foreign keys';

    public function handle(DatabaseIntegrityService $integrity): int
    {
        $convert = (bool) $this->option('convert');
        $addConstraints = (bool) $this->option('add-constraints');

        if ($convert && $addConstraints) {
            $this->error('Run --convert and --add-constraints as separate, reviewable phases.');

            return self::INVALID;
        }

        if (!$integrity->supportsCurrentConnection()) {
            $this->error('This command supports only MySQL and MariaDB connections.');

            return self::INVALID;
        }

        try {
            if ($convert || $addConstraints) {
                $this->guardWritePhase($integrity);
            }

            if ($convert) {
                $converted = $integrity->convertAllTablesToInnoDb();
                foreach ($converted as $table) {
                    $this->line('Converted to InnoDB: '.$table);
                }
                $this->info(count($converted).' table(s) converted to InnoDB.');
            }

            if ($addConstraints) {
                $added = $integrity->addMissingForeignKeys();
                foreach ($added as $constraint) {
                    $this->line('Added foreign key: '.$constraint);
                }
                $this->info(count($added).' foreign key constraint(s) added.');
            }

            $audit = $integrity->audit();
            $ready = $this->displayAudit($audit, (bool) $this->option('details'));

            if ($convert) {
                $nonInnoDb = array_filter(
                    $audit['tables'],
                    static fn (array $table): bool => strcasecmp((string) $table['engine'], 'InnoDB') !== 0
                );

                return $nonInnoDb ? self::FAILURE : self::SUCCESS;
            }

            return $ready ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $audit */
    private function displayAudit(array $audit, bool $details): bool
    {
        $tables = $audit['tables'];
        $relations = $audit['relations'];
        $nonInnoDb = array_values(array_filter(
            $tables,
            static fn (array $table): bool => strcasecmp((string) $table['engine'], 'InnoDB') !== 0
        ));
        $required = array_values(array_filter(
            $relations,
            static fn (array $relation): bool => $relation['status'] !== 'optional_absent'
        ));
        $missingConstraints = array_values(array_filter(
            $required,
            static fn (array $relation): bool => $relation['constraint_status'] === 'missing'
        ));
        $mismatchedConstraints = array_values(array_filter(
            $required,
            static fn (array $relation): bool => $relation['constraint_status'] === 'mismatch'
        ));
        $schemaIssues = array_values(array_filter(
            $required,
            static fn (array $relation): bool => $relation['status'] !== 'ready'
        ));
        $typeIssues = array_values(array_filter(
            $required,
            static fn (array $relation): bool => $relation['status'] === 'ready'
                && (!$relation['types_compatible'] || !$relation['nullability_compatible'])
        ));
        $orphanRelations = array_values(array_filter(
            $required,
            static fn (array $relation): bool => (int) ($relation['orphan_count'] ?? 0) > 0
        ));
        $orphanRows = array_sum(array_map(
            static fn (array $relation): int => (int) ($relation['orphan_count'] ?? 0),
            $orphanRelations
        ));

        $this->newLine();
        $this->line('Database: '.$audit['database']);
        $this->line('Server: '.$audit['server']['version']);
        $this->line('Server default engine: '.$audit['server']['default_storage_engine']);
        $this->table(
            ['Base tables', 'Non-InnoDB', 'Expected FKs', 'Missing FKs', 'FK mismatches', 'Orphan rows', 'Schema/type issues'],
            [[
                count($tables),
                count($nonInnoDb),
                count($required),
                count($missingConstraints),
                count($mismatchedConstraints),
                $orphanRows,
                count($schemaIssues) + count($typeIssues),
            ]]
        );

        $problems = array_values(array_filter(
            $required,
            static fn (array $relation): bool => $relation['status'] !== 'ready'
                || !$relation['types_compatible']
                || !$relation['nullability_compatible']
                || (int) ($relation['orphan_count'] ?? 0) > 0
                || $relation['constraint_status'] === 'mismatch'
        ));
        $rows = $details ? $relations : $problems;

        if ($rows) {
            $this->table(
                ['Relationship', 'Delete', 'Schema', 'Types', 'Orphans', 'Constraint'],
                array_map(static fn (array $relation): array => [
                    $relation['label'],
                    $relation['on_delete'],
                    $relation['status'],
                    $relation['status'] === 'ready'
                        ? ($relation['types_compatible'] && $relation['nullability_compatible'] ? 'ok' : 'mismatch')
                        : '-',
                    $relation['orphan_count'] ?? '-',
                    $relation['constraint_status'],
                ], $rows)
            );
        }

        if ($nonInnoDb) {
            $this->warn('Non-InnoDB tables: '.implode(', ', array_column($nonInnoDb, 'name')));
        }

        $ready = !$nonInnoDb
            && !$missingConstraints
            && !$mismatchedConstraints
            && !$schemaIssues
            && !$typeIssues
            && !$orphanRelations;

        $ready
            ? $this->info('Database storage and relational integrity checks passed.')
            : $this->warn('Database hardening is not complete; no automatic repair was performed.');

        return $ready;
    }

    private function guardWritePhase(DatabaseIntegrityService $integrity): void
    {
        $database = $integrity->databaseName();

        if (!hash_equals($database, (string) $this->option('confirm'))) {
            throw new RuntimeException(
                'Write phase refused. Pass --confirm='.$database.' exactly.'
            );
        }

        if (app()->environment('production') && !app()->isDownForMaintenance()) {
            throw new RuntimeException(
                'Production must be in Laravel maintenance mode before a database write phase.'
            );
        }

        $this->verifyRecentBackup();
    }

    private function verifyRecentBackup(): void
    {
        $backup = OperationRun::query()
            ->where('operation', 'database_backup')
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        if (!$backup || $backup->finished_at->lt(now()->subHours(2))) {
            throw new RuntimeException(
                'A successful database backup less than two hours old is required.'
            );
        }

        $diskName = trim((string) $backup->artifact_disk);
        $path = trim((string) $backup->artifact_path);
        $expectedHash = strtolower(trim((string) $backup->artifact_sha256));
        $consistencyMode = trim((string) ($backup->details['consistency_mode'] ?? ''));

        if ($diskName === '' || $path === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new RuntimeException('The latest backup record is incomplete.');
        }

        if (!in_array($consistencyMode, ['table-locks', 'single-transaction'], true)) {
            throw new RuntimeException(
                'The latest backup does not record a verified MySQL consistency mode. Create a fresh backup.'
            );
        }

        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            throw new RuntimeException('The latest database backup artifact is missing.');
        }

        if (
            $backup->artifact_size_bytes !== null
            && (int) $disk->size($path) !== (int) $backup->artifact_size_bytes
        ) {
            throw new RuntimeException('The latest database backup size does not match its record.');
        }

        $stream = $disk->readStream($path);
        if (!is_resource($stream)) {
            throw new RuntimeException('The latest database backup could not be read for verification.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (!hash_equals($expectedHash, strtolower($actualHash))) {
            throw new RuntimeException('The latest database backup checksum does not match its record.');
        }

        $this->info(
            'Verified recent '.$consistencyMode.' backup: '.$path.' (sha256 '.$expectedHash.')'
        );
    }
}
