<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

/** Server-side SkiF4er ID Authorization Code + PKCE client. No tokens leave PHP. */
final class OidcClient
{
    public const ISSUER = 'https://id.skif4er.ru/realms/skif4er';
    private const WELL_KNOWN = self::ISSUER.'/.well-known/openid-configuration';
    private const AUTH = self::ISSUER.'/protocol/openid-connect/auth';
    private const TOKEN = self::ISSUER.'/protocol/openid-connect/token';
    private const JWKS = self::ISSUER.'/protocol/openid-connect/certs';
    private const LOGOUT = self::ISSUER.'/protocol/openid-connect/logout';
    private readonly string $redirectUri;

    /** Injectable HTTP driver strictly for isolated integration tests. */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        string $siteOrigin,
        private readonly mixed $driver = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9._-]{2,128}$/D', $clientId) ||
            strlen($clientSecret) < 12 || strlen($clientSecret) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $clientSecret)) {
            throw new \RuntimeException('Invalid SkiF4er OIDC client credentials');
        }
        $parts = parse_url($siteOrigin);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('SkiF4er ID requires a configured HTTPS site origin');
        }
        $this->redirectUri = rtrim($siteOrigin, '/').'/admin/oidc/callback';
    }

    public static function enabled(string $origin): bool
    {
        return str_starts_with($origin, 'https://') &&
            is_string(getenv('CAJEER_OIDC_CLIENT_ID')) && getenv('CAJEER_OIDC_CLIENT_ID') !== '' &&
            is_string(getenv('CAJEER_OIDC_CLIENT_SECRET')) && getenv('CAJEER_OIDC_CLIENT_SECRET') !== '';
    }

    public static function fromEnvironment(string $origin): self
    {
        return new self((string)getenv('CAJEER_OIDC_CLIENT_ID'), (string)getenv('CAJEER_OIDC_CLIENT_SECRET'), $origin);
    }

    public function redirectUri(): string { return $this->redirectUri; }

    /** @return array{state:string,nonce:string,verifier:string,created:int,mode:string,url:string} */
    public function begin(string $mode): array
    {
        if (!in_array($mode, ['login', 'bind', 'replace_current', 'replace_new', 'unbind'], true)) throw new \InvalidArgumentException('Invalid OIDC flow mode');
        $this->discovery(); // fail closed if provider metadata is unavailable or unexpected
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random();
        $url = self::AUTH.'?'.http_build_query([
            'client_id' => $this->clientId, 'response_type' => 'code',
            'scope' => 'openid profile email', 'redirect_uri' => $this->redirectUri,
            'state' => $state, 'nonce' => $nonce, 'code_challenge_method' => 'S256',
            'code_challenge' => self::b64(hash('sha256', $verifier, true)),
            // Require a fresh interactive identity check for owner mutations.
            ...($mode === 'login' ? [] : ['prompt' => 'login', 'max_age' => '0']),
        ], '', '&', PHP_QUERY_RFC3986);
        return compact('state', 'nonce', 'verifier', 'mode', 'url') + ['created' => time()];
    }

    /** @return array{iss:string,sub:string} */
    public function complete(array $transaction, array $query): array
    {
        if (!isset($transaction['state'], $transaction['nonce'], $transaction['verifier'], $transaction['created']) ||
            !is_string($transaction['state']) || !is_string($transaction['nonce']) ||
            !is_string($transaction['verifier']) || !is_int($transaction['created']) ||
            $transaction['created'] > time()+30 || time()-$transaction['created'] > 300) {
            throw new \RuntimeException('OIDC transaction expired');
        }
        $state = $query['state'] ?? null;
        $code = $query['code'] ?? null;
        if (!is_string($state) || !hash_equals($transaction['state'], $state) ||
            !is_string($code) || strlen($code) < 5 || strlen($code) > 2048 ||
            preg_match('/[^\x21-\x7e]/', $code) || isset($query['error'])) {
            throw new \RuntimeException('Invalid OIDC callback');
        }
        $this->discovery();
        $response = $this->request(self::TOKEN, 'POST', [
            'Authorization: Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => $this->redirectUri, 'code_verifier' => $transaction['verifier'],
        ], '', '&', PHP_QUERY_RFC3986));
        $idToken = $response['id_token'] ?? null;
        if (!is_string($idToken) || strlen($idToken) > 16000 || strlen($idToken) < 100 ||
            !is_string($response['token_type'] ?? null) || strcasecmp($response['token_type'], 'Bearer') !== 0) throw new \RuntimeException('Invalid OIDC token response');
        $claims = $this->verifyIdToken($idToken, $transaction['nonce'], $response['access_token'] ?? null);
        if (($transaction['mode'] ?? 'login') !== 'login' &&
            (!is_int($claims['auth_time'] ?? null) || $claims['auth_time'] > time()+60 ||
                $claims['auth_time'] < time()-300)) {
            throw new \RuntimeException('Fresh SkiF4er ID authentication is required');
        }
        return ['iss' => $claims['iss'], 'sub' => $claims['sub']] + (isset($claims['sid']) ? ['sid' => $claims['sid']] : []);
    }

    /** @return array<string,mixed> */
    public function verifyIdToken(string $jwt, string $nonce, mixed $accessToken = null): array
    {
        $claims = $this->signedClaims($jwt, false);
        $now = time();
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : $aud;
        if (($claims['iss'] ?? null) !== self::ISSUER || !is_array($audiences) ||
            !in_array($this->clientId, $audiences, true) ||
            (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId) ||
            (isset($claims['azp']) && $claims['azp'] !== $this->clientId) ||
            !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 60 ||
            !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 7200 ||
            (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60)) ||
            !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce']) ||
            !is_string($claims['sub'] ?? null) || strlen($claims['sub']) < 1 || strlen($claims['sub']) > 255 ||
            preg_match('/[\x00-\x1f\x7f]/', $claims['sub']) ||
            (isset($claims['sid']) && (!is_string($claims['sid']) || strlen($claims['sid'])<1 || strlen($claims['sid'])>255 || preg_match('/[\x00-\x1f\x7f]/', $claims['sid'])))) {
            throw new \RuntimeException('Invalid ID token claims');
        }
        if (array_key_exists('at_hash', $claims)) {
            if (!is_string($accessToken) || strlen($accessToken) > 16000 ||
                !is_string($claims['at_hash']) ||
                !hash_equals(self::b64(substr(hash('sha256', $accessToken, true), 0, 16)), $claims['at_hash'])) {
                throw new \RuntimeException('Invalid access token binding');
            }
        }
        return $claims;
    }

    /** Verify only allowlisted RS256/JWKS signatures. Never trust token header key URLs. */
    private function signedClaims(string $jwt, bool $logout): array
    {
        if (strlen($jwt) > 16000 || substr_count($jwt, '.') !== 2) throw new \RuntimeException('Invalid ID token format');
        [$encodedHeader, $encodedPayload, $signature] = explode('.', $jwt, 3);
        try {
            $header = self::object(json_decode(self::decode($encodedHeader), true, 32, JSON_THROW_ON_ERROR));
            $claims = self::object(json_decode(self::decode($encodedPayload), true, 32, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid ID token JSON');
        }
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) ||
            strlen($header['kid']) < 1 || strlen($header['kid']) > 128 ||
            (isset($header['typ']) && !in_array($header['typ'], $logout ? ['JWT','application/jwt','logout+jwt'] : ['JWT','application/jwt'], true))) {
            throw new \RuntimeException('Untrusted ID token algorithm');
        }
        $jwks = $this->request(self::JWKS);
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || count($keys) > 40) throw new \RuntimeException('Invalid OIDC JWKS');
        $key = null;
        foreach ($keys as $candidate) {
            if (!is_array($candidate) || ($candidate['kid'] ?? null) !== $header['kid']) continue;
            if ($key !== null) throw new \RuntimeException('Ambiguous OIDC signing key');
            if (($candidate['kty'] ?? null) !== 'RSA' ||
                (isset($candidate['use']) && $candidate['use'] !== 'sig') ||
                (isset($candidate['alg']) && $candidate['alg'] !== 'RS256')) throw new \RuntimeException('Invalid OIDC signing key');
            $key = $candidate;
        }
        if ($key === null) throw new \RuntimeException('OIDC signing key not found');
        $publicKey = self::rsaPublicKey($key);
        $verified = openssl_verify($encodedHeader.'.'.$encodedPayload, self::decode($signature), $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) throw new \RuntimeException('Invalid ID token signature');
        return $claims;
    }

    /** Validate an OIDC Back-Channel Logout Token (not an authentication ID Token). */
    public function verifyLogoutToken(string $jwt): array
    {
        $claims=$this->signedClaims($jwt,true);
        $now=time();
        $aud=$claims['aud']??null;
        $audiences=is_string($aud)?[$aud]:$aud;
        $sub=$claims['sub']??null;
        $sid=$claims['sid']??null;
        // A Logout Token event must be a JSON *object*, not an array or scalar.
        // Check raw JSON shape after its RS256 signature has been verified.
        $parts=explode('.',$jwt,3);
        try {
            $payloadObject=json_decode(self::decode($parts[1]),false,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) { throw new \RuntimeException('Invalid logout event JSON'); }
        $events=is_object($payloadObject)?($payloadObject->events??null):null;
        $event=$events instanceof \stdClass ? ($events->{'http://schemas.openid.net/event/backchannel-logout'}??null) : null;
        if (($claims['iss']??null)!==self::ISSUER || !is_array($audiences) ||
            !in_array($this->clientId,$audiences,true) ||
            (count($audiences)>1 && ($claims['azp']??null)!==$this->clientId) ||
            (isset($claims['azp']) && $claims['azp']!==$this->clientId) ||
            !is_int($claims['iat']??null) || $claims['iat']>$now+60 || $claims['iat']<$now-300 ||
            !is_int($claims['exp']??null) || $claims['exp']<=$now || $claims['exp']>$now+3600 ||
            !is_string($claims['jti']??null) || strlen($claims['jti'])<8 || strlen($claims['jti'])>255 ||
            !preg_match('/\A[\x21-\x7e]+\z/D',$claims['jti']) ||
            array_key_exists('nonce',$claims) || !($event instanceof \stdClass) ||
            ($sub===null && $sid===null) ||
            ($sub!==null && (!is_string($sub)||strlen($sub)<1||strlen($sub)>255||preg_match('/[\x00-\x1f\x7f]/',$sub))) ||
            ($sid!==null && (!is_string($sid)||strlen($sid)<1||strlen($sid)>255||preg_match('/[\x00-\x1f\x7f]/',$sid)))) {
            throw new \RuntimeException('Invalid OIDC Logout Token claims');
        }
        return $claims;
    }

    /** Provider-controlled logout destination, never a user-supplied URL. */
    public function globalLogoutUrl(string $siteOrigin): string
    {
        $metadata=$this->discovery();
        if (($metadata['end_session_endpoint']??null)!==self::LOGOUT) {
            throw new \RuntimeException('Unexpected OIDC logout endpoint');
        }
        $origin=rtrim($siteOrigin,'/');
        if ($origin.'/admin/oidc/callback'!==$this->redirectUri) {
            throw new \RuntimeException('Untrusted post-logout origin');
        }
        return self::LOGOUT.'?'.http_build_query([
            'client_id'=>$this->clientId,
            'post_logout_redirect_uri'=>$origin.'/admin/oidc/logged-out',
        ],'', '&',PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private function discovery(): array
    {
        $doc = $this->request(self::WELL_KNOWN);
        if (($doc['issuer'] ?? null) !== self::ISSUER ||
            ($doc['authorization_endpoint'] ?? null) !== self::AUTH ||
            ($doc['token_endpoint'] ?? null) !== self::TOKEN ||
            ($doc['jwks_uri'] ?? null) !== self::JWKS ||
            (isset($doc['code_challenge_methods_supported']) &&
                (!is_array($doc['code_challenge_methods_supported']) ||
                    !in_array('S256', $doc['code_challenge_methods_supported'], true)))) {
            throw new \RuntimeException('Unexpected SkiF4er OIDC metadata');
        }
        return $doc;
    }

    /** Exact allowlist prevents SSRF through discovery fields and redirects. */
    private function request(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
    {
        if (!in_array($url, [self::WELL_KNOWN, self::TOKEN, self::JWKS], true) ||
            !in_array($method, ['GET', 'POST'], true) || strlen($body) > 8000) {
            throw new \RuntimeException('Unsafe OIDC request');
        }
        if ($this->driver !== null) {
            [$status, $raw] = ($this->driver)($url, $method, $headers, $body);
        } else {
            $ctx = stream_context_create([
                'http' => ['method' => $method, 'header' => implode("\r\n", array_merge(['Accept: application/json', 'Connection: close'], $headers)),
                    'content' => $body, 'timeout' => 6, 'follow_location' => 0, 'max_redirects' => 0,
                    'ignore_errors' => true, 'protocol_version' => 1.1],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
            ]);
            $stream = @fopen($url, 'rb', false, $ctx);
            if (!is_resource($stream)) throw new \RuntimeException('SkiF4er ID temporarily unavailable');
            try {
                $headersIn = stream_get_meta_data($stream)['wrapper_data'] ?? [];
                $status = 0;
                foreach ($headersIn as $line) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
                $raw = stream_get_contents($stream, 262145);
            } finally { fclose($stream); }
        }
        if (!is_int($status) || $status < 200 || $status >= 300 || !is_string($raw) || strlen($raw) > 262144) {
            throw new \RuntimeException('Invalid SkiF4er ID response');
        }
        try { $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Invalid SkiF4er ID JSON'); }
        return self::object($result);
    }

    private static function random(): string { return self::b64(random_bytes(32)); }
    private static function b64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
    private static function decode(string $encoded): string
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) || strlen($encoded) > 22000) {
            throw new \RuntimeException('Invalid base64url encoding');
        }
        $value = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($value) || !hash_equals(self::b64($value), $encoded)) throw new \RuntimeException('Invalid base64url payload');
        return $value;
    }
    private static function object(mixed $v): array
    {
        if (!is_array($v) || array_is_list($v)) throw new \RuntimeException('OIDC JSON must be an object');
        return $v;
    }
    private static function derLen(int $length): string
    {
        if ($length < 128) return chr($length);
        $raw = ltrim(pack('N', $length), "\0");
        return chr(0x80 | strlen($raw)).$raw;
    }
    private static function der(string $type, string $body): string { return $type.self::derLen(strlen($body)).$body; }
    private static function rsaPublicKey(array $jwk): \OpenSSLAsymmetricKey
    {
        $n = self::decode(is_string($jwk['n'] ?? null) ? $jwk['n'] : '');
        $e = self::decode(is_string($jwk['e'] ?? null) ? $jwk['e'] : '');
        if (strlen($n) < 256 || strlen($n) > 512 || strlen($e) < 1 || strlen($e) > 8) {
            throw new \RuntimeException('Invalid RSA JWK size');
        }
        $integer = static function(string $v): string {
            $v = ltrim($v, "\0");
            return self::der("\x02", $v !== '' && (ord($v[0]) & 0x80) ? "\0".$v : $v);
        };
        $rsa = self::der("\x30", $integer($n).$integer($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = self::der("\x30", $algorithm.self::der("\x03", "\0".$rsa));
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64)."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key instanceof \OpenSSLAsymmetricKey) throw new \RuntimeException('Invalid RSA public key');
        return $key;
    }
}
