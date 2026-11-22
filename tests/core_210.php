<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\OperationLock;

function check210(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function rejects210(callable $action, string $reason): void
{
    try {
        $action();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException('Rejected check did not fail: ' . $reason);
}

function cleanup210(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                cleanup210($entry->getPathname());
            }
        }
        rmdir($path);
    }
}

check210(Lite::ENGINE_BASE_VERSION === '2.1.0', 'Wrong upstream core baseline');
$root = sys_get_temp_dir() . '/cajeer-lite-210-' . bin2hex(random_bytes(8));
mkdir($root, 0700);

try {
    $normalRoot = $root . '/normal';
    mkdir($normalRoot, 0700);
    $site = new Lite($normalRoot);
    $site->install('TestPassword123', 'https://example.com', 'Site');
    check210($site->load()['settings']['name'] === 'Site', 'Initial JSON state failed');
    check210((fileperms($site->file()) & 0777) === 0600, 'State permissions are not private');
    check210((fileperms($normalRoot . '/storage/site.lock') & 0002) === 0, 'Lock is world-writable');
    check210($site->login('TestPassword123'), 'Login failed');
    $site->change(static function (array $d): array {
        $d['settings']['name'] = 'Updated';
        return $d;
    });
    check210($site->load()['settings']['name'] === 'Updated', 'Atomic JSON update failed');

    // The 2.1.0 lock opener rejects a symlink in place of a lock.
    $lockPath = $normalRoot . '/storage/site.lock';
    $victim = $root . '/victim';
    file_put_contents($victim, 'unchanged');
    unlink($lockPath);
    symlink($victim, $lockPath);
    rejects210(fn () => $site->change(static fn (array $d): array => $d), 'symlinked lock');
    check210(file_get_contents($victim) === 'unchanged', 'Lock symlink target was touched');
    unlink($lockPath);

    // The 2.1.0 lock opener rejects a multiply linked or world-writable lock.
    link($victim, $lockPath);
    rejects210(fn () => $site->change(static fn (array $d): array => $d), 'hard-linked lock');
    unlink($lockPath);
    file_put_contents($lockPath, '');
    chmod($lockPath, 0666);
    rejects210(fn () => $site->change(static fn (array $d): array => $d), 'world-writable lock');
    chmod($lockPath, 0600);
    check210($site->login('TestPassword123'), 'Lock recovery failed');

    // A swapped JSON state file must not be read or overwritten.
    rename($site->file(), $site->file() . '.original');
    symlink($victim, $site->file());
    rejects210(fn () => $site->load(), 'symlinked JSON read');
    rejects210(fn () => $site->change(static fn (array $d): array => $d), 'symlinked JSON update');
    check210(file_get_contents($victim) === 'unchanged', 'State symlink target was touched');
    unlink($site->file());
    rename($site->file() . '.original', $site->file());

    // The 2.1.0 mkdir primitive rejects symlinks in any missing path's ancestors.
    $outside = $root . '/outside';
    mkdir($outside, 0700);
    symlink($outside, $root . '/link-parent');
    $badSite = new Lite($root . '/link-parent/nested');
    rejects210(fn () => $badSite->install('TestPassword123', 'https://example.com', 'Unsafe'), 'symlink ancestor');
    check210(!file_exists($outside . '/nested'), 'Created a directory through a symlink');

    // Check the primitive is functional for legitimate nested directories and durable writes.
    $nested = $root . '/nested/a/b';
    OperationFiles::dir($nested, 0700);
    OperationFiles::write($nested . '/state.json', '{"ok":true}', 0600);
    check210(OperationFiles::read($nested . '/state.json')['ok'] === true, 'OperationFiles roundtrip failed');
    rejects210(fn () => OperationFiles::dir($root . '/link-parent/new'), 'unsafe directory');
    rejects210(fn () => OperationLock::openLockFile($root . '/link-parent/a.lock'), 'unsafe lock directory');

    // A broken state update callback must not replace the previous committed JSON.
    $before = file_get_contents($site->file());
    rejects210(fn () => $site->change(static function (): never { throw new RuntimeException('abort'); }), 'aborted transaction');
    check210(file_get_contents($site->file()) === $before, 'Aborted change modified committed state');
    check210(count(glob($normalRoot . '/storage/.tmp-*') ?: []) === 0, 'Orphaned temporary files');

    echo "Core 2.1.0 regression OK\n";
} finally {
    cleanup210($root);
}
