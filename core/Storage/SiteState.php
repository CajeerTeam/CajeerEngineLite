<?php
declare(strict_types=1);
namespace CajeerEngine\Storage;

use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Public\PublicPath;

/** Versioned schema and deterministic upgrade path for the site's durable data. */
final class SiteState
{
    public const SCHEMA = 2;
    public const LIMIT = 4_000_000;

    public static function validate(array $state): void
    {
        $version = $state['schema'] ?? null;
        if ($version !== 1 && $version !== 2) throw new \RuntimeException('Unsupported site schema');
        $settings = $state['settings'] ?? null;
        if (!is_array($settings) || !is_string($settings['name'] ?? null) || trim($settings['name']) === '' ||
            !is_string($settings['url'] ?? null) || !is_string($settings['email'] ?? null) ||
            !is_string($settings['phone'] ?? null)) throw new \RuntimeException('Invalid site settings');
        try { if (PublicPath::base($settings['url']) !== $settings['url']) throw new \RuntimeException('Invalid site origin'); }
        catch (\InvalidArgumentException $e) { throw new \RuntimeException('Invalid site origin', 0, $e); }
        if (strlen($settings['name']) > 200 || strlen($settings['url']) > 500 ||
            strlen($settings['email']) > 254 || strlen($settings['phone']) > 40) throw new \RuntimeException('Site settings exceed limits');
        if (!is_string($state['password'] ?? null) || (password_get_info($state['password'])['algo'] ?? null) === null) {
            throw new \RuntimeException('Invalid password hash');
        }
        $login = $state['login'] ?? null;
        if (!is_array($login) || !is_int($login['count'] ?? null) || $login['count'] < 0 ||
            !is_int($login['until'] ?? null) || $login['until'] < 0) throw new \RuntimeException('Invalid login state');
        $pages = $state['pages'] ?? null;
        if (!is_array($pages) || !isset($pages['/']) || count($pages) > 100) throw new \RuntimeException('Invalid site pages');
        foreach ($pages as $key => $page) {
            if (!is_string($key) || !is_array($page) || ($page['path'] ?? null) !== $key ||
                !str_starts_with($key, '/') || str_contains($key,'.')) throw new \RuntimeException('Invalid page path');
            try { if (PublicPath::content($key) !== $key) throw new \RuntimeException('Invalid page path'); }
            catch (\InvalidArgumentException $e) { throw new \RuntimeException('Invalid page path', 0, $e); }
            foreach (['title'=>200,'description'=>500,'body'=>60000] as $field=>$limit) {
                if (!is_string($page[$field] ?? null) || strlen($page[$field]) > $limit ||
                    !preg_match('//u', $page[$field]) || str_contains($page[$field], "\0")) throw new \RuntimeException('Invalid page field: '.$field);
            }
            if ($page['title'] === '' || !is_bool($page['published'] ?? null) || !is_bool($page['menu'] ?? null)) {
                throw new \RuntimeException('Invalid page flags or title');
            }
        }
        if ($pages['/']['published'] !== true) throw new \RuntimeException('Home page must be published');
        if ($version === 2) {
            if (!is_array($state['modules'] ?? null)) throw new \RuntimeException('Missing modules');
            ModuleConfig::validate($state['modules']);
        }
    }

    public static function migrate(array $state): array
    {
        self::validate($state);
        if ($state['schema'] === 1) {
            $state['modules'] = ModuleConfig::DEFAULTS;
            $state['schema'] = 2;
        }
        self::validate($state);
        return $state;
    }

    public static function encode(array $state): string
    {
        self::validate($state);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (strlen($json) > self::LIMIT) throw new \InvalidArgumentException('Site exceeds 4 MB');
        return $json;
    }
}
