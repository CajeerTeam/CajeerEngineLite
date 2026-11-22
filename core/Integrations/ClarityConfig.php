<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** Microsoft Clarity project configuration. No remote code or arbitrary URL input. */
final class ClarityConfig
{
    public const DEFAULTS = [
        'enabled' => false,
        'project_id' => '',
        'recordings' => true,
        'contact_events' => false,
    ];

    public static function validId(string $id): bool
    {
        // The official Microsoft Clarity GTM template accepts lowercase a-z/0-9.
        return (bool) preg_match('/^[a-z0-9]{1,40}$/D', $id);
    }

    public static function validate(array $config): void
    {
        $keys = array_keys($config);
        $expected = array_keys(self::DEFAULTS);
        sort($keys); sort($expected);
        if ($keys !== $expected || !is_bool($config['enabled'] ?? null) ||
            !is_bool($config['recordings'] ?? null) ||
            !is_bool($config['contact_events'] ?? null) ||
            !is_string($config['project_id'] ?? null) ||
            ($config['project_id'] !== '' && !self::validId($config['project_id'])) ||
            ($config['enabled'] && $config['project_id'] === '')) {
            throw new \RuntimeException('Invalid Microsoft Clarity configuration');
        }
    }

    /** @param array<string,mixed> $post */
    public static function fromPost(array $post): array
    {
        foreach (['enabled','recordings','contact_events'] as $key) {
            if (array_key_exists($key, $post) && $post[$key] !== '1') {
                throw new \InvalidArgumentException('Invalid Clarity flag: '.$key);
            }
        }
        if (!is_string($post['project_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Clarity project ID');
        }
        $config = [
            'enabled' => ($post['enabled'] ?? null) === '1',
            'project_id' => strtolower(trim($post['project_id'])),
            'recordings' => ($post['recordings'] ?? null) === '1',
            'contact_events' => ($post['contact_events'] ?? null) === '1',
        ];
        try { self::validate($config); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(), 0, $e); }
        return $config;
    }

    public static function active(array $state): bool
    {
        // Clarity combines heatmaps, behavior metrics and recording in one tag.
        // Therefore disabling recordings disables the Clarity tag entirely.
        return ($state['consent']['enabled'] ?? false) === true &&
            ($state['integrations']['clarity']['enabled'] ?? false) === true &&
            ($state['integrations']['clarity']['recordings'] ?? false) === true;
    }
}
