<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** Strict GTM container configuration; only a validated container ID is published. */
final class GTMConfig
{
    public const DEFAULTS = [
        'enabled' => false,
        'container_id' => '',
        'contact_events' => true,
    ];

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^GTM-[A-Z0-9]{4,32}$/D', $id);
    }

    public static function validate(array $config): void
    {
        $keys = array_keys($config);
        $expected = array_keys(self::DEFAULTS);
        sort($keys); sort($expected);
        if ($keys !== $expected || !is_bool($config['enabled']) ||
            !is_bool($config['contact_events']) || !is_string($config['container_id']) ||
            ($config['container_id'] !== '' && !self::validId($config['container_id'])) ||
            ($config['enabled'] && $config['container_id'] === '')) {
            throw new \RuntimeException('Invalid Google Tag Manager configuration');
        }
    }

    /** @param array<string,mixed> $post */
    public static function fromPost(array $post): array
    {
        foreach (['enabled', 'contact_events'] as $key) {
            if (array_key_exists($key, $post) && $post[$key] !== '1') {
                throw new \InvalidArgumentException('Invalid GTM flag: '.$key);
            }
        }
        if (!is_string($post['container_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid GTM container ID');
        }
        $config = [
            'enabled' => ($post['enabled'] ?? null) === '1',
            'container_id' => strtoupper(trim($post['container_id'])),
            'contact_events' => ($post['contact_events'] ?? null) === '1',
        ];
        try { self::validate($config); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(), 0, $e); }
        return $config;
    }

    public static function active(array $state): bool
    {
        return ($state['consent']['enabled'] ?? false) === true &&
               ($state['integrations']['gtm']['enabled'] ?? false) === true;
    }
}
