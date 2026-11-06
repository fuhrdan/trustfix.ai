<?php

namespace App\Console\Commands;

use App\Models\ContractorDocument;
use App\Models\Document;
use App\Models\JobImage;
use App\Models\ProfileClaim;
use App\Models\PropertyImage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigratePrivateUploads extends Command
{
    protected $signature = 'trustfix:migrate-private-uploads
        {--execute : Copy referenced files from public to private storage}
        {--delete-public : Delete a public source only after its private checksum matches}';

    protected $description = 'Audit or migrate homeowner images and contractor documents to private storage';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $deletePublic = (bool) $this->option('delete-public');

        if ($deletePublic && !$execute) {
            $this->error('--delete-public requires --execute.');

            return self::INVALID;
        }

        $stats = [
            'referenced' => 0,
            'already_private' => 0,
            'public_found' => 0,
            'would_copy' => 0,
            'copied' => 0,
            'public_deleted' => 0,
            'missing' => 0,
            'failed' => 0,
        ];
        $seen = [];

        $groups = [
            [JobImage::query(), 'image_path', 'job image'],
            [PropertyImage::query(), 'image_path', 'property image'],
            [ContractorDocument::query(), 'stored_filename', 'contractor document'],
            [Document::query(), 'file_path', 'handyman document'],
            [ProfileClaim::query(), 'proof_document_path', 'profile claim document'],
        ];

        foreach ($groups as [$query, $column, $label]) {
            foreach ($query->whereNotNull($column)->where($column, '<>', '')
                ->select(['id', $column])->cursor() as $record) {
                $path = $this->safeRelativePath($record->getAttribute($column));
                if ($path === null) {
                    $this->warn("Invalid {$label} path on record {$record->getKey()}.");
                    $stats['failed']++;
                    continue;
                }

                $key = $label . ':' . $path;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $stats['referenced']++;

                $this->processPath($record, $path, $label, $execute, $deletePublic, $stats);
            }
        }

        $this->newLine();
        $this->table(
            ['Referenced', 'Private', 'Public found', 'Would copy', 'Copied', 'Public deleted', 'Missing', 'Failed'],
            [[
                $stats['referenced'],
                $stats['already_private'],
                $stats['public_found'],
                $stats['would_copy'],
                $stats['copied'],
                $stats['public_deleted'],
                $stats['missing'],
                $stats['failed'],
            ]]
        );

        if (!$execute) {
            $this->comment('Dry run only. No files were changed.');
        }

        return ($stats['missing'] > 0 || $stats['failed'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param array<string, int> $stats */
    private function processPath(
        Model $record,
        string $path,
        string $label,
        bool $execute,
        bool $deletePublic,
        array &$stats
    ): void {
        $private = Storage::disk('private');
        $public = Storage::disk('public');
        $privateExists = $private->exists($path);
        $publicExists = $public->exists($path);

        if ($publicExists) {
            $stats['public_found']++;
        }

        if ($privateExists) {
            $stats['already_private']++;

            if ($publicExists) {
                if (!$this->filesMatch($path)) {
                    $this->error("Checksum mismatch for {$label} {$record->getKey()}: {$path}");
                    $stats['failed']++;
                    return;
                }

                if (!$deletePublic) {
                    return;
                }

                if (!$public->delete($path) || $public->exists($path)) {
                    $this->error("Unable to delete public {$label} {$record->getKey()}: {$path}");
                    $stats['failed']++;
                    return;
                }

                $stats['public_deleted']++;
            }

            return;
        }

        if (!$publicExists) {
            $this->warn("Missing {$label} {$record->getKey()}: {$path}");
            $stats['missing']++;
            return;
        }

        if (!$execute) {
            $stats['would_copy']++;
            return;
        }

        try {
            $stream = $public->readStream($path);
            if (!is_resource($stream)) {
                throw new \RuntimeException('Unable to read the public source.');
            }

            try {
                if (!$private->writeStream($path, $stream)) {
                    throw new \RuntimeException('Unable to write the private destination.');
                }
            } finally {
                fclose($stream);
            }

            if (!$this->filesMatch($path)) {
                $private->delete($path);
                throw new \RuntimeException('Source and destination checksums differ.');
            }

            $stats['copied']++;

            if ($deletePublic) {
                if (!$public->delete($path) || $public->exists($path)) {
                    throw new \RuntimeException('The verified public source could not be deleted.');
                }

                $stats['public_deleted']++;
            }
        } catch (Throwable $exception) {
            $this->error(
                "Failed {$label} {$record->getKey()} ({$path}): {$exception->getMessage()}"
            );
            $stats['failed']++;
        }
    }

    private function filesMatch(string $path): bool
    {
        $privatePath = Storage::disk('private')->path($path);
        $publicPath = Storage::disk('public')->path($path);

        if (!is_file($privatePath) || !is_file($publicPath)) {
            return false;
        }

        clearstatcache(true, $privatePath);
        clearstatcache(true, $publicPath);

        $privateSize = filesize($privatePath);
        $publicSize = filesize($publicPath);
        $privateHash = hash_file('sha256', $privatePath);
        $publicHash = hash_file('sha256', $publicPath);

        return is_int($privateSize)
            && is_int($publicSize)
            && $privateSize === $publicSize
            && is_string($privateHash)
            && is_string($publicHash)
            && hash_equals($privateHash, $publicHash);
    }

    private function safeRelativePath(mixed $value): ?string
    {
        $path = str_replace('\\', '/', trim((string) $value));

        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)
        ) {
            return null;
        }

        return $path;
    }
}
