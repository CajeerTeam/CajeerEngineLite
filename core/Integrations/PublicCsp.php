<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** The single source of truth for the public CSP and the Webvisor frame exception.
 * Provider source permissions are emitted only if the first-party consent UI is
 * enabled and the provider is configured. Browser consent still gates requests.
 */
final class PublicCsp
{
    /** @return array{policy:string,webvisor_frames:bool} */
    public static function headers(array $state): array
    {
        $script = ["'self'"];
        $connect = ["'self'"];
        $img = ["'self'"];
        $frame = "frame-src 'none'";
        $child = "child-src 'none'";
        $ancestors = "frame-ancestors 'none'";
        $webvisorFrames = false;

        if (GA4Config::active($state) || GTMConfig::active($state)) {
            $script[] = 'https://www.googletagmanager.com';
            array_push($connect, 'https://www.googletagmanager.com', 'https://www.google-analytics.com',
                'https://region1.google-analytics.com', 'https://www.google.com');
            array_push($img, 'https://www.google-analytics.com', 'https://region1.google-analytics.com',
                'https://www.google.com');
        }
        if (ClarityConfig::active($state)) {
            $script[] = 'https://www.clarity.ms';
            array_push($connect, 'https://*.clarity.ms', 'https://www.clarity.ms');
            array_push($img, 'https://*.clarity.ms', 'https://www.clarity.ms');
        }
        if (MatomoConfig::active($state)) {
            $mt = $state['integrations']['matomo'];
            foreach ([['script', MatomoConfig::scriptUrl($mt)], ['connect', MatomoConfig::trackerUrl($mt)],
                         ['img', MatomoConfig::trackerUrl($mt)]] as [$type, $url]) {
                $parts = parse_url($url);
                if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
                    throw new \RuntimeException('Invalid Matomo CSP origin');
                }
                $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
                if ($type === 'script') $script[] = $origin;
                elseif ($type === 'connect') $connect[] = $origin;
                else $img[] = $origin;
            }
        }
        if (MetricaConfig::active($state)) {
            $hosts = ['mc.yandex.ru','mc.yandex.az','mc.yandex.by','mc.yandex.co.il',
                'mc.yandex.com','mc.yandex.com.am','mc.yandex.com.ge','mc.yandex.com.tr',
                'mc.yandex.ee','mc.yandex.fr','mc.yandex.kg','mc.yandex.kz',
                'mc.yandex.lt','mc.yandex.lv','mc.yandex.md','mc.yandex.tj',
                'mc.yandex.tm','mc.yandex.uz','mc.webvisor.com','mc.webvisor.org'];
            foreach ($hosts as $host) {
                $source = 'https://'.$host;
                array_push($connect, $source, 'wss://'.$host);
                $img[] = $source;
                $script[] = $source;
            }
            $script[] = 'https://yastatic.net';
            // Replayer frames are unnecessary without Webvisor.
            if ($state['integrations']['metrica']['webvisor']) {
                $frame = 'frame-src blob: https://mc.yandex.ru https://mc.webvisor.com https://mc.webvisor.org';
                $child = 'child-src blob: https://mc.yandex.ru https://mc.webvisor.com https://mc.webvisor.org';
                // These are the official Yandex replay viewers; never relax /admin.
                $ancestors = "frame-ancestors 'self' https://metrika.yandex.ru https://metrica.yandex.ru https://metrica.yandex.com https://metrika.yandex.com https://metrika.yandex.kz https://metrica.yandex.kz https://metrika.yandex.by https://metrica.yandex.by https://metrika.yandex.com.tr https://metrica.yandex.com.tr https://metrica.ya.ru https://metrika.ya.ru";
                $webvisorFrames = true;
            }
        }
        $policy = "default-src 'self'; script-src ".implode(' ', array_unique($script)).
            "; script-src-attr 'none'; connect-src ".implode(' ', array_unique($connect)).
            "; img-src ".implode(' ', array_unique($img)).
            "; style-src 'self'; font-src 'self'; media-src 'none'; ".$frame.'; '.$child.
            "; object-src 'none'; base-uri 'none'; ".$ancestors."; form-action 'self'";
        return ['policy' => $policy, 'webvisor_frames' => $webvisorFrames];
    }
}
