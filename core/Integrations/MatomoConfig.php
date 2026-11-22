<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** Browser-only Matomo integration. A validated HTTPS base URL is the only remote source. */
final class MatomoConfig
{
    public const DEFAULTS = [
        'enabled' => false,
        'mode' => 'onpremise',
        'server_url' => '',
        'site_id' => '',
        'page_views' => true,
        'contact_events' => false,
    ];

    public static function validSiteId(string $id): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{0,9}$/D', $id);
    }

    /**
     * HTTPS URLs are used in a published script tag and CSP. Accept only an
     * exact host, optional port and simple subdirectory. No user info, fragments,
     * percent escapes, dot segments, or query parameters can change the target.
     */
    public static function canonicalUrl(string $url, string $mode): string
    {
        if (strlen($url) > 512 || !in_array($mode, ['cloud', 'onpremise'], true)) {
            throw new \InvalidArgumentException('Invalid Matomo URL or mode');
        }
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, 'https://') ||
            preg_match('/[\x00-\x20\x7F\\\\%?#@]/', $url)) {
            throw new \InvalidArgumentException('Matomo requires a clean HTTPS URL');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            !is_string($parts['host'] ?? null) || isset($parts['user']) ||
            isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid Matomo URL');
        }
        $host = strtolower($parts['host']);
        // IP addresses (including bracketed IPv6 and private addresses) are
        // permitted for intentional on-premise installations; the Lite server
        // never fetches this user-specified URL itself.
        $ipHost = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1) : $host;
        if (!filter_var($ipHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) &&
            !filter_var($ipHost, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid Matomo host');
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Invalid Matomo port');
        }
        $path = $parts['path'] ?? '';
        if (!preg_match('~^(?:/[A-Za-z0-9_-]+)*/?$~D', $path)) {
            throw new \InvalidArgumentException('Invalid Matomo installation path');
        }
        if ($mode === 'cloud') {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.matomo\.cloud$/D', $host) ||
                $port !== null || ($path !== '' && $path !== '/')) {
                throw new \InvalidArgumentException('Cloud URL must be https://<site>.matomo.cloud/');
            }
        }
        $hostPart = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;
        return 'https://'.$hostPart.($port !== null ? ':'.$port : '').rtrim($path, '/').'/';
    }

    public static function validate(array $config): void
    {
        $keys = array_keys($config);
        $expected = array_keys(self::DEFAULTS);
        sort($keys); sort($expected);
        if ($keys !== $expected || !is_bool($config['enabled']) ||
            !is_bool($config['page_views']) || !is_bool($config['contact_events']) ||
            !is_string($config['mode']) || !in_array($config['mode'], ['cloud', 'onpremise'], true) ||
            !is_string($config['server_url']) || !is_string($config['site_id']) ||
            ($config['site_id'] !== '' && !self::validSiteId($config['site_id'])) ||
            ($config['enabled'] && ($config['server_url'] === '' || $config['site_id'] === ''))) {
            throw new \RuntimeException('Invalid Matomo configuration');
        }
        if ($config['server_url'] !== '') {
            try {
                if (self::canonicalUrl($config['server_url'], $config['mode']) !== $config['server_url']) {
                    throw new \RuntimeException('Matomo URL must be canonical');
                }
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('Invalid Matomo server URL', 0, $e);
            }
        }
    }

    /** @param array<string,mixed> $post */
    public static function fromPost(array $post): array
    {
        foreach (['enabled','page_views','contact_events'] as $key) {
            if (array_key_exists($key, $post) && $post[$key] !== '1') {
                throw new \InvalidArgumentException('Invalid Matomo flag: '.$key);
            }
        }
        foreach (['mode','server_url','site_id'] as $key) {
            if (!is_string($post[$key] ?? null)) {
                throw new \InvalidArgumentException('Invalid Matomo field: '.$key);
            }
        }
        $mode = $post['mode'];
        $url = trim($post['server_url']);
        $config = [
            'enabled' => ($post['enabled'] ?? null) === '1',
            'mode' => $mode,
            'server_url' => $url === '' ? '' : self::canonicalUrl($url, $mode),
            'site_id' => trim($post['site_id']),
            'page_views' => ($post['page_views'] ?? null) === '1',
            'contact_events' => ($post['contact_events'] ?? null) === '1',
        ];
        try { self::validate($config); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(), 0, $e); }
        return $config;
    }

    public static function active(array $state): bool
    {
        return ($state['consent']['enabled'] ?? false) === true &&
            ($state['integrations']['matomo']['enabled'] ?? false) === true;
    }

    public static function scriptUrl(array $config): string
    {
        self::validate($config);
        if ($config['server_url'] === '') throw new \InvalidArgumentException('Matomo URL missing');
        if ($config['mode'] === 'cloud') {
            $host = parse_url($config['server_url'], PHP_URL_HOST);
            return 'https://cdn.matomo.cloud/'.$host.'/matomo.js';
        }
        return $config['server_url'].'matomo.js';
    }

    public static function trackerUrl(array $config): string
    {
        self::validate($config);
        if ($config['server_url'] === '') throw new \InvalidArgumentException('Matomo URL missing');
        return $config['server_url'].'matomo.php';
    }
}
