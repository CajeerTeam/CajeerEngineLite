<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

use CajeerEngine\Storage\SiteState;

/** Deployment gate: checks actual local runtime/configuration, never claims to
 * have inspected third-party accounts or sent live conversion events. */
final class ProductionAcceptance
{
    /** @return array{ok:bool,checks:list<array{id:string,status:string,message:string}>,manual:list<string>,providers:array<string,string>} */
    public static function run(string $root, array $state): array
    {
        SiteState::validate($state);
        if ($state['schema'] !== SiteState::SCHEMA) {
            throw new \RuntimeException('Migrate JSON before production acceptance');
        }
        $diagnostics = IntegrationDiagnostics::run($root, $state);
        $checks = $diagnostics['checks'];
        $add = static function(string $id, string $status, string $message) use (&$checks): void {
            $checks[] = compact('id', 'status', 'message');
        };

        if (version_compare(PHP_VERSION, '8.4.1', '<')) {
            $add('runtime.php', 'fail', 'PHP 8.4.1 or newer is required');
        } else {
            $add('runtime.php', 'pass', 'Supported PHP runtime');
        }
        // The runtime diagnostic rejects world-writable assets; the production
        // gate additionally rejects group-writable executable code. A shared
        // web-server group must never be able to modify a served loader.
        foreach (['public/assets', 'public/assets/consent.js', 'public/assets/ga4.js',
                  'public/assets/metrica.js', 'public/assets/gtm.js',
                  'public/assets/clarity.js', 'public/assets/matomo.js'] as $asset) {
            $path = $root.'/'.$asset;
            clearstatcache(true, $path);
            $info = @lstat($path);
            $safe = is_array($info) && ($info['mode'] & 0022) === 0;
            $add('immutable.'.$asset, $safe ? 'pass' : 'fail',
                $safe ? 'Deployment asset is not group/world writable: '.$asset :
                    'Deployment asset is missing or group/world writable: '.$asset);
        }
        $url = $state['settings']['url'];
        if (!str_starts_with($url, 'https://')) {
            $add('deployment.https', 'fail', 'Production site origin must use HTTPS');
        } else {
            $add('deployment.https', 'pass', 'Site origin uses HTTPS');
        }
        $enabled = array_keys(array_filter($state['integrations'],
            static fn(array $config): bool => $config['enabled']));
        if ($enabled && !$state['consent']['enabled']) {
            $add('privacy.consent', 'fail', 'Configured integrations require Cookie Consent');
        } else {
            $add('privacy.consent', 'pass', 'Consent gate is enabled or no integration is configured');
        }
        if ($enabled) {
            $privacy = $state['consent']['privacy_url'];
            if ($privacy === '' || !isset($state['pages'][$privacy]) ||
                $state['pages'][$privacy]['published'] !== true) {
                $add('privacy.policy', 'fail', 'Provide a published local privacy policy page linked from Cookie Consent');
            } else {
                $add('privacy.policy', 'pass', 'Published privacy policy linked from Cookie Consent');
            }
        } else {
            $add('privacy.policy', 'pass', 'No analytics enabled; policy needed before enabling a provider');
        }
        $csp = PublicCsp::headers($state)['policy'];
        foreach (['object-src' => "object-src 'none'", 'form-action' => "form-action 'self'",
                  'inline-script' => "script-src-attr 'none'", 'base-uri' => "base-uri 'none'"] as $id => $directive) {
            $add('csp.'.$id, str_contains($csp, $directive) ? 'pass' : 'fail',
                'Production CSP requires '.$directive);
        }
        if ($state['integrations']['ga4']['enabled'] && $state['integrations']['gtm']['enabled']) {
            $add('analytics.ga4-gtm', 'warn', 'Direct GA4 is suppressed; ensure GA4 exists in GTM and is not duplicated');
        }
        $manual = [];
        foreach ($enabled as $provider) {
            $manual[] = $provider.': verify collection and consent withdrawal in the real provider account on HTTPS staging';
        }
        if (in_array('gtm', $enabled, true)) {
            $manual[] = 'GTM: audit every published tag for required consent and deduplicate GA4/Clarity/Matomo';
        }
        if (in_array('clarity', $enabled, true) || in_array('metrica', $enabled, true)) {
            $manual[] = 'Session recording: verify masking of confidential elements and replay exclusion rules';
        }
        $ok = !in_array('fail', array_column($checks, 'status'), true);
        return ['ok' => $ok, 'checks' => $checks, 'manual' => $manual,
            'providers' => $diagnostics['providers']];
    }
}
