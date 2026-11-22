<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Server-side, single-use login transaction bound to the PHP session. */
final class OidcFlow
{
    public static function start(array &$session, OidcClient $client, string $mode, array $context = []): string
    {
        // Prevent two tabs replacing a pending login without an explicit new start.
        $tx = $client->begin($mode);
        // Internal context is set by trusted PHP handlers, never from callback query data.
        if ($context !== []) $tx['context'] = $context;
        $session['cajeer_oidc_transaction'] = $tx;
        return $tx['url'];
    }

    /** @return array{mode:string,identity:array{iss:string,sub:string}} */
    public static function finish(array &$session, OidcClient $client, array $query): array
    {
        $tx = $session['cajeer_oidc_transaction'] ?? null;
        unset($session['cajeer_oidc_transaction']); // consumed even on errors or network failures
        if (!is_array($tx) || !in_array($tx['mode'] ?? null, ['login', 'bind', 'replace_current', 'replace_new', 'unbind', 'auth_policy'], true)) {
            throw new \RuntimeException('OIDC login was not initiated in this browser');
        }
        return ['mode' => $tx['mode'], 'identity' => $client->complete($tx, $query),
            'context' => $tx['context'] ?? []];
    }
}
