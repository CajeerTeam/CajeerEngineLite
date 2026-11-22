<?php
declare(strict_types=1);
namespace CajeerEngine\Storage;

use CajeerEngine\Update\OperationFiles;

/** Private, checksummed snapshots with atomic restore and automatic pre-restore copies. */
final class BackupService
{
    public function __construct(private readonly SiteRepository $site) {}
    private function directory(): string { return OperationFiles::path($this->site->root, 'storage/backups'); }
    private function filename(string $name): string
    {
        if (!preg_match('/^site-\d{8}T\d{6}Z-[a-f0-9]{16}\.json$/D', $name)) throw new \InvalidArgumentException('Invalid backup name');
        return OperationFiles::path($this->site->root, 'storage/backups/'.$name);
    }
    private function ensureDirectory(): void
    {
        $dir = $this->directory();
        OperationFiles::dir($dir, 0700);
        $mode = @fileperms($dir);
        if ($mode === false || ($mode & 0077) !== 0) throw new \RuntimeException('Backup directory must be private (0700)');
    }
    private function encode(string $raw, string $reason): string
    {
        if (!in_array($reason, ['manual','migration','pre-restore'], true)) throw new \InvalidArgumentException('Invalid backup reason');
        if (strlen($raw) > SiteState::LIMIT) throw new \RuntimeException('Backup source exceeds 4 MB');
        return json_encode([
            'format' => 1,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reason' => $reason,
            'sha256' => hash('sha256', $raw),
            'payload' => base64_encode($raw),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
    /** Caller holds the same site lock used by all writes. */
    public function createLocked(string $reason = 'manual'): string
    {
        if (!$this->site->isLocked()) throw new \LogicException('Backup requires an exclusive site lock');
        $raw = $this->site->raw();
        if ($raw === null) throw new \RuntimeException('No site data to back up');
        if ($reason === 'manual') {
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new \RuntimeException('Cannot back up invalid site JSON');
            SiteState::validate($state);
        }
        $this->ensureDirectory();
        $name = 'site-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)).'.json';
        $path = $this->filename($name);
        if (file_exists($path) || is_link($path)) throw new \RuntimeException('Backup name collision');
        OperationFiles::write($path, $this->encode($raw,$reason), 0600);
        return $name;
    }
    public function create(): string
    {
        return $this->site->exclusive(fn() => $this->createLocked());
    }
    /** @return list<string> */
    public function names(): array
    {
        $dir = $this->directory();
        if (!file_exists($dir)) return [];
        $this->ensureDirectory();
        $names = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!preg_match('/^site-\d{8}T\d{6}Z-[a-f0-9]{16}\.json$/D', $name)) {
                throw new \RuntimeException('Unexpected entry in backup directory');
            }
            if ($entry->isLink() || !$entry->isFile()) throw new \RuntimeException('Unsafe backup entry');
            $names[] = $name;
        }
        rsort($names, SORT_STRING);
        return $names;
    }
    /** @return array{format:int,created_at:string,reason:string,sha256:string,payload:string} */
    private function decode(string $name): array
    {
        $this->ensureDirectory();
        $path = $this->filename($name);
        if (is_link($path)) throw new \RuntimeException('Unsafe backup file');
        $entry = @lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1 ||
            ($entry['mode'] & 0077) !== 0 || $entry['size'] > 6_000_000) throw new \RuntimeException('Unsafe backup permissions or size');
        $handle = @fopen($path,'rb');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot read backup');
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($stat) || !is_array($current) || $current['ino'] !== $stat['ino'] ||
                $current['dev'] !== $stat['dev'] || $stat['nlink'] !== 1) throw new \RuntimeException('Backup file changed while reading');
            $json = stream_get_contents($handle, 6_000_001);
            if (!is_string($json) || strlen($json) > 6_000_000) throw new \RuntimeException('Backup too large');
        } finally { fclose($handle); }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 ||
            !is_string($data['payload'] ?? null) || !is_string($data['sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $data['sha256']) ||
            !is_string($data['reason'] ?? null) || !in_array($data['reason'], ['manual','migration','pre-restore'],true) ||
            !is_string($data['created_at'] ?? null)) throw new \RuntimeException('Malformed backup');
        $raw = base64_decode($data['payload'], true);
        if (!is_string($raw) || strlen($raw) > SiteState::LIMIT || !hash_equals($data['sha256'], hash('sha256', $raw))) {
            throw new \RuntimeException('Backup checksum mismatch');
        }
        $data['payload'] = $raw;
        return $data;
    }
    public function verify(string $name): array
    {
        $data = $this->decode($name);
        $state = json_decode($data['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Backup contains invalid state');
        SiteState::validate($state);
        return ['name'=>$name,'created_at'=>$data['created_at'],'reason'=>$data['reason'],'schema'=>$state['schema']];
    }
    public function restore(string $name): string
    {
        // Verify BEFORE mutating anything; on failure the live file is unchanged.
        $info = $this->verify($name);
        return $this->site->exclusive(function () use ($name, $info): string {
            // Re-verify while holding the site lock to detect modifications to the backup.
            $data = $this->decode($name);
            $state = json_decode($data['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new \RuntimeException('Invalid backup state');
            $state = SiteState::migrate($state);
            // A content restore must not roll back credentials previously rotated by
            // a server-console recovery, even when the live site JSON is damaged.
            $live = null;
            try { $live = $this->site->read(); }
            catch (\RuntimeException|\JsonException $exception) { /* damaged live site; protected recovery copy is used */ }
            if ($live !== null && $live['schema'] !== SiteState::SCHEMA) {
                $live = SiteState::migrate($live);
            }
            $credentials = \CajeerEngine\Auth\RecoveryService::restoredCredentials($this->site->root, $live);
            if ($credentials !== null) {
                $state['password'] = $credentials['password'];
                $state['identity'] = $credentials['identity'];
                $state['login'] = $credentials['login'];
            }
            // A damaged live JSON can be recovered: preserve its raw bytes first.
            $rescue = $this->site->raw() === null ? '' : $this->createLocked('pre-restore');
            // Restoring site.json must not resurrect any pre-restore PHP session.
            (new \CajeerEngine\Auth\SessionRegistry($this->site->root))->invalidateAll($state);
            $this->site->commit($state);
            return $rescue;
        });
    }
}
