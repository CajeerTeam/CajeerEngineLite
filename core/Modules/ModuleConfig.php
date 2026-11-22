<?php
declare(strict_types=1);
namespace CajeerEngine\Modules;

/** The Lite modules are actual runtime features, not dynamically loaded PHP. */
final class ModuleConfig
{
    public const DEFAULTS = ['navigation' => true, 'robots' => true, 'sitemap' => true];

    public static function validate(array $config): void
    {
        $keys = array_keys($config);
        $allowed = array_keys(self::DEFAULTS);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw new \RuntimeException('Invalid module configuration');
        }
        foreach ($config as $value) {
            if (!is_bool($value)) throw new \RuntimeException('Module flags must be booleans');
        }
    }

    public static function enabled(array $state, string $name): bool
    {
        if (!array_key_exists($name, self::DEFAULTS)) throw new \InvalidArgumentException('Unknown module: '.$name);
        return $state['modules'][$name];
    }

    public static function set(array $state, string $name, bool $enabled): array
    {
        if (!array_key_exists($name, self::DEFAULTS)) throw new \InvalidArgumentException('Unknown module: '.$name);
        $state['modules'][$name] = $enabled;
        return $state;
    }
}
