<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;

/** Service account OAuth 2.0 JWT bearer grant, no external PHP libraries required. */
final class GoogleAuth
{
    private ?string $cached = null;
    public function __construct(private readonly HttpClient $http, private readonly string $root) {}

    public function token(): string
    {
        if ($this->cached!==null) return $this->cached;
        $direct=getenv('CAJEER_REPORT_GOOGLE_TOKEN');
        if (is_string($direct) && $direct!=='') return $this->cached=self::safeToken($direct);
        $path=getenv('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
        if (!is_string($path) || $path==='') throw new \RuntimeException('Google service account not configured');
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('Invalid Google credential file');
        $real=realpath($path);
        $public=realpath($this->root.'/public');
        if ($real===false || ($public!==false && str_starts_with($real,$public.'/')) ||
            ((fileperms($real)&0077)!==0)) throw new \RuntimeException('Google credentials must be private and outside public/');
        $data=json_decode((string)file_get_contents($real),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['type']??'')!=='service_account' ||
            !filter_var($data['client_email']??'',FILTER_VALIDATE_EMAIL) ||
            !is_string($data['private_key']??null)) throw new \RuntimeException('Invalid Google service account');
        $now=time();
        $jwtHeader=self::b64(json_encode(['alg'=>'RS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));
        $claims=self::b64(json_encode(['iss'=>$data['client_email'],
            'scope'=>'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600],JSON_THROW_ON_ERROR));
        $signed=$jwtHeader.'.'.$claims;
        if (!openssl_sign($signed,$sig,$data['private_key'],OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Google JWT signing failed');
        $grant=$signed.'.'.self::b64($sig);
        // The OAuth token endpoint expects form encoding, not JSON.
        $result=$this->http->form('https://oauth2.googleapis.com/token',
            ['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$grant]);
        return $this->cached=self::safeToken($result['access_token']??'');
    }

    private static function b64(string $str): string { return rtrim(strtr(base64_encode($str),'+/','-_'),'='); }
    public static function safeToken(mixed $token): string
    {
        if (!is_string($token) || strlen($token)<8 || strlen($token)>4096 || !preg_match('/^[\x21-\x7E]+$/D',$token)) {
            throw new \RuntimeException('API token missing or invalid');
        }
        return $token;
    }
}
