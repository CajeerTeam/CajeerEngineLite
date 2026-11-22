<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Ownership is an exact OIDC issuer+subject binding, never an email address. */
final class OwnerIdentity
{
    public static function generation(): string { return bin2hex(random_bytes(24)); }

    public static function validSubject(mixed $subject): bool
    {
        return is_array($subject) && array_keys($subject) === ['iss', 'sub'] &&
            ($subject['iss'] ?? null) === OidcClient::ISSUER &&
            is_string($subject['sub'] ?? null) && strlen($subject['sub']) >= 1 &&
            strlen($subject['sub']) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $subject['sub']);
    }

    public static function matches(mixed $owner, mixed $subject): bool
    {
        return self::validSubject($owner) && self::validSubject($subject) &&
            hash_equals($owner['sub'], $subject['sub']);
    }

    /** Prevent stale sessions and in-flight OIDC flows from modifying an updated owner. */
    public static function assertGeneration(array $state, string $expected): void
    {
        $actual = $state['identity']['generation'] ?? null;
        if (!is_string($actual) || !hash_equals($actual, $expected)) {
            throw new \RuntimeException('Owner binding changed; sign in again');
        }
    }

    public static function bind(array $state, array $subject, ?string $expectedGeneration = null): array
    {
        if ($expectedGeneration !== null) self::assertGeneration($state, $expectedGeneration);
        if (($state['identity']['owner'] ?? null) !== null) throw new \RuntimeException('Site owner already bound');
        if (!self::validSubject($subject)) throw new \RuntimeException('Invalid OIDC owner identity');
        $state['identity']['owner'] = ['iss' => $subject['iss'], 'sub' => $subject['sub']];
        $state['identity']['generation'] = self::generation();
        return $state;
    }

    /** Only after current-owner step-up plus local password, inside an exclusive site transaction. */
    public static function replace(array $state, array $oldOwner, array $newOwner, string $expectedGeneration): array
    {
        self::assertGeneration($state, $expectedGeneration);
        if (!self::matches($state['identity']['owner'] ?? null, $oldOwner)) {
            throw new \RuntimeException('The bound owner changed');
        }
        if (!self::validSubject($newOwner)) throw new \RuntimeException('Invalid replacement identity');
        if (self::matches($oldOwner, $newOwner)) throw new \InvalidArgumentException('Choose a different SkiF4er ID account');
        $state['identity']['owner'] = ['iss' => $newOwner['iss'], 'sub' => $newOwner['sub']];
        $state['identity']['generation'] = self::generation();
        return $state;
    }

    /** Unbinding deliberately preserves the local password as the recovery path. */
    public static function unbind(array $state, array $currentOwner, string $expectedGeneration): array
    {
        self::assertGeneration($state, $expectedGeneration);
        if (!self::matches($state['identity']['owner'] ?? null, $currentOwner)) {
            throw new \RuntimeException('The bound owner changed');
        }
        $state['identity']['owner'] = null;
        $state['identity']['generation'] = self::generation();
        return $state;
    }
}
