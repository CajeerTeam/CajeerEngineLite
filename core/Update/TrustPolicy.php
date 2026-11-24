<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Operator-provisioned trust, independent of GitHub and CMS-writable state. */
final class TrustPolicy
{
    private function __construct(private readonly array $keys, private readonly array $revoked,
        private readonly string $minimum, private readonly bool $managed) {}

    public static function configured(): self
    {
        $path=getenv('CAJEER_UPDATE_TRUST_POLICY_FILE');
        if(is_string($path)&&$path!=='') return self::load($path);
        $legacy=getenv('CAJEER_UPDATE_TRUSTED_KEY_FILE');
        if(!is_string($legacy)||$legacy==='') throw new \RuntimeException('Configure update trust policy or trusted public key');
        return self::single($legacy);
    }

    public static function managedConfigured(): bool
    {
        $path=getenv('CAJEER_UPDATE_TRUST_POLICY_FILE');
        return is_string($path)&&$path!=='';
    }

    /** Legacy explicit verification for existing releases and offline build hosts. */
    public static function single(string $path): self
    {
        if(!str_starts_with($path,'/')) throw new \RuntimeException('Absolute trusted key path required');
        clearstatcache(true,$path);$st=@lstat($path);
        if(!is_array($st)||($st['mode']&0022)!==0) throw new \RuntimeException('Writable trusted key');
        $key=self::key(UpdatePackage::readRegular($path,16384));
        return new self([$key['fingerprint']=>$key['key']],[],'0.0.0',false);
    }

    /** The policy and all parent directories must be root controlled. A root-owned
     * sticky directory (e.g. /tmp in tests) may contain root-owned descendants. */
    public static function load(string $path): self
    {
        if(!str_starts_with($path,'/')||strlen($path)>4096||str_contains($path,"\0")||realpath($path)!==$path) throw new \RuntimeException('Canonical absolute trust policy path required');
        $parts=explode('/',substr($path,1));$current='';
        foreach($parts as $i=>$part) {
            if($part===''||$part==='.'||$part==='..')throw new \RuntimeException('Unsafe trust policy path');
            $current.='/'.$part;clearstatcache(true,$current);$st=@lstat($current);$file=$i===count($parts)-1;
            if(!is_array($st)||$st['uid']!==0||($st['mode']&0170000)!==($file?0100000:0040000)||
                (($st['mode']&0022)!==0&&($file||($st['mode']&01000)===0))||($file&&$st['nlink']!==1)) throw new \RuntimeException('Trust policy must be root-owned and protected against writes');
        }
        $raw=UpdatePackage::readRegular($path,65536);
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data)||count($data)!==4||($data['format']??null)!==1||!DistributionIndex::version($data['minimum_target']??null)||
            !is_array($data['keys']??null)||!array_is_list($data['keys'])||count($data['keys'])<1||count($data['keys'])>8||
            !is_array($data['revoked']??null)||!array_is_list($data['revoked'])||count($data['revoked'])>128)throw new \RuntimeException('Invalid update trust policy');
        $revoked=[];$keys=[];
        foreach($data['revoked'] as $fingerprint) {
            if(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||isset($revoked[$fingerprint]))throw new \RuntimeException('Invalid revoked key fingerprint');
            $revoked[$fingerprint]=true;
        }
        foreach($data['keys'] as $pem) {
            if(!is_string($pem)||strlen($pem)>16384)throw new \RuntimeException('Invalid trusted public key');
            $key=self::key($pem);$id=$key['fingerprint'];
            if(isset($keys[$id]))throw new \RuntimeException('Duplicate trusted key');
            $keys[$id]=$key['key'];
        }
        $keys=array_diff_key($keys,$revoked);
        if(!$keys)throw new \RuntimeException('No active update signing keys');
        return new self($keys,array_keys($revoked),$data['minimum_target'],true);
    }

    private static function key(string $pem): array
    {
        if(!preg_match('~\A\s*-----BEGIN PUBLIC KEY-----[\r\n]+[A-Za-z0-9+/=\r\n]+-----END PUBLIC KEY-----\s*\z~D',$pem))throw new \RuntimeException('PEM public key required');
        $key=openssl_pkey_get_public($pem);$details=$key instanceof \OpenSSLAsymmetricKey?openssl_pkey_get_details($key):false;
        if(!is_array($details)||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048||$details['bits']>8192)throw new \RuntimeException('Trusted RSA public key (2048–8192 bits) required');
        return ['key'=>$key,'fingerprint'=>hash('sha256',$details['key'])];
    }

    public function verify(string $payload,string $signature): string
    {
        if(strlen($signature)<256||strlen($signature)>1024)throw new \RuntimeException('Invalid RSA signature size');
        foreach($this->keys as $fingerprint=>$key) {
            if(openssl_verify($payload,$signature,$key,OPENSSL_ALGO_SHA256)===1)return $fingerprint;
        }
        throw new \RuntimeException('Untrusted or invalid release signature');
    }
    public function permitsTarget(string $version): bool
    {return DistributionIndex::version($version)&&version_compare($version,$this->minimum,'>=');}
    public function assertTarget(string $version): void
    {if(!$this->permitsTarget($version))throw new \RuntimeException('Update target below trusted security floor');}
    public function report(): array
    {return ['mode'=>$this->managed?'managed':'legacy','minimum_target'=>$this->minimum,'active_fingerprints'=>array_keys($this->keys),'revoked_fingerprints'=>$this->revoked];}

    /** Fixed fields only, no package paths, URL, key material or user data. */
    public static function audit(string $event,array $fields=[]): void
    {
        $record=['component'=>'update-security','event'=>$event,'at'=>gmdate('c')];
        foreach(['from','to','id','key_sha256','package_sha256'] as $field)if(isset($fields[$field])&&is_string($fields[$field])&&preg_match('/^[a-f0-9.]{1,64}$/D',$fields[$field]))$record[$field]=$fields[$field];
        error_log(json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
}
