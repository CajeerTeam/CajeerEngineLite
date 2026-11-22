<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Enforces a pre-authorized owner. Valid OIDC authentication alone never grants admin. */
final class OwnerIdentity
{
    public static function matches(mixed $owner, mixed $subject): bool
    {
        if (!is_array($owner) || !is_array($subject) ||
            ($owner['iss'] ?? null) !== OidcClient::ISSUER ||
            ($subject['iss'] ?? null) !== OidcClient::ISSUER ||
            !is_string($owner['sub'] ?? null) || !is_string($subject['sub'] ?? null) ||
            $owner['sub'] === '' || $subject['sub'] === '') return false;
        return hash_equals($owner['sub'], $subject['sub']);
    }

    public static function bind(array $state, array $subject): array
    {
        if (($state['identity']['owner'] ?? null) !== null) throw new \RuntimeException('Site owner already bound');
        if (($subject['iss'] ?? null) !== OidcClient::ISSUER || !is_string($subject['sub'] ?? null) ||
            strlen($subject['sub']) < 1 || strlen($subject['sub']) > 255 ||
            preg_match('/[\x00-\x1f\x7f]/', $subject['sub'])) {
            throw new \RuntimeException('Invalid OIDC owner identity');
        }
        $state['identity']['owner'] = ['iss' => OidcClient::ISSUER, 'sub' => $subject['sub']];
        return $state;
    }
}
