<?php

namespace App\Services;

use App\Models\OperationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function __construct(
        private readonly OperationsAlertService $alerts,
    ) {
    }

    public function run(bool $force = false): OperationRun
    {
        if (!config('operations.backups.enabled') && !$force) {
            throw new RuntimeException('Database backups are disabled. Use --force to run one manually.');
        }

        $startedAt = now();
        $clockStartedAt = microtime(true);
        $run = OperationRun::create([
            'operation' => 'database_backup',
            'status' => 'running',
            'started_at' => $startedAt,
            'summary' => 'Database backup is running.',
        ]);

        $temporaryPath = null;

        try {
            $connectionName = (string) config('database.default');
            $connection = (array) config('database.connections.'.$connectionName, []);
            $driver = (string) ($connection['driver'] ?? $connectionName);
            $temporaryPath = $driver === 'sqlite'
                ? $this->snapshotSqliteDatabase($connection)
                : (
                    in_array($driver, ['mysql', 'mariadb'], true)
                        ? $this->dumpMysqlDatabase($connection)
                        : throw new RuntimeException(
                            'Database backups currently support MySQL, MariaDB, and SQLite. Configured driver: '.$driver
                        )
                );

            $disk = (string) config('operations.backups.disk', 'private');
            $directory = trim((string) config('operations.backups.directory', 'backups/database'), '/');
            $filename = basename($temporaryPath);
            $artifactPath = $directory.'/'.$filename;
            $artifactSize = filesize($temporaryPath);
            $artifactHash = hash_file('sha256', $temporaryPath);
            $readStream = fopen($temporaryPath, 'rb');

            if ($readStream === false) {
                throw new RuntimeException('The completed backup could not be opened for storage.');
            }

            try {
                if (!Storage::disk($disk)->writeStream($artifactPath, $readStream)) {
                    throw new RuntimeException('The completed backup could not be written to the configured disk.');
                }
            } finally {
                fclose($readStream);
            }

            $this->pruneExpiredBackups($disk, $directory, $artifactPath);
            $this->pruneOperationHistory();

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => $this->elapsedMilliseconds($clockStartedAt),
                'summary' => 'Database backup completed successfully.',
                'details' => [
                    'connection' => $connectionName,
                    'driver' => $driver,
                    'retention_days' => (int) config('operations.backups.retention_days'),
                ],
                'artifact_disk' => $disk,
                'artifact_path' => $artifactPath,
                'artifact_size_bytes' => $artifactSize === false ? null : $artifactSize,
                'artifact_sha256' => $artifactHash === false ? null : $artifactHash,
            ]);

            return $run->fresh();
        } catch (Throwable $exception) {
            $safeMessage = $this->safeErrorMessage($exception->getMessage());

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => $this->elapsedMilliseconds($clockStartedAt),
                'summary' => 'Database backup failed.',
                'details' => ['error' => $safeMessage],
            ]);

            $this->alerts->send('Database backup failed', [
                'The scheduled TrustFix database backup did not complete.',
                'Time: '.now()->toIso8601String(),
                'Error: '.$safeMessage,
                'Review the administrator Operations page and storage/logs/laravel.log.',
            ]);

            throw $exception;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function dumpMysqlDatabase(array $connection): string
    {
        $connection = $this->mergeDatabaseUrl($connection);
        $database = trim((string) ($connection['database'] ?? ''));

        if ($database === '') {
            throw new RuntimeException('The database name is missing from the configured connection.');
        }

        $temporaryDirectory = $this->temporaryDirectory();
        $compressed = function_exists('gzopen');
        $filename = 'trustfix-db-'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(3))
            .'-mysql.sql'.($compressed ? '.gz' : '');
        $outputPath = $temporaryDirectory.'/'.$filename;
        $credentialsPath = tempnam($temporaryDirectory, 'mysql-client-');

        if ($credentialsPath === false) {
            throw new RuntimeException('A temporary MySQL credentials file could not be created.');
        }

        $options = [
            '[client]',
            'user='.$this->mysqlOption((string) ($connection['username'] ?? '')),
            'password='.$this->mysqlOption((string) ($connection['password'] ?? '')),
        ];

        $socket = trim((string) ($connection['unix_socket'] ?? ''));

        if ($socket !== '') {
            $options[] = 'socket='.$this->mysqlOption($socket);
        } else {
            $options[] = 'host='.$this->mysqlOption((string) ($connection['host'] ?? '127.0.0.1'));
            $options[] = 'port='.(int) ($connection['port'] ?? 3306);
            $options[] = 'protocol=tcp';
        }

        if (file_put_contents($credentialsPath, implode(PHP_EOL, $options).PHP_EOL, LOCK_EX) === false) {
            @unlink($credentialsPath);
            throw new RuntimeException('The temporary MySQL credentials file could not be written.');
        }
        @chmod($credentialsPath, 0600);

        $sink = $compressed ? gzopen($outputPath, 'wb9') : fopen($outputPath, 'wb');

        if ($sink === false) {
            @unlink($credentialsPath);
            throw new RuntimeException('The temporary backup output file could not be opened.');
        }

        $stderr = '';

        try {
            $command = [
                (string) config('operations.backups.binary', 'mysqldump'),
                '--defaults-extra-file='.$credentialsPath,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--hex-blob',
                '--default-character-set=utf8mb4',
            ];

            if (config('operations.backups.no_tablespaces', true)) {
                $command[] = '--no-tablespaces';
            }

            $command[] = $database;
            $process = new Process($command);
            $process->setTimeout((int) config('operations.backups.timeout_seconds', 300));
            $process->run(function (string $type, string $buffer) use ($compressed, $sink, &$stderr): void {
                if ($type === Process::ERR) {
                    $stderr = mb_substr($stderr.$buffer, -4000);
                    return;
                }

                $written = $compressed ? gzwrite($sink, $buffer) : fwrite($sink, $buffer);

                if ($written === false) {
                    throw new RuntimeException('The database dump could not be written to temporary storage.');
                }
            });

            if (!$process->isSuccessful()) {
                throw new RuntimeException(
                    'mysqldump exited with code '.$process->getExitCode().': '.trim($stderr)
                );
            }
        } finally {
            $compressed ? gzclose($sink) : fclose($sink);
            @unlink($credentialsPath);
        }

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException('mysqldump completed without producing a backup file.');
        }

        return $outputPath;
    }

    private function snapshotSqliteDatabase(array $connection): string
    {
        $sourcePath = (string) ($connection['database'] ?? '');

        if ($sourcePath === '' || $sourcePath === ':memory:' || !is_file($sourcePath)) {
            throw new RuntimeException('The configured SQLite database file could not be found.');
        }

        $temporaryDirectory = $this->temporaryDirectory();
        $compressed = function_exists('gzopen');
        $stem = 'trustfix-db-'.now('UTC')->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'-sqlite';
        $snapshotPath = $temporaryDirectory.'/'.$stem.'.sqlite';
        $outputPath = $snapshotPath.($compressed ? '.gz' : '');
        $pdo = DB::connection((string) config('database.default'))->getPdo();
        $quotedPath = $pdo->quote($snapshotPath);

        if ($quotedPath === false || $pdo->exec('VACUUM INTO '.$quotedPath) === false) {
            throw new RuntimeException('A consistent SQLite snapshot could not be created.');
        }

        if (!$compressed) {
            return $snapshotPath;
        }

        $input = fopen($snapshotPath, 'rb');
        $output = gzopen($outputPath, 'wb9');

        if ($input === false || $output === false) {
            is_resource($input) && fclose($input);
            is_resource($output) && gzclose($output);
            @unlink($snapshotPath);
            throw new RuntimeException('The SQLite backup stream could not be opened.');
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false || gzwrite($output, $chunk) === false) {
                    throw new RuntimeException('The SQLite backup could not be compressed.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
            @unlink($snapshotPath);
        }

        return $outputPath;
    }

    private function mergeDatabaseUrl(array $connection): array
    {
        $url = trim((string) ($connection['url'] ?? ''));

        if ($url === '') {
            return $connection;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException('The configured database URL is invalid.');
        }

        return array_merge($connection, array_filter([
            'host' => isset($parts['host']) ? rawurldecode($parts['host']) : null,
            'port' => $parts['port'] ?? null,
            'database' => isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : null,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function mysqlOption(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('A database credential contains an unsupported newline.');
        }

        return '"'.addcslashes($value, "\\\"").'"';
    }

    private function temporaryDirectory(): string
    {
        $path = storage_path('app/private/backups/tmp');

        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('The private backup directory could not be created.');
        }

        return $path;
    }

    private function pruneExpiredBackups(string $disk, string $directory, string $currentPath): void
    {
        $cutoff = now()->subDays((int) config('operations.backups.retention_days', 14))->getTimestamp();

        foreach (Storage::disk($disk)->files($directory) as $path) {
            if ($path === $currentPath || !str_starts_with(basename($path), 'trustfix-db-')) {
                continue;
            }

            try {
                if (Storage::disk($disk)->lastModified($path) < $cutoff) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (Throwable) {
                // A retention error must not invalidate a completed backup.
            }
        }
    }

    private function pruneOperationHistory(): void
    {
        OperationRun::where(
            'created_at',
            '<',
            now()->subDays((int) config('operations.backups.history_days', 90))
        )->delete();
    }

    private function safeErrorMessage(string $message): string
    {
        $message = preg_replace('/mysql-client-[^\s:]+/', '[credentials-file]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1000);
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
