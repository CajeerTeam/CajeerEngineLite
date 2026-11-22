<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

use CajeerEngine\Lite;
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Server-console-only, one-time emergency account recovery. No HTTP endpoint. */
final class RecoveryService
{
    public const TTL = 600;
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Lite $app) {}

    private function path(string $name): string
    {
        return OperationFiles::path($this->app->root, 'storage/auth/'.$name);
    }

    private function locked(callable $fn): mixed
    {
        // Shares the existing session registry's stringent directory ownership/mode checks.
        (new SessionRegistry($this->app->root))->phpSessionDirectory();
        $handle = OperationLock::openLockFile($this->path('recovery.lock'), 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock recovery operations');
            return $fn();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Read only a regular private file and verify opened descriptor matches its directory entry. */
    private static function secureRead(string $path, int $max): ?string
    {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if ($entry === false) return null;
        if (($entry['mode'] & 0170000) !== 0100000 || ($entry['mode'] & 0077) !== 0 ||
            $entry['nlink'] !== 1 || $entry['size'] > $max) {
            throw new \RuntimeException('Unsafe recovery file permissions or type');
        }
        $file = @fopen($path, 'rb');
        if (!is_resource($file)) throw new \RuntimeException('Cannot open recovery file');
        try {
            $stat = fstat($file);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($stat) || !is_array($after) || ($stat['mode'] & 0170000) !== 0100000 ||
                $stat['nlink'] !== 1 || $stat['ino'] !== $entry['ino'] || $stat['dev'] !== $entry['dev'] ||
                $after['ino'] !== $stat['ino'] || $after['dev'] !== $stat['dev'] ||
                ($after['mode'] & 0170000) !== 0100000 || ($after['mode'] & 0077) !== 0) {
                throw new \RuntimeException('Recovery file replaced during read');
            }
            $raw = stream_get_contents($file, $max + 1);
            if (!is_string($raw) || strlen($raw) > $max) throw new \RuntimeException('Recovery file too large');
            return $raw;
        } finally { fclose($file); }
    }

    private function grant(): ?array
    {
        $raw = self::secureRead($this->path('recovery.json'), 2048);
        if ($raw === null) return null;
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['version','digest','issued','expires','attempts','generation','proof'] ||
            ($data['version'] ?? null) !== 1 || !is_int($data['issued']) || !is_int($data['expires']) ||
            !is_int($data['attempts']) || $data['attempts'] < 0 || $data['attempts'] > self::MAX_ATTEMPTS ||
            $data['expires'] - $data['issued'] !== self::TTL ||
            !is_string($data['digest']) || !preg_match('/\A[a-f0-9]{64}\z/D', $data['digest']) ||
            !is_string($data['proof']) || !preg_match('/\A[a-f0-9]{64}\z/D', $data['proof']) ||
            !is_string($data['generation']) || !preg_match('/\A[a-f0-9]{48}\z/D', $data['generation'])) {
            throw new \RuntimeException('Invalid recovery state');
        }
        return $data;
    }

    private function save(array $grant): void
    {
        OperationFiles::json($this->path('recovery.json'), $grant, 0600);
    }

    /** Persistent redacted log; never includes token, password, username, email or OIDC claims. */
    private function audit(string $event, int $clock): void
    {
        $path = $this->path('recovery-audit.jsonl');
        $entry = @lstat($path);
        if ($entry !== false && (($entry['mode'] & 0170000) !== 0100000 ||
            $entry['nlink'] !== 1 || ($entry['mode'] & 0077) !== 0 || $entry['size'] > 2_000_000)) {
            throw new \RuntimeException('Unsafe recovery audit log');
        }
        $handle = $entry === false ? @fopen($path, 'x+b') : @fopen($path, 'rb+');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot open recovery audit log');
        try {
            if ($entry === false && !@chmod($path, 0600)) throw new \RuntimeException('Cannot protect audit log');
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $now = @lstat($path);
            if (!is_array($stat) || !is_array($now) || $stat['ino'] !== $now['ino'] ||
                $stat['dev'] !== $now['dev'] || $stat['nlink'] !== 1 ||
                ($stat['mode'] & 0170000) !== 0100000 || ($now['mode'] & 0077) !== 0) {
                throw new \RuntimeException('Audit log changed during write');
            }
            // Seek only after comparing the descriptor with the directory entry.
            if (fseek($handle, 0, SEEK_END) !== 0) throw new \RuntimeException('Cannot seek recovery audit');
            $row = json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z', $clock), 'event' => $event], JSON_THROW_ON_ERROR)."\n";
            if (fwrite($handle, $row) !== strlen($row) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Cannot persist recovery audit');
            }
        } finally { fclose($handle); }
    }

    /** Token is returned only to the trusted CLI process; only its digest is persisted. */
    public function issue(?int $clock = null): string
    {
        return $this->locked(function () use ($clock): string {
            $site = $this->app->load();
            $now = $clock ?? time();
            $token = bin2hex(random_bytes(32));
            $this->audit('issued', $now);
            $this->save(['version' => 1, 'digest' => hash('sha256', hex2bin($token)),
                'issued' => $now, 'expires' => $now + self::TTL, 'attempts' => 0,
                'generation' => $site['identity']['generation'], 'proof' => hash('sha256', $site['password'])]);
            return $token;
        });
    }

    /** Finalizing consumes the grant before credential mutation. A crash requires a fresh grant. */
    public function complete(string $token, string $password, ?int $clock = null): void
    {
        $this->locked(function () use ($token, $password, $clock): void {
            $now = $clock ?? time();
            $grant = $this->grant();
            if ($grant === null) throw new \RuntimeException('No active recovery grant');
            if ($now < $grant['issued'] || $now >= $grant['expires'] || $grant['attempts'] >= self::MAX_ATTEMPTS) {
                $this->audit('expired_or_locked', $now);
                $this->clearGrant();
                throw new \RuntimeException('Recovery grant expired or locked');
            }
            // Invalid token attempts are rate-limited in durable state, including across CLI processes.
            $wellFormed = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1;
            $digest = $wellFormed ? hash('sha256', hex2bin($token)) : str_repeat('0', 64);
            if (!hash_equals($grant['digest'], $digest)) {
                ++$grant['attempts'];
                $this->audit('invalid_token', $now);
                if ($grant['attempts'] >= self::MAX_ATTEMPTS) $this->clearGrant();
                else $this->save($grant);
                throw new \RuntimeException('Invalid recovery token');
            }
            if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0") ||
                !preg_match('//u', $password)) {
                throw new \InvalidArgumentException('New password must be 12–72 UTF-8 bytes without NUL');
            }
            $site = $this->app->load();
            if (!hash_equals($grant['generation'], $site['identity']['generation']) ||
                !hash_equals($grant['proof'], hash('sha256', $site['password']))) {
                $this->audit('stale_grant', $now);
                $this->clearGrant();
                throw new \RuntimeException('Owner or password changed since grant was issued');
            }
            if (password_verify($password, $site['password'])) {
                throw new \InvalidArgumentException('New password must differ from the current password');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash)) throw new \RuntimeException('Cannot hash recovery password');
            $this->audit('authorized', $now);
            $this->clearGrant(); // Consume before changing protected data: replay fails closed.
            $this->app->change(function (array $current) use ($grant, $hash): array {
                OwnerIdentity::assertGeneration($current, $grant['generation']);
                if (!hash_equals(hash('sha256', $current['password']), $grant['proof'])) {
                    throw new \RuntimeException('Password was changed during recovery');
                }
                $current['password'] = $hash;
                $current['login'] = ['count' => 0, 'until' => 0];
                $current = AuthPolicy::recover($current);
                $current['identity']['owner'] = null; // Owner must rebind with a fresh verified OIDC login.
                $current['identity']['generation'] = OwnerIdentity::generation();
                // Protect recovered credentials against a later restore of pre-recovery site backups.
                $this->protectCredentials($current);
                (new SessionRegistry($this->app->root))->invalidateAll($current);
                return $current;
            });
            $this->audit('completed', $now);
        });
    }

    public function cancel(?int $clock = null): void
    {
        $this->locked(function () use ($clock): void {
            $grant = $this->grant();
            $now = $clock ?? time();
            if ($grant !== null && $now >= $grant['issued'] &&
                $now < $grant['expires'] && $grant['attempts'] < self::MAX_ATTEMPTS) {
                $this->audit('cancelled', $now);
                $this->clearGrant();
            }
        });
    }

    public function status(?int $clock = null): array
    {
        return $this->locked(function () use ($clock): array {
            $state = $this->grant();
            $now = $clock ?? time();
            $active = $state !== null && $now >= $state['issued'] &&
                $now < $state['expires'] && $state['attempts'] < self::MAX_ATTEMPTS;
            return ['active' => $active, 'expires' => $active ? $state['expires'] : null,
                'attempts_remaining' => $active ? self::MAX_ATTEMPTS - $state['attempts'] : 0];
        });
    }

    private function protectCredentials(array $site): void
    {
        OperationFiles::json($this->path('recovery-protected.json'), [
            'version'=>2, 'password'=>$site['password'], 'identity'=>$site['identity'],
            'auth_policy'=>$site['auth_policy'],
            'protected_at'=>time(),
        ], 0600);
    }

    private static function validProtectedPolicy(array $policy): bool
    {
        try { AuthPolicy::validate($policy); return true; }
        catch (\RuntimeException) { return false; }
    }

    /** Restores may recover content but must never reinstate pre-recovery credentials. */
    public static function restoredCredentials(string $root, ?array $live): ?array
    {
        $path = OperationFiles::path($root, 'storage/auth/recovery-protected.json');
        $raw = self::secureRead($path, 4096);
        if ($raw === null) return null;
        $saved = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($saved) ||
            !in_array($saved['version'] ?? null, [1,2], true) ||
            array_keys($saved) !== ($saved['version'] === 2 ?
                ['version','password','identity','auth_policy','protected_at'] :
                ['version','password','identity','protected_at']) ||
            ($saved['version'] === 2 && (!is_array($saved['auth_policy']) || !self::validProtectedPolicy($saved['auth_policy']))) || !is_int($saved['protected_at']) ||
            !is_string($saved['password']) || password_get_info($saved['password'])['algo'] === null ||
            !is_array($saved['identity']) || array_keys($saved['identity']) !== ['owner','generation'] ||
            !is_string($saved['identity']['generation']) ||
            !preg_match('/\A[a-f0-9]{48}\z/D', $saved['identity']['generation']) ||
            ($saved['identity']['owner'] !== null && !OwnerIdentity::validSubject($saved['identity']['owner']))) {
            throw new \RuntimeException('Unsafe protected recovery credentials');
        }
        // A valid live site may contain newer legitimate binds / password updates.
        $source = $live ?? $saved;
        return ['password'=>$source['password'], 'identity'=>$source['identity'],
            'login'=>['count'=>0,'until'=>0],
            'auth_policy'=>$source['auth_policy'] ?? AuthPolicy::DEFAULTS];
    }

    private function clearGrant(): void
    {
        // Replacing with a tombstone prevents an old grant reappearing after an interrupted unlink.
        $this->save(['version' => 1, 'digest' => str_repeat('0',64), 'issued' => 0,
            'expires' => self::TTL, 'attempts' => self::MAX_ATTEMPTS,
            'generation' => str_repeat('0',48), 'proof' => str_repeat('0',64)]);
    }

    /** CLI inputs are file-backed to keep secrets out of process arguments and shell history. */
    public static function readInput(string $root, string $filename, int $limit): string
    {
        if ($filename === '' || str_contains($filename, "\0") || !str_starts_with($filename, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $filename)) {
            throw new \InvalidArgumentException('Provide an absolute private file path');
        }
        $real = realpath($filename);
        $public = realpath($root.'/public');
        if ($real === false || ($public !== false && ($real === $public || str_starts_with($real, $public.DIRECTORY_SEPARATOR)))) {
            throw new \RuntimeException('Recovery credentials must not be inside public/');
        }
        $raw = self::secureRead($filename, $limit);
        if ($raw === null) throw new \RuntimeException('Missing recovery credential file');
        return rtrim($raw, "\r\n");
    }
}
