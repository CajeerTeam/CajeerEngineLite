<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

use CajeerEngine\Storage\SiteState;

/** Runtime diagnostics; no external requests or credentials, no public endpoint. */
final class IntegrationDiagnostics
{
    private const ASSETS = ['consent' => 'consent.js', 'ga4' => 'ga4.js',
        'metrica' => 'metrica.js', 'gtm' => 'gtm.js', 'clarity' => 'clarity.js', 'matomo' => 'matomo.js'];

    /** @return array{ok:bool,checks:list<array{id:string,status:string,message:string}>,providers:array<string,string>} */
    public static function run(string $root, array $state): array
    {
        SiteState::validate($state);
        if ($state['schema'] !== SiteState::SCHEMA) throw new \RuntimeException('Run php bin/cajeer migrate first');
        $checks = [];
        $add = static function(string $id, string $status, string $message) use (&$checks): void {
            $checks[] = compact('id', 'status', 'message');
        };
        $scriptRoot = $root.'/public/assets';
        $assetDir = @lstat($scriptRoot);
        if (!is_array($assetDir) || ($assetDir['mode'] & 0170000) !== 0040000 ||
            ($assetDir['mode'] & 0002) !== 0) {
            $add('permissions.assets', 'fail', 'Public assets directory must be a non-world-writable real directory');
        } else $add('permissions.assets', 'pass', 'Public assets directory is not world writable');
        foreach (self::ASSETS as $id => $file) {
            $path = $scriptRoot.'/'.$file;
            clearstatcache(true, $path);
            $entry = @lstat($path);
            if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1 ||
                ($entry['mode'] & 0002) !== 0 || $entry['size'] < 100 || $entry['size'] > 100_000 ||
                !is_readable($path)) {
                $add('asset.'.$id, 'fail', 'Missing, unsafe or world-writable first-party runtime: '.$file);
            } else {
                $add('asset.'.$id, 'pass', 'Local runtime available: '.$file.' (sha256 '.substr(hash_file('sha256', $path), 0, 12).')');
            }
        }
        $storage = $root.'/storage';
        $file = $storage.'/site.json';
        foreach (['storage' => $storage, 'state' => $file] as $id => $path) {
            clearstatcache(true, $path);
            $info = @lstat($path);
            if (!is_array($info) || ($info['mode'] & 0077) !== 0 ||
                ($info['mode'] & 0170000) !== ($id === 'storage' ? 0040000 : 0100000)) {
                $add('permissions.'.$id, 'fail', 'Private directory/file requires permissions 0700/0600: '.$path);
            } else $add('permissions.'.$id, 'pass', 'Private permissions: '.$id);
        }
        $consent = $state['consent']['enabled'];
        $providers = [];
        $active = ['ga4' => GA4Config::active($state) && !GTMConfig::active($state),
            'metrica' => MetricaConfig::active($state), 'gtm' => GTMConfig::active($state),
            'clarity' => ClarityConfig::active($state), 'matomo' => MatomoConfig::active($state)];
        foreach ($active as $id => $enabled) {
            $configured = $state['integrations'][$id]['enabled'];
            $providers[$id] = $enabled ? 'active' : ($configured ? 'blocked' : 'disabled');
            if ($configured && !$consent) {
                $add('provider.'.$id, 'warn', $id.' configured but Cookie Consent is disabled; no tag will load');
            } elseif ($id === 'ga4' && $configured && $active['gtm']) {
                $add('provider.ga4', 'warn', 'Direct GA4 tag suppressed by GTM; configure GA4 in container to receive events');
            } elseif ($id === 'clarity' && $configured && !$state['integrations']['clarity']['recordings']) {
                $add('provider.clarity', 'warn', 'Clarity recordings disabled; entire Clarity tag stays off');
            } else {
                $add('provider.'.$id, 'pass', $id.': '.$providers[$id]);
            }
        }
        $policy = PublicCsp::headers($state);
        if (str_contains($policy['policy'], "'unsafe-inline'") || str_contains($policy['policy'], "'unsafe-eval'") ||
            !str_contains($policy['policy'], "script-src-attr 'none'") ||
            (!$consent && preg_match('~https://(?:www\.googletagmanager\.com|mc\.yandex\.ru|www\.clarity\.ms|cdn\.matomo\.cloud)~', $policy['policy']))) {
            $add('csp', 'fail', 'Public CSP permits unsafe script behavior');
        } else $add('csp', 'pass', 'Public script policy is explicit and blocks inline handlers');
        if ($state['integrations']['metrica']['webvisor'] && $active['metrica'] && $policy['webvisor_frames']) {
            $add('webvisor.frames', 'warn', 'Webvisor requires the published page to allow Yandex replay framing; admin remains DENY');
        }
        $ok = !in_array('fail', array_column($checks, 'status'), true);
        return ['ok' => $ok, 'checks' => $checks, 'providers' => $providers];
    }
}
