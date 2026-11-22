<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Safe, read-only HTTPS acceptance against the configured public installation.
 * Does not send credentials, cookies or POST requests to the deployed site.
 */
final class DeploymentProbe
{
    /** @return array{ok:bool,checks:list<array{id:string,status:string,message:string}>} */
    public static function run(string $origin, mixed $driver = null): array
    {
        $parsed = parse_url($origin);
        if (!is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https' ||
            !is_string($parsed['host'] ?? null) || $parsed['host'] === '' ||
            isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) ||
            isset($parsed['fragment']) || !in_array($parsed['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('Production deployment probe requires a canonical HTTPS site origin');
        }
        $checks=[];
        $record = static function(string $id, bool $success, string $message) use (&$checks): void {
            $checks[]=['id'=>$id,'status'=>$success?'pass':'fail','message'=>$message];
        };
        // Request only known fixed routes on the configured origin. No redirects,
        // caller-controlled paths, authorization headers or user credentials.
        $cases=[
            '/health/live'=>200,
            '/admin'=>200,
            '/admin/oidc/login'=>405,
            '/admin/oidc/backchannel-logout'=>405,
            '/admin/recovery'=>404,
        ];
        foreach ($cases as $path=>$expectedStatus) {
            try {
                $response=self::request(rtrim($origin,'/').$path,$driver);
                $status=$response['status'];
                $record('http.'.$path, $status===$expectedStatus,
                    'Expected HTTP '.$expectedStatus.' for '.$path.'; received '.$status);
                if ($path==='/health/live') {
                    $data=json_decode($response['body'],true);
                    $record('http.health_body', $status===200 && is_array($data) && ($data['status']??null)==='ok',
                        'Live application must return a valid JSON health response');
                }
                if ($path==='/admin') {
                    $headers=$response['headers'];
                    $hsts=$headers['strict-transport-security']??[];
                    $cache=$headers['cache-control']??[];
                    $cookies=$headers['set-cookie']??[];
                    $csp=$headers['content-security-policy']??[];
                    $record('http.hsts',self::contains($hsts,'max-age='),'Administrative response must have HSTS');
                    $record('http.no_store',self::contains($cache,'no-store'),'Administrative response must prohibit caching');
                    $record('http.csp',self::contains($csp,"script-src 'none'") && self::contains($csp,"frame-ancestors 'none'"),
                        'Admin must disable scripts and embedding');
                    $secureCookie=false;
                    foreach ($cookies as $cookie) {
                        if (preg_match('/^CajeerLite=[^;]*;/i',$cookie) &&
                            preg_match('/(?:^|;)\s*Secure(?:;|$)/i',$cookie) &&
                            preg_match('/(?:^|;)\s*HttpOnly(?:;|$)/i',$cookie) &&
                            preg_match('/(?:^|;)\s*SameSite=Lax(?:;|$)/i',$cookie)) {
                            $secureCookie=true; break;
                        }
                    }
                    $record('http.session_cookie',$secureCookie,'Admin session cookie must be Secure, HttpOnly and SameSite=Lax');
                }
            } catch (\Throwable $e) {
                $record('http.'.$path,false,'HTTPS probe failed for '.$path.' (see server connectivity/TLS)');
            }
        }
        return ['ok'=>!in_array('fail',array_column($checks,'status'),true),'checks'=>$checks];
    }

    private static function contains(array $values, string $needle): bool
    {
        foreach ($values as $value) if (stripos($value,$needle)!==false) return true;
        return false;
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    private static function request(string $url, mixed $driver): array
    {
        if ($driver !== null) {
            [$status,$lines,$body]=$driver($url);
        } else {
            $ctx=stream_context_create([
                'http'=>['method'=>'GET','header'=>"Accept: application/json, text/html\r\nConnection: close",
                    'timeout'=>6,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,
                    'protocol_version'=>1.1],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
            ]);
            $handle=@fopen($url,'rb',false,$ctx);
            if (!is_resource($handle)) throw new \RuntimeException('HTTPS endpoint unavailable');
            try {
                $lines=stream_get_meta_data($handle)['wrapper_data']??[];
                $body=stream_get_contents($handle,8192);
            } finally { fclose($handle); }
            $status=0;
            foreach ($lines as $line) if (preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$m)) $status=(int)$m[1];
        }
        if (!is_int($status) || $status<100 || $status>599 || !is_array($lines) ||
            !is_string($body) || strlen($body)>8192) throw new \RuntimeException('Invalid HTTPS response');
        $headers=[];
        foreach ($lines as $line) {
            if (!is_string($line) || strlen($line)>8192 || !str_contains($line,':')) continue;
            [$name,$value]=explode(':',$line,2);
            $name=strtolower(trim($name));
            if (!preg_match('/\A[a-z0-9-]+\z/D',$name)) continue;
            $headers[$name][]=trim($value);
        }
        return compact('status','headers','body');
    }
}
