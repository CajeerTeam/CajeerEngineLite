<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

use CajeerEngine\Public\PublicPath;

/** Verify the canonical admin authority and bound work per request before PHP sessions or mutations. */
final class AdminRequestGuard
{
    private const FORM_LIMIT = 350_000;
    private const MEDIA_LIMIT = 8_500_000;
    private const OPERATIONS_LIMIT = 116_000_000;

    /** @throws RequestRejected */
    public static function check(string $siteOrigin, array $server, string $path, string $method): void
    {
        $canonical = PublicPath::base($siteOrigin);
        $site = parse_url($canonical);
        if (!is_array($site)) throw new RequestRejected(421, 'Invalid site origin');
        $isHttps = strtolower($site['scheme']) === 'https';
        if ($isHttps && !in_array($server['HTTPS'] ?? null, ['on', '1'], true)) {
            throw new RequestRejected(403, 'Administrative access requires HTTPS');
        }
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || strlen($host) > 255 || $host === '' || preg_match('/[\x00-\x20\x7f\/@\\\\,]/', $host)) {
            throw new RequestRejected(421, 'Unrecognized admin hostname');
        }
        // Never consult Host for redirects and never accept X-Forwarded-* as proof of TLS.
        $authority = parse_url(($isHttps ? 'https://' : 'http://').$host);
        if (!self::sameAuthority($site, $authority) || !self::pureAuthority($authority)) {
            throw new RequestRejected(421, 'Unrecognized admin hostname');
        }
        if ($method !== 'POST') return;

        $length = $server['CONTENT_LENGTH'] ?? null;
        if ($length !== null && (!is_string($length) && !is_int($length) ||
            !preg_match('/\A[0-9]{1,12}\z/D', (string)$length))) {
            throw new RequestRejected(413, 'Invalid request length');
        }
        $limit = $path === '/admin/operations' ? self::OPERATIONS_LIMIT :
            ($path === '/admin/media' ? self::MEDIA_LIMIT : self::FORM_LIMIT);
        if ($length !== null && (int)$length > $limit) throw new RequestRejected(413, 'Request body too large');
        $transfer = $server['HTTP_TRANSFER_ENCODING'] ?? null;
        if (is_string($transfer) && trim($transfer) !== '') {
            // The CMS needs a bounded body and does not accept chunked uploads.
            throw new RequestRejected(413, 'Chunked administrative uploads are unsupported');
        }

        // This endpoint uses a cryptographically validated OIDC logout token rather
        // than a browser cookie, and can legitimately be called by another origin.
        if ($path === '/admin/oidc/backchannel-logout') return;
        if (($server['HTTP_SEC_FETCH_SITE'] ?? null) === 'cross-site') {
            throw new RequestRejected(403, 'Cross-site administrative request denied');
        }
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            if (!array_key_exists($key, $server)) continue;
            $value = $server[$key];
            if (!is_string($value) || strlen($value) > 4096 || !self::sameSource($canonical, $value, $key === 'HTTP_REFERER')) {
                throw new RequestRejected(403, 'Cross-origin administrative request denied');
            }
        }
    }

    private static function pureAuthority(mixed $parts): bool
    {
        return is_array($parts) && isset($parts['host']) &&
            !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) &&
            !isset($parts['fragment']) && !isset($parts['path']);
    }
    private static function sameAuthority(array $site, mixed $other): bool
    {
        if (!is_array($other) || !is_string($other['host'] ?? null)) return false;
        $port = static fn(array $p): int => $p['port'] ?? (strtolower($p['scheme'] ?? '') === 'https' ? 443 : 80);
        return strtolower($site['host']) === strtolower($other['host']) && $port($site) === $port($other);
    }
    private static function sameSource(string $canonical, string $source, bool $allowPath): bool
    {
        if ($source === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $source) || strlen($source) > 4096) return false;
        $parts = parse_url($source); $site = parse_url($canonical);
        if (!is_array($parts) || !is_array($site) || !isset($parts['scheme'], $parts['host']) ||
            strtolower($parts['scheme']) !== strtolower($site['scheme']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            (!$allowPath && (isset($parts['path']) && $parts['path'] !== '/' || isset($parts['query']) || isset($parts['fragment'])))) return false;
        return self::sameAuthority($site, $parts);
    }
}
