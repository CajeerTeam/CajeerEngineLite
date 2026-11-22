<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Administrative authentication policy; only server-verified SkiF4er identities can use SSO-only. */
final class AuthPolicy
{
    public const HYBRID = 'hybrid';
    public const OIDC_ONLY = 'oidc_only';
    public const DEFAULTS = ['mode'=>self::HYBRID, 'revision'=>1];

    public static function validate(array $policy): void
    {
        if (array_keys($policy) !== ['mode', 'revision'] ||
            !in_array($policy['mode'], [self::HYBRID, self::OIDC_ONLY], true) ||
            !is_int($policy['revision']) || $policy['revision'] < 1 || $policy['revision'] > 2147483646) {
            throw new \RuntimeException('Invalid authentication policy');
        }
    }

    public static function localAllowed(array $site): bool
    {
        return ($site['auth_policy']['mode'] ?? self::HYBRID) === self::HYBRID;
    }

    public static function sessionAllowed(array $site, array $session): bool
    {
        return self::localAllowed($site) ||
            OwnerIdentity::matches($site['identity']['owner'] ?? null, $session['oidc_identity'] ?? null);
    }

    /** Executed while the site writer lock is held, after fresh password and OIDC verification. */
    public static function switch(array $site, string $mode, string $generation, int $revision, array $subject): array
    {
        if (!in_array($mode, [self::HYBRID, self::OIDC_ONLY], true)) {
            throw new \InvalidArgumentException('Unsupported authentication mode');
        }
        self::validate($site['auth_policy']);
        OwnerIdentity::assertGeneration($site, $generation);
        if (!OwnerIdentity::matches($site['identity']['owner'] ?? null, $subject)) {
            throw new \RuntimeException('Linked owner reauthentication is required');
        }
        if ($revision !== $site['auth_policy']['revision'] || $site['auth_policy']['mode'] === $mode) {
            throw new \RuntimeException('Authentication policy changed; reload settings');
        }
        if ($revision >= 2147483646) throw new \RuntimeException('Authentication policy revision exhausted');
        $site['auth_policy'] = ['mode'=>$mode, 'revision'=>$revision + 1];
        // Rotate the owner generation: every previously issued lease fails the next validation.
        $site['identity']['generation'] = OwnerIdentity::generation();
        return $site;
    }

    /** Server-console recovery always re-enables password login before detaching the owner. */
    public static function recover(array $site): array
    {
        self::validate($site['auth_policy']);
        $site['auth_policy'] = ['mode'=>self::HYBRID,
            'revision'=>$site['auth_policy']['revision'] < 2147483646 ? $site['auth_policy']['revision']+1 : 1];
        return $site;
    }
}
