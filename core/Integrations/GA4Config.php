<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** Only supported GA4 settings can enter the persistent JSON and published page. */
final class GA4Config
{
    public const DEFAULTS = [
        'enabled' => false,
        'measurement_id' => '',
        'page_views' => true,
        'contact_clicks' => true,
        'debug_mode' => false,
    ];

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^G-[A-Z0-9]{6,32}$/D', $id);
    }

    public static function validate(array $config): void
    {
        $keys = array_keys($config);
        $expected = array_keys(self::DEFAULTS);
        sort($keys); sort($expected);
        if ($keys !== $expected || !is_bool($config['enabled']) ||
            !is_string($config['measurement_id']) ||
            ($config['measurement_id'] !== '' && !self::validId($config['measurement_id'])) ||
            ($config['enabled'] && $config['measurement_id'] === '')) {
            throw new \RuntimeException('Invalid GA4 configuration');
        }
        foreach (['page_views', 'contact_clicks', 'debug_mode'] as $key) {
            if (!is_bool($config[$key])) throw new \RuntimeException('Invalid GA4 flag: '.$key);
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromPost(array $data): array
    {
        $allowed = ['enabled', 'measurement_id', 'page_views', 'contact_clicks', 'debug_mode'];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue; // Other admin POST fields are handled separately.
            if ($key !== 'measurement_id' && $value !== '1') {
                throw new \InvalidArgumentException('Invalid GA4 flag: '.$key);
            }
        }
        if (!is_string($data['measurement_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid GA4 Measurement ID');
        }
        $result = [
            'enabled' => isset($data['enabled']),
            'measurement_id' => strtoupper(trim($data['measurement_id'])),
            'page_views' => isset($data['page_views']),
            'contact_clicks' => isset($data['contact_clicks']),
            'debug_mode' => isset($data['debug_mode']),
        ];
        try { self::validate($result); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(), 0, $e); }
        return $result;
    }

    public static function active(array $state): bool
    {
        // Fail closed if Cookie Consent is switched off. GA4 must never run without opt-in.
        return $state['consent']['enabled'] && $state['integrations']['ga4']['enabled'];
    }
}
