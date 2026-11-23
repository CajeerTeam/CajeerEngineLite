<?php
declare(strict_types=1);
namespace CajeerEngine\Storage;

use CajeerEngine\Update\OperationFiles;
use CajeerEngine\Update\OperationLock;

/** Serialized transactions and versioned, atomically committed JSON state. */
final class SiteRepository
{
    private bool $locked = false;
    public function __construct(public readonly string $root) {}
    public function file(): string { return OperationFiles::path($this->root, 'storage/site.json'); }
    public function isLocked(): bool { return $this->locked; }

    public function raw(): ?string
    {
        $path = $this->file();
        if (!file_exists($path)) return null;
        // Check the descriptor as well as the directory entry to reject links/swaps.
        $entry = @lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1) {
            throw new \RuntimeException('Unsafe site file');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot read site file');
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($stat) || !is_array($current) ||
                ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 ||
                $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev'] ||
                ($current['mode'] & 0170000) !== 0100000) throw new \RuntimeException('Site file replaced while reading');
            $raw = stream_get_contents($handle, SiteState::LIMIT + 1);
            if (!is_string($raw) || strlen($raw) > SiteState::LIMIT) throw new \RuntimeException('Site file exceeds 4 MB');
            return $raw;
        } finally { fclose($handle); }
    }

    public function read(): ?array
    {
        $raw = $this->raw();
        if ($raw === null) return null;
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Invalid site JSON');
        SiteState::validate($state);
        return $state;
    }

    public function load(): array
    {
        $state = $this->read();
        if ($state === null) throw new \RuntimeException('Site not installed');
        if ($state['schema'] !== SiteState::SCHEMA) {
            // Re-read under the writer lock; preserve a verified pre-migration snapshot.
            return $this->transaction(static fn(array $current): array => $current);
        }
        return $state;
    }

    public function exclusive(callable $fn): mixed
    {
        if ($this->locked) throw new \LogicException('Nested site transaction');
        $storage = OperationFiles::path($this->root, 'storage');
        OperationFiles::dir($storage, 0700);
        // An unpacked release often gives storage/ mode 0755. Before any
        // installation or writer transaction, make it private for site.json,
        // backups and lock data; never loosen private existing permissions.
        $permissions = @fileperms($storage);
        if ($permissions === false ||
            (($permissions & 0077) !== 0 && !@chmod($storage, 0700))) {
            throw new \RuntimeException('Cannot secure private storage directory (0700)');
        }
        clearstatcache(true, $storage);
        if ((fileperms($storage) & 0077) !== 0) {
            throw new \RuntimeException('Private storage directory is not 0700');
        }
        $lock = OperationLock::openLockFile(OperationFiles::path($this->root, 'storage/site.lock'), 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock site state');
            $this->locked = true;
            return $fn($this);
        } finally {
            $this->locked = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function commit(array $state): void
    {
        if (!$this->locked) throw new \LogicException('State writes require an exclusive lock');
        if (($state['schema'] ?? null) !== SiteState::SCHEMA) throw new \RuntimeException('Cannot commit an outdated schema');
        OperationFiles::write($this->file(), SiteState::encode($state), 0600);
    }

    public function transaction(callable $fn): array
    {
        return $this->exclusive(function () use ($fn): array {
            $old = $this->read();
            $migrating = $old !== null && $old['schema'] !== SiteState::SCHEMA;
            if ($migrating) {
                // Never migrate in place without a recoverable snapshot of the original bytes.
                (new BackupService($this))->createLocked('migration');
                $old = SiteState::migrate($old);
            }
            $result = $fn($old);
            if (!is_array($result)) throw new \InvalidArgumentException('Transaction must return a site state');
            // Rejected/no-op updates must not rewrite site.json, consume disk space,
            // or cause a spurious revision change. A migrated state MUST persist.
            if (!$migrating && $old !== null && $result === $old) return $old;
            $this->commit($result);
            return $result;
        });
    }

    public function migrate(): bool
    {
        return $this->exclusive(function (): bool {
            $state = $this->read();
            if ($state === null) throw new \RuntimeException('Site not installed');
            if ($state['schema'] === SiteState::SCHEMA) return false;
            (new BackupService($this))->createLocked('migration');
            $this->commit(SiteState::migrate($state));
            return true;
        });
    }
}
