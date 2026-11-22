<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Server-side, single-use login transaction bound to the PHP session. */
final class OidcFlow
{
    public static function start(array &$session, OidcClient $client, string $mode, array $context = []): string
    {
        // A second tab must never replace the PKCE verifier/state of a live transaction.
        $pending = $session['cajeer_oidc_transaction'] ?? null;
        if (is_array($pending) && is_int($pending['created'] ?? null) &&
            $pending['created'] <= time() + 30 && time() - $pending['created'] < 300) {
            throw new \RuntimeException('An OIDC authorization is already in progress; cancel it before retrying');
        }
        unset($session['cajeer_oidc_transaction']);
        $tx = $client->begin($mode);
        // Internal context is set by trusted PHP handlers, never from callback query data.
        if ($context !== []) $tx['context'] = $context;
        $session['cajeer_oidc_transaction'] = $tx;
        return $tx['url'];
    }

    /** Cancel an abandoned transaction only from a CSRF-protected POST handler. */
    public static function cancel(array &$session): void
    {
        unset($session['cajeer_oidc_transaction']);
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
