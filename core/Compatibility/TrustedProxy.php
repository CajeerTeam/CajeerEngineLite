<?php
declare(strict_types=1);
namespace CajeerEngine\Compatibility;

use CajeerEngine\Public\PublicPath;
use CajeerEngine\Security\RequestRejected;

/** Accept proxy authority/TLS only from exact, operator-allowed peer addresses.
 * Reverse proxy MUST overwrite X-Forwarded-Host and X-Forwarded-Proto, not append.
 * Never uses X-Forwarded-For for identity, rate limiting or access control.
 */
final class TrustedProxy
{
    /** @return list<string> */
    public static function addresses(): array
    {
        $config=getenv('CAJEER_TRUSTED_PROXY_IPS');
        if ($config===false || $config==='') return [];
        if (strlen($config)>1024) throw new \RuntimeException('Trusted proxy list too long');
        $parts=explode(',', $config);
        if (count($parts)>16) throw new \RuntimeException('Too many trusted proxies');
        $result=[];
        foreach ($parts as $part) {
            $ip=trim($part);
            if ($ip==='' || filter_var($ip,FILTER_VALIDATE_IP)===false || in_array($ip,$result,true)) {
                throw new \RuntimeException('Trusted proxies must be distinct literal IPv4/IPv6 addresses');
            }
            $result[]=$ip;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function normalize(string $origin,array $server): array
    {
        $allowed=self::addresses();
        $peer=$server['REMOTE_ADDR']??null;
        if (!is_string($peer) || filter_var($peer,FILTER_VALIDATE_IP)===false) return $server;
        $trusted=false;
        foreach ($allowed as $ip) {
            if (inet_pton($peer)===inet_pton($ip)) { $trusted=true; break; }
        }
        if (!$trusted) return $server; // Direct requests retain all existing guards.
        $host=$server['HTTP_X_FORWARDED_HOST']??null;
        $proto=$server['HTTP_X_FORWARDED_PROTO']??null;
        if ($host===null && $proto===null) return $server;
        if (!is_string($host) || !is_string($proto) || strlen($host)>255 ||
            !in_array($proto,['https','http'],true) || $host==='' ||
            preg_match('/[\x00-\x20\x7f\\,@\/]/',$host)) {
            throw new RequestRejected(421,'Invalid trusted proxy authority');
        }
        $expected=parse_url(PublicPath::base($origin));
        $forwarded=parse_url($proto.'://'.$host);
        if (!is_array($forwarded) || !is_array($expected) ||
            isset($forwarded['user']) || isset($forwarded['pass']) ||
            isset($forwarded['path']) || isset($forwarded['query']) || isset($forwarded['fragment']) ||
            strtolower($forwarded['host']??'')!==strtolower($expected['host']??'') ||
            $proto!==strtolower($expected['scheme']??'') ||
            ($forwarded['port']??($proto==='https'?443:80))!==
            ($expected['port']??($proto==='https'?443:80))) {
            throw new RequestRejected(421,'Unrecognized forwarded authority');
        }
        // A proxy indicating plain HTTP cannot override an authenticated TLS signal.
        if ($proto==='http' && in_array($server['HTTPS']??null,['on','1'],true)) {
            throw new RequestRejected(403,'Conflicting TLS proxy metadata');
        }
        $server['HTTPS']=$proto==='https'?'on':'off';
        $server['HTTP_HOST']=$host;
        return $server;
    }
}
