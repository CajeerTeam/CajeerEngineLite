<?php
declare(strict_types=1);
namespace CajeerEngine\Storage;

use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Consent\ConsentConfig;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig};
use CajeerEngine\Public\PublicPath;

/** Versioned schema and deterministic upgrade path for the site's durable data. */
final class SiteState
{
    public const SCHEMA = 6;
    public const LIMIT = 4_000_000;

    public static function validate(array $state): void
    {
        $version = $state['schema'] ?? null;
        if (!in_array($version, [1, 2, 3, 4, 5, 6], true)) throw new \RuntimeException('Unsupported site schema');
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
        if ($version >= 2) {
            if (!is_array($state['modules'] ?? null)) throw new \RuntimeException('Missing modules');
            ModuleConfig::validate($state['modules']);
        }
        if ($version >= 3) {
            if (!is_array($state['consent'] ?? null)) throw new \RuntimeException('Missing consent settings');
            ConsentConfig::validate($state['consent']);
        }
        if ($version >= 4) {
            if (!is_array($state['integrations'] ?? null) ||
                array_keys($state['integrations']) !== ($version >= 6 ? ['ga4','metrica','gtm'] : ($version >= 5 ? ['ga4','metrica'] : ['ga4'])) ||
                !is_array($state['integrations']['ga4'])) throw new \RuntimeException('Invalid integrations configuration');
            GA4Config::validate($state['integrations']['ga4']);
        }
        if ($version >= 5) {
            if (!is_array($state['integrations']['metrica'])) throw new \RuntimeException('Missing Metrica configuration');
            MetricaConfig::validate($state['integrations']['metrica']);
        }
        if ($version >= 6) {
            if (!is_array($state['integrations']['gtm'] ?? null)) throw new \RuntimeException('Missing GTM configuration');
            GTMConfig::validate($state['integrations']['gtm']);
        }
    }

    public static function migrate(array $state): array
    {
        self::validate($state);
        if ($state['schema'] === 1) {
            $state['modules'] = ModuleConfig::DEFAULTS;
            $state['schema'] = 2;
        }
        if ($state['schema'] === 2) {
            $state['consent'] = ConsentConfig::DEFAULTS;
            $state['schema'] = 3;
        }
        if ($state['schema'] === 3) {
            $state['integrations'] = ['ga4' => GA4Config::DEFAULTS];
            $state['schema'] = 4;
        }
        if ($state['schema'] === 4) {
            $state['integrations']['metrica'] = MetricaConfig::DEFAULTS;
            $state['schema'] = 5;
        }
        if ($state['schema'] === 5) {
            $state['integrations']['gtm'] = GTMConfig::DEFAULTS;
            $state['schema'] = 6;
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
