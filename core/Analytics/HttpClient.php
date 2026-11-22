<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;

/** Small bounded HTTPS JSON transport. No redirects, disabled proxies, credential logging, or curl dependency. */
final class HttpClient
{
    /** @param ?callable $driver Injectable network driver for deterministic API contract tests. */
    public function __construct(private readonly mixed $driver = null) {}

    public function json(string $url, string $method='GET', array $headers=[], ?array $payload=null, ?string $matomoHost=null): array
    {
        $body=$payload===null ? '' : json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return $this->request($url,$method,$headers,$body,$payload!==null ? 'application/json' : null,$matomoHost);
    }

    public function form(string $url, array $fields, ?string $matomoHost=null): array
    {
        return $this->request($url,'POST',[],http_build_query($fields,'','&',PHP_QUERY_RFC3986),'application/x-www-form-urlencoded',$matomoHost);
    }

    private function request(string $url, string $method, array $headers, string $body, ?string $contentType, ?string $matomoHost): array
    {
        $parts=parse_url($url);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' || !is_string($parts['host']??null) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) ||
            (preg_match('/[\x00-\x20\x7F]/',$url) || str_contains($url, chr(92))) ||
            !in_array($method,['GET','POST'],true)) throw new \RuntimeException('Unsafe report API URL');
        $host=strtolower($parts['host']);
        $hosts=['analyticsdata.googleapis.com','oauth2.googleapis.com','www.googleapis.com',
            'api-metrika.yandex.net','api.webmaster.yandex.net','www.clarity.ms'];
        if (!in_array($host,$hosts,true) && !($matomoHost!==null && $host===$matomoHost)) {
            throw new \RuntimeException('Unapproved report API host');
        }
        $custom=$host===$matomoHost && !in_array($host,$hosts,true);
        $port=$parts['port']??443;
        if (!$custom && $port!==443) throw new \RuntimeException('Unapproved report API port');
        $pinnedIp=$custom && $this->driver===null ? $this->checkPublicDns($host) : null;
        if (strlen($body)>15000) throw new \RuntimeException('Oversized API request');
        $responseHeaders=['Accept: application/json','Connection: close'];
        foreach ($headers as $header) {
            if (!is_string($header) || strlen($header)>5000 || preg_match('/[\r\n\x00]/',$header)) {
                throw new \RuntimeException('Invalid API header');
            }
            $responseHeaders[]=$header;
        }
        if ($contentType!==null) $responseHeaders[]='Content-Type: '.$contentType;
        if ($this->driver !== null) {
            [$code,$raw]=($this->driver)($url,$method,$responseHeaders,$body);
        } else {
            // On-Premise Matomo: connect to the validated public address, not a second
            // DNS resolution that could rebind to loopback or a private network.
            $connectUrl=$url;
            $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];
            if ($pinnedIp!==null) {
                $ipHost=str_contains($pinnedIp,':') ? '['.$pinnedIp.']' : $pinnedIp;
                $connectUrl='https://'.$ipHost.($port!==443?':'.$port:'').($parts['path']??'/').
                    (isset($parts['query'])?'?'.$parts['query']:'');
                $ssl['peer_name']=$host; $ssl['SNI_enabled']=true; $ssl['SNI_server_name']=$host;
                $responseHeaders[]='Host: '.$host.($port!==443?':'.$port:'');
            }
            $ctx=stream_context_create([
                'http'=>['method'=>$method,'header'=>implode("\r\n",$responseHeaders),
                    'content'=>$body,'timeout'=>8,'follow_location'=>0,'max_redirects'=>0,
                    'ignore_errors'=>true,'protocol_version'=>1.1],
                'ssl'=>$ssl,
            ]);
            $stream=@fopen($connectUrl,'rb',false,$ctx);
            if (!is_resource($stream)) throw new \RuntimeException('Report API unavailable (network/TLS)');
            try {
                $meta=stream_get_meta_data($stream);
                $statusLines=$meta['wrapper_data']??[];
                $code=0;
                foreach ($statusLines as $line) if (preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$m)) $code=(int)$m[1];
                $raw=stream_get_contents($stream,1_048_577);
            } finally { fclose($stream); }
        }
        if (!is_string($raw) || strlen($raw)>1_048_576) throw new \RuntimeException('Report API response exceeds 1 MiB');
        if ($code<200 || $code>=300) throw new \RuntimeException('Report API HTTP '.(int)$code);
        try { $result=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Invalid report API JSON'); }
        if (!is_array($result)) throw new \RuntimeException('Report API JSON is not an object or array');
        return $result;
    }

    private function checkPublicDns(string $hostname): string
    {
        if (filter_var($hostname,FILTER_VALIDATE_IP)) throw new \RuntimeException('Report API host must use a public DNS name');
        if (!function_exists('dns_get_record')) throw new \RuntimeException('DNS verification unavailable');
        $records=@dns_get_record($hostname,DNS_A|DNS_AAAA);
        if (!is_array($records) || $records===[]) throw new \RuntimeException('Report API DNS lookup failed');
        $selected=null;
        foreach ($records as $entry) {
            $ip=$entry['ip']??$entry['ipv6']??null;
            if (!is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Report API points to a non-public IP');
            }
            $selected??=$ip;
        }
        return $selected ?? throw new \RuntimeException('Report API DNS empty');
    }
}
