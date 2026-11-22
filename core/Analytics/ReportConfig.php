<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;

/** Non-secret report resource identifiers; API credentials live only in the server environment. */
final class ReportConfig
{
    public const DEFAULTS = [
        'ga4_property_id' => '',
        'metrica_goal_id' => '',
        'gsc_site_url' => '',
        'yandex_webmaster_user_id' => '',
        'yandex_webmaster_host_id' => '',
    ];

    public static function validate(array $cfg): void
    {
        if (array_keys($cfg) !== array_keys(self::DEFAULTS)) throw new \RuntimeException('Invalid dashboard settings keys');
        foreach ($cfg as $key => $value) {
            if (!is_string($value) || strlen($value) > 255 || str_contains($value,"\0")) {
                throw new \RuntimeException('Invalid dashboard setting: '.$key);
            }
        }
        foreach (['ga4_property_id','metrica_goal_id','yandex_webmaster_user_id'] as $key) {
            if ($cfg[$key] !== '' && !preg_match('/^[1-9][0-9]{0,19}$/D', $cfg[$key])) {
                throw new \RuntimeException('Invalid dashboard numeric ID: '.$key);
            }
        }
        if ($cfg['gsc_site_url'] !== '' && !self::validSearchConsoleSite($cfg['gsc_site_url'])) {
            throw new \RuntimeException('Invalid Search Console property');
        }
        if ($cfg['yandex_webmaster_host_id'] !== '' && !preg_match('~^https?:[A-Za-z0-9.-]+:[0-9]{1,5}$~D', $cfg['yandex_webmaster_host_id'])) {
            throw new \RuntimeException('Invalid Yandex Webmaster host ID');
        }
    }

    public static function validSearchConsoleSite(string $value): bool
    {
        if (preg_match('/^sc-domain:([a-z0-9-]+\.)+[a-z0-9-]+$/Di', $value)) return true;
        if (strlen($value) > 255 || !preg_match('~^https://[^/?#@ ]+/$~D', $value)) return false;
        $parts = parse_url($value);
        return is_array($parts) && isset($parts['host']) && filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public static function fromPost(array $post): array
    {
        $result=[];
        foreach (self::DEFAULTS as $key => $_) {
            if (!is_string($post[$key] ?? null)) throw new \InvalidArgumentException('Missing dashboard field: '.$key);
            $result[$key]=trim($post[$key]);
        }
        try { self::validate($result); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(),0,$e); }
        return $result;
    }
}
