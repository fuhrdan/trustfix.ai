<?php

declare(strict_types=1);

/*
 * Rebuild image links after a release is unpacked or switched. Laravel always
 * writes uploads to backend/storage/app/public; the public links must resolve
 * to that directory on the active server.
 */

function fail(string $message): never
{
    fwrite(STDERR, "Image storage repair failed: {$message}\n");
    exit(1);
}

function placeholderBackupPath(string $link): string
{
    $base = $link.'.zip-placeholder-'.date('YmdHis');
    $candidate = $base;
    $attempt = 1;

    while (file_exists($candidate) || is_link($candidate)) {
        $candidate = $base.'-'.$attempt;
        $attempt++;
    }

    return $candidate;
}

function ensureLink(string $link, string $target, string $zipPlaceholderTarget): void
{
    if (is_link($link)) {
        $currentTarget = realpath($link);

        if ($currentTarget === $target) {
            echo "OK: {$link} -> {$target}\n";

            return;
        }

        if (! unlink($link)) {
            fail("could not replace stale link {$link}");
        }
    } elseif (is_file($link)) {
        $contents = file_get_contents($link);

        if ($contents === false || trim($contents) !== $zipPlaceholderTarget) {
            fail("{$link} is a real file; refusing to replace it");
        }

        $backup = placeholderBackupPath($link);

        if (! rename($link, $backup)) {
            fail("could not preserve ZIP placeholder {$link}");
        }

        echo "Preserved ZIP placeholder: {$backup}\n";
    } elseif (file_exists($link)) {
        fail("{$link} is a real file or directory; refusing to replace it");
    }

    if (! symlink($target, $link)) {
        fail("could not create {$link}");
    }

    echo "Repaired: {$link} -> {$target}\n";
}

$repositoryRoot = dirname(__DIR__, 2);
$storageTarget = $repositoryRoot.'/backend/storage/app/public';

if (! is_dir($storageTarget) && ! mkdir($storageTarget, 0775, true) && ! is_dir($storageTarget)) {
    fail("could not create {$storageTarget}");
}

$storageTarget = realpath($storageTarget);

if ($storageTarget === false) {
    fail('could not resolve the Laravel public storage directory');
}

if (! is_writable($storageTarget)) {
    fail("{$storageTarget} is not writable by the PHP user");
}

ensureLink(
    $repositoryRoot.'/backend/public/storage',
    $storageTarget,
    '../storage/app/public'
);
ensureLink(
    $repositoryRoot.'/frontend/storage',
    $storageTarget,
    '../backend/storage/app/public'
);

echo "Image storage links are ready.\n";
