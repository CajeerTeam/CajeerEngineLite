<?php
declare(strict_types=1);
namespace CajeerEngine\Auth;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Durable, server-side revocation of administrative sessions (PHP cookie is not sufficient). */
final class SessionRegistry
{
    public const IDLE_SECONDS = 3600;
    public const MAX_SECONDS = 86400;
    private const MAX_BYTES = 262144;
    private const MAX_SESSIONS = 40;
    private const MAX_REPLAYS = 512;
    private const WRITE_INTERVAL = 45;

    public function __construct(private readonly string $root) {}

    private static function key(array $session): ?string
    {
        $secret = $session['cajeer_session_token'] ?? null;
        return is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? hash('sha256', hex2bin($secret)) : null;
    }

    public static function currentId(array $session): ?string { return self::key($session); }

    private function file(): string { return OperationFiles::path($this->root, 'storage/auth/sessions.json'); }
    /** Private PHP session backing files, independent of the system-wide session directory. */
    public function phpSessionDirectory(): string
    {
        $this->directory();
        $path=OperationFiles::path($this->root,'storage/auth/php');
        OperationFiles::dir($path,0700);
        $permissions=@fileperms($path);
        if ($permissions===false || (($permissions&0077)!==0 && !@chmod($path,0700))) {
            throw new \RuntimeException('Cannot secure PHP session directory');
        }
        clearstatcache(true,$path);
        if ((fileperms($path)&0077)!==0) throw new \RuntimeException('PHP session directory too broad');
        return $path;
    }


    /** Keep session registry private even when unpacked files have permissive modes. */
    private function directory(): void
    {
        $storage=OperationFiles::path($this->root, 'storage');
        OperationFiles::dir($storage, 0700);
        $permissions=@fileperms($storage);
        if ($permissions===false || (($permissions & 0077)!==0 && !@chmod($storage,0700))) {
            throw new \RuntimeException('Cannot secure session storage');
        }
        $dir=OperationFiles::path($this->root, 'storage/auth');
        OperationFiles::dir($dir, 0700);
        $permissions=@fileperms($dir);
        if ($permissions===false || (($permissions & 0077)!==0 && !@chmod($dir,0700))) {
            throw new \RuntimeException('Cannot secure session directory');
        }
        clearstatcache(true,$storage); clearstatcache(true,$dir);
        if ((fileperms($storage)&0077)!==0 || (fileperms($dir)&0077)!==0) {
            throw new \RuntimeException('Session directory permissions too broad');
        }
    }

    private function read(): array
    {
        $path=$this->file();
        clearstatcache(true,$path);
        $entry=@lstat($path);
        if ($entry===false) return ['items'=>[],'replays'=>[]];
        if (($entry['mode']&0170000)!==0100000 || $entry['nlink']!==1 || ($entry['mode']&0077)!==0) {
            throw new \RuntimeException('Unsafe session registry');
        }
        $h=@fopen($path,'rb');
        if (!is_resource($h)) throw new \RuntimeException('Cannot read session registry');
        try {
            $stat=fstat($h); clearstatcache(true,$path); $now=@lstat($path);
            if (!is_array($stat) || !is_array($now) || $stat['ino']!==$now['ino'] ||
                $stat['dev']!==$now['dev'] || ($stat['mode']&0170000)!==0100000 ||
                $stat['nlink']!==1 || ($now['mode']&0170000)!==0100000 || ($now['mode']&0077)!==0) {
                throw new \RuntimeException('Session registry changed during read');
            }
            $raw=stream_get_contents($h,self::MAX_BYTES+1);
            if (!is_string($raw) || strlen($raw)>self::MAX_BYTES) throw new \RuntimeException('Session registry too large');
            $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($data) || !in_array($data['version']??null,[1,2],true) || !isset($data['items']) || !is_array($data['items']) || count($data['items'])>self::MAX_SESSIONS) {
                throw new \RuntimeException('Invalid session registry format');
            }
            foreach ($data['items'] as $id=>$record) {
                if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id) || !is_array($record) ||
                    !is_string($record['generation']??null) || !is_string($record['proof']??null) ||
                    !in_array($record['method']??null,['local','oidc'],true) ||
                    !is_string($record['agent']??null) || !is_string($record['ip']??null) ||
                    !is_int($record['created']??null) || !is_int($record['seen']??null) ||
                    !is_int($record['expires']??null) ||
                    (isset($record['oidc_subject']) && (!is_string($record['oidc_subject']) || !preg_match('/\A[a-f0-9]{64}\z/D',$record['oidc_subject']))) ||
                    (isset($record['oidc_sid']) && (!is_string($record['oidc_sid']) || !preg_match('/\A[a-f0-9]{64}\z/D',$record['oidc_sid'])))) {
                    throw new \RuntimeException('Invalid session record');
                }
            }
            $replays=$data['version']===2?($data['replays']??null):[];
            if (!is_array($replays) || count($replays)>self::MAX_REPLAYS) throw new \RuntimeException('Invalid logout replay cache');
            foreach ($replays as $jti=>$expiry) if (!is_string($jti) || !preg_match('/\A[a-f0-9]{64}\z/D',$jti) || !is_int($expiry)) {
                throw new \RuntimeException('Invalid logout replay cache entry');
            }
            return ['items'=>$data['items'],'replays'=>$replays];
        } finally { fclose($h); }
    }

    private function write(array $items, array $replays): void
    {
        // No login secrets, bearer tokens or IdP JWTs are persisted in the registry.
        OperationFiles::write($this->file(),json_encode(['version'=>2,'items'=>$items,'replays'=>$replays],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),0600);
    }

    private function locked(callable $callback, array $site, ?int $clock=null): mixed
    {
        $this->directory();
        $lock=OperationLock::openLockFile(OperationFiles::path($this->root,'storage/auth/sessions.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock sessions');
            $data=$this->read(); $items=$data['items']; $old=$items;
            $replays=$data['replays']; $oldReplays=$replays; $time=$clock??time();
            foreach ($replays as $jti=>$expiry) if ($expiry <= $time) unset($replays[$jti]);
            foreach ($items as $id=>$record) {
                if (($record['method']==='oidc' && !isset($record['oidc_subject'])) ||
                    $record['expires']<=$time || $record['seen']+self::IDLE_SECONDS<=$time ||
                    !hash_equals($site['identity']['generation'],$record['generation']) ||
                    !hash_equals(hash('sha256',$site['password']),$record['proof'])) unset($items[$id]);
            }
            $result=$callback($items,$time,$replays);
            if ($items!==$old || $replays!==$oldReplays) $this->write($items,$replays);
            return $result;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** Create a new authenticated device lease after password/OIDC authentication. */
    public function issue(array &$session, array $site, array $server=[], ?int $clock=null): string
    {
        if (!AuthPolicy::sessionAllowed($site, $session)) {
            throw new \RuntimeException('Sign-in method is disabled by authentication policy');
        }
        $secret=bin2hex(random_bytes(32)); $id=hash('sha256',hex2bin($secret));
        $agent=$server['HTTP_USER_AGENT']??''; $agent=is_string($agent)?substr(preg_replace('/[^\x20-\x7e]/','',$agent),0,180):'';
        $ip=$server['REMOTE_ADDR']??'';
        $ip=is_string($ip) && filter_var($ip,FILTER_VALIDATE_IP)?$ip:'';
        $identity=$session['oidc_identity']??null;
        $method=is_array($identity) && OwnerIdentity::validSubject($identity)?'oidc':'local';
        $subject=$method==='oidc'?hash('sha256',$identity['iss']."\0".$identity['sub']):null;
        $providerSid=$method==='oidc' && is_string($session['oidc_sid']??null)?$session['oidc_sid']:null;
        $sid=$providerSid!==null?hash('sha256',OidcClient::ISSUER."\0".$providerSid):null;
        $this->locked(static function(array &$items,int $time)use($id,$agent,$ip,$method,$site,$subject,$sid): void {
            if (count($items)>=self::MAX_SESSIONS) {
                uasort($items,static fn(array $a,array $b): int => $a['seen'] <=> $b['seen']);
                array_shift($items);
            }
            $items[$id]=['generation'=>$site['identity']['generation'],
                'proof'=>hash('sha256',$site['password']),'method'=>$method,
                'agent'=>$agent,'ip'=>$ip,'created'=>$time,'seen'=>$time,'expires'=>$time+self::MAX_SECONDS]
                + ($subject!==null?['oidc_subject'=>$subject]:[])
                + ($sid!==null?['oidc_sid'=>$sid]:[]);
        },$site,$clock);
        $session['cajeer_session_token']=$secret;
        return $id;
    }

    /** Revocations are enforced on the very next protected HTTP request. */
    public function valid(array $session, array $site, ?int $clock=null): bool
    {
        if (!AuthPolicy::sessionAllowed($site, $session)) return false;
        $id=self::key($session);
        if ($id===null) return false; // Legacy PHP-only sessions must reauthenticate.
        return $this->locked(static function(array &$items,int $time)use($id): bool {
            if (!isset($items[$id])) return false;
            if ($items[$id]['seen']+self::WRITE_INTERVAL<=$time) $items[$id]['seen']=$time;
            return true;
        },$site,$clock);
    }

    /** @return list<array{id:string,method:string,agent:string,ip:string,created:int,seen:int,expires:int,current:bool}> */
    public function devices(array $session,array $site,?int $clock=null): array
    {
        $current=self::key($session);
        if ($current===null) return [];
        return $this->locked(static function(array &$items,int $time)use($current): array {
            if (!isset($items[$current])) return [];
            $out=[];
            foreach ($items as $id=>$row) {
                $out[]=['id'=>$id,'method'=>$row['method'],'agent'=>$row['agent'],
                    'ip'=>$row['ip'],'created'=>$row['created'],'seen'=>$row['seen'],
                    'expires'=>$row['expires'],'current'=>$id===$current];
            }
            usort($out,static fn(array $a,array $b): int => $b['seen']<=>$a['seen']);
            return $out;
        },$site,$clock);
    }

    /** Process verified logout exactly once; revoke only matching OIDC leases. */
    public function backchannelLogout(array $claims, array $site, ?int $clock=null): int
    {
        if (($claims['iss']??null)!==OidcClient::ISSUER || !is_string($claims['jti']??null) ||
            !is_int($claims['exp']??null) ||
            (!is_string($claims['sub']??null) && !is_string($claims['sid']??null))) {
            throw new \InvalidArgumentException('Unverified logout claims');
        }
        $jti=hash('sha256',$claims['iss']."\0".$claims['jti']);
        $subject=isset($claims['sub'])?hash('sha256',$claims['iss']."\0".$claims['sub']):null;
        $sid=isset($claims['sid'])?hash('sha256',$claims['iss']."\0".$claims['sid']):null;
        return $this->locked(static function(array &$items,int $time,array &$replays)use($jti,$subject,$sid,$claims): int {
            if (isset($replays[$jti])) return 0; // retries are idempotent
            if (count($replays)>=self::MAX_REPLAYS) {
                asort($replays,SORT_NUMERIC);
                array_shift($replays);
            }
            $count=0;
            foreach ($items as $id=>$row) {
                if ($row['method']!=='oidc') continue;
                if ($sid!==null && ($row['oidc_sid']??null)!==$sid) continue;
                if ($subject!==null && ($row['oidc_subject']??null)!==$subject) continue;
                unset($items[$id]); ++$count;
            }
            $replays[$jti]=min((int)$claims['exp'],$time+600);
            return $count;
        },$site,$clock);
    }

    /** Called from verified backup restore while holding the site writer lock. */
    public function invalidateAll(array $site): void
    {
        $this->locked(static function(array &$items,int $time): void { $items=[]; },$site);
    }

    /** @return bool Whether the current device was revoked. */
    public function revoke(array $session,array $site,string $scope,?string $target=null,?int $clock=null): bool
    {
        if (!in_array($scope,['one','others','all'],true)) throw new \InvalidArgumentException('Invalid revocation scope');
        if ($scope==='one' && ($target===null || preg_match('/\A[a-f0-9]{64}\z/D',$target)!==1)) {
            throw new \InvalidArgumentException('Invalid session identifier');
        }
        $current=self::key($session);
        if ($current===null) throw new \RuntimeException('Session is no longer authorized');
        return $this->locked(static function(array &$items,int $time)use($scope,$target,$current): bool {
            if (!isset($items[$current])) throw new \RuntimeException('Session is no longer authorized');
            if ($scope==='all') { $items=[]; return true; }
            if ($scope==='others') { $items=[$current=>$items[$current]]; return false; }
            if (!isset($items[$target])) throw new \InvalidArgumentException('Session not found');
            unset($items[$target]);
            return $target===$current;
        },$site,$clock);
    }
}
