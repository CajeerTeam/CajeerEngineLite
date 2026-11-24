<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Versioned, RSA-SHA256 signed, length-delimited release stream. No archiver or shell needed. */
final class UpdatePackage
{
    private const MAGIC = "CAJEER-UPDATE-1\n";
    public const MAX_BYTES = 96_000_000;
    public const MAX_FILE = 12_000_000;
    public const MAX_FILES = 512;

    public static function permitted(string $path): bool
    {
        if (strlen($path)>180 || !preg_match('~^[A-Za-z0-9._/-]+$~D',$path) ||
            preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~',$path)) return false;
        if (in_array($path,['VERSION','bootstrap.php','bin/cajeer','.gitignore'],true)) return true;
        if (preg_match('~^(?:core/[A-Za-z0-9_/-]+\.php|templates/[A-Za-z0-9_/-]+\.cjr)$~D',$path)) return true;
        if (preg_match('~^public/(?:index\.php|assets/[A-Za-z0-9_/-]+\.(?:css|js|svg|png|ico|woff2))$~D',$path)) return true;
        if (preg_match('~^tests/[A-Za-z0-9_/-]+\.(?:php|sh|py|json)$~D',$path)) return true;
        if (preg_match('~^deploy/[A-Za-z0-9_.-]+\.(?:conf|md|service|sh)$~D',$path)) return true;
        if (preg_match('~^[A-Z][A-Z0-9_]*\.md$~D',$path)) return true;
        return $path==='.github/workflows/ci.yml';
    }

    public static function version(string $root): string
    {
        $file=OperationFiles::path($root,'VERSION');
        $value=trim(self::readRegular($file,128));
        if (!DistributionIndex::version($value)) throw new \RuntimeException('Invalid installed VERSION');
        return $value;
    }

    public static function readRegular(string $path,int $max): string
    {
        clearstatcache(true,$path);$entry=@lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000)!==0100000 || $entry['nlink']!==1 || $entry['size']>$max) throw new \RuntimeException('Unsafe release input file');
        $h=@fopen($path,'rb');
        if (!is_resource($h)) throw new \RuntimeException('Cannot open release file');
        try {
            $stat=fstat($h);clearstatcache(true,$path);$now=@lstat($path);
            if (!is_array($stat)||!is_array($now)||$stat['ino']!==$entry['ino']||$stat['dev']!==$entry['dev']||
                $now['ino']!==$entry['ino']||$now['dev']!==$entry['dev']||$stat['size']!==$entry['size']) throw new \RuntimeException('Release file changed');
            $raw=stream_get_contents($h,$max+1);
            if (!is_string($raw)||strlen($raw)!==$entry['size']||strlen($raw)>$max) throw new \RuntimeException('Release file read failed');
            $after=fstat($h);clearstatcache(true,$path);$last=@lstat($path);
            if (!is_array($last)||$last['ino']!==$stat['ino']||$last['dev']!==$stat['dev']||$last['nlink']!==1||!is_array($after)||$after['mtime']!==$stat['mtime']||$after['ctime']!==$stat['ctime']||$after['size']!==$stat['size']) throw new \RuntimeException('Release file modified while reading');
            return $raw;
        } finally {fclose($h);}
    }

    /** Enumerate only supported source paths, rejecting suspicious filesystem entries. */
    private static function inventory(string $root, string $relative=''): array
    {
        $result=[];
        $dir=$relative===''?$root:OperationFiles::path($root,$relative);
        if (is_link($dir)||!is_dir($dir)) throw new \RuntimeException('Unsafe release source directory');
        foreach(new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $name=($relative===''?'':$relative.'/').$entry->getFilename();
            // Only explicitly managed source roots can enter the package.
            if ($relative==='' && !in_array($entry->getFilename(),
                ['core','templates','public','tests','deploy','.github','VERSION','bootstrap.php','bin','.gitignore'],true) &&
                !preg_match('/^[A-Z][A-Z0-9_]*\.md$/D',$entry->getFilename())) continue;
            if ($name==='.github' || $name==='.github/workflows' ||
                $name==='core'||$name==='templates'||$name==='public'||$name==='public/assets'||
                $name==='tests'||$name==='deploy'||$name==='bin'||preg_match('~^(?:core|templates|tests)/[A-Za-z0-9_/-]+$~D',$name)) {
                if ($entry->isLink()) throw new \RuntimeException('Symlink in release tree');
                if ($entry->isDir()) $result+=self::inventory($root,$name);
                elseif (self::permitted($name)) $result[$name]=$entry->getPathname();
                continue;
            }
            if ($entry->isDir()) continue; // No arbitrary trees in candidate release.
            if (self::permitted($name)) {
                if ($entry->isLink() || !$entry->isFile()) throw new \RuntimeException('Unsafe release member');
                $result[$name]=$entry->getPathname();
            }
        }
        ksort($result,SORT_STRING);
        return $result;
    }

    public static function build(string $base,string $candidate,string $output,string $privateKeyPath): array
    {
        $from=self::version($base); $to=self::version($candidate);
        if (version_compare($to,$from,'<=')) throw new \RuntimeException('Update target must be newer than installed version');
        $baseFiles=self::inventory($base);$nextFiles=self::inventory($candidate);
        $paths=array_unique(array_merge(array_keys($baseFiles),array_keys($nextFiles)));
        sort($paths,SORT_STRING); $files=[];
        foreach($paths as $path) {
            $old=isset($baseFiles[$path])?hash('sha256',self::readRegular($baseFiles[$path],self::MAX_FILE)):null;
            $next=isset($nextFiles[$path])?hash('sha256',self::readRegular($nextFiles[$path],self::MAX_FILE)):null;
            if ($old===$next) continue;
            $mode=$next===null ? 0 : ((fileperms($nextFiles[$path]) & 0111)!==0 ? 0755 : 0644);
            $files[]=['path'=>$path,'action'=>$next===null?'remove':'write','before'=>$old,'after'=>$next,
                'size'=>$next===null?0:filesize($nextFiles[$path]),'mode'=>$mode];
        }
        if (!$files || count($files)>self::MAX_FILES) throw new \RuntimeException('Invalid number of updated files');
        if (!in_array('VERSION',array_column($files,'path'),true) || !in_array('core/Lite.php',array_column($files,'path'),true)) {
            throw new \RuntimeException('Update must change VERSION and core/Lite.php');
        }
        $source=self::readRegular($nextFiles['core/Lite.php'],self::MAX_FILE);
        if (!preg_match("/public const VERSION\\s*=\\s*'".preg_quote($to,'/')."'\\s*;/",$source)) throw new \RuntimeException('Candidate application version mismatch');
        $manifest=['format'=>1,'product'=>'CajeerEngineLite','from'=>$from,'to'=>$to,
            'php_min'=>'8.4.1','schema_min'=>14,'schema_max'=>14,'files'=>$files];
        self::validate($manifest);
        $json=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>262144) throw new \RuntimeException('Release manifest too large');
        $keyStat=@lstat($privateKeyPath);
        if(!is_array($keyStat)||($keyStat['mode'] & 0077)!==0) throw new \RuntimeException('Signing private key must have restrictive filesystem permissions');
        $private=self::readRegular($privateKeyPath,16384);
        $key=openssl_pkey_get_private($private);
        if (!$key instanceof \OpenSSLAsymmetricKey || openssl_pkey_get_details($key)['type']!==OPENSSL_KEYTYPE_RSA || openssl_pkey_get_details($key)['bits']<2048) throw new \RuntimeException('RSA signing key (2048 bits+) required');
        if (!openssl_sign($json,$signature,$key,OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Cannot sign release manifest');
        if (file_exists($output)||is_link($output)) throw new \RuntimeException('Output release package already exists');
        $h=@fopen($output,'xb');
        if (!is_resource($h)) throw new \RuntimeException('Cannot create release package');
        $ok=false;
        try {
            chmod($output,0600);
            self::put($h,self::MAGIC.sprintf('%08x',strlen($json))."\n".$json.sprintf('%04x',strlen($signature))."\n".$signature);
            foreach($files as $file) if ($file['action']==='write') {
                $contents=self::readRegular($nextFiles[$file['path']],self::MAX_FILE);
                if (hash('sha256',$contents)!==$file['after']) throw new \RuntimeException('Source changed during package build');
                self::put($h,$contents);
            }
            if (!fflush($h)||!fsync($h)) throw new \RuntimeException('Cannot sync signed package');
            $ok=true;
        } finally { fclose($h); if(!$ok)@unlink($output); }
        return ['from'=>$from,'to'=>$to,'files'=>count($files),'package_sha256'=>OperationFiles::hash($output)];
    }

    private static function put(mixed $handle,string $bytes): void
    {
        $length=strlen($bytes);
        for($offset=0;$offset<$length;) {
            $count=@fwrite($handle,substr($bytes,$offset,min(65536,$length-$offset)));
            if (!is_int($count)||$count<=0) throw new \RuntimeException('Release write failed');
            $offset+=$count;
        }
    }
    private static function exact(mixed $handle,int $size,?\HashContext $digest=null): string
    {
        $result='';
        while (strlen($result)<$size) {
            $piece=fread($handle,min(65536,$size-strlen($result)));
            if (!is_string($piece)||$piece==='') throw new \RuntimeException('Truncated update package');
            $result.=$piece;
            if($digest!==null)hash_update($digest,$piece);
        }
        return $result;
    }
    private static function validHash(mixed $value): bool {return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1;}

    /** Validate signature and all bytes before returning any data for installation. */
    public static function verify(string $package,string|TrustPolicy $publicKeyPath,?string $stage=null): array
    {
        clearstatcache(true,$package);$st=@lstat($package);
        if(!is_array($st)||($st['mode']&0170000)!==0100000||$st['nlink']!==1||$st['size']>self::MAX_BYTES||$st['size']<60) throw new \RuntimeException('Unsafe update package');
        $policy=$publicKeyPath instanceof TrustPolicy?$publicKeyPath:TrustPolicy::single($publicKeyPath);
        $h=@fopen($package,'rb');
        if(!is_resource($h)) throw new \RuntimeException('Cannot read update package');
        try {
            $opened=fstat($h);clearstatcache(true,$package);$now=@lstat($package);
            $packageDigest=hash_init('sha256');
            if (!is_array($opened)||!is_array($now)||$opened['ino']!==$now['ino']||$opened['dev']!==$now['dev']||$opened['nlink']!==1||$opened['ino']!==$st['ino']||$opened['dev']!==$st['dev']||$opened['size']!==$st['size']||($opened['mode']&0170000)!==0100000) throw new \RuntimeException('Update package changed');
            if(self::exact($h,strlen(self::MAGIC),$packageDigest)!==self::MAGIC) throw new \RuntimeException('Not a Cajeer update package');
            $size=self::exact($h,9,$packageDigest);
            if(!preg_match('/^[0-9a-f]{8}\n$/D',$size)) throw new \RuntimeException('Invalid release header');
            $length=hexdec(substr($size,0,8));
            if($length<80||$length>262144) throw new \RuntimeException('Release manifest length invalid');
            $json=self::exact($h,$length,$packageDigest);
            $sigLength=self::exact($h,5,$packageDigest);
            if(!preg_match('/^[0-9a-f]{4}\n$/D',$sigLength)) throw new \RuntimeException('Invalid signature framing');
            $signatureBytes=hexdec(substr($sigLength,0,4));
            if($signatureBytes<256||$signatureBytes>1024)throw new \RuntimeException('Invalid RSA signature size');
            $signature=self::exact($h,$signatureBytes,$packageDigest);
            $fingerprint=$policy->verify($json,$signature);
            $manifest=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            self::validate($manifest);
            $policy->assertTarget($manifest['to']);
            $total=ftell($h);
            foreach($manifest['files'] as $member) {
                if($member['action']!=='write') continue;
                $size=$member['size']; $total+=$size;
                if($total>self::MAX_BYTES) throw new \RuntimeException('Package data exceeds limit');
                $dst=null;
                if($stage!==null) {
                    $dst=OperationFiles::path($stage,'files/'.$member['path']);
                    OperationFiles::dir(dirname($dst),0700);
                    $out=@fopen($dst,'xb');
                    if(!is_resource($out)) throw new \RuntimeException('Cannot stage update payload');
                    chmod($dst,0600);
                }
                $ctx=hash_init('sha256');$remaining=$size;
                try {
                    while($remaining>0) {
                        $chunk=self::exact($h,min($remaining,65536),$packageDigest);
                        hash_update($ctx,$chunk);
                        if($dst!==null)self::put($out,$chunk);
                        $remaining-=strlen($chunk);
                    }
                    if($dst!==null&&(!fflush($out)||!fsync($out))) throw new \RuntimeException('Cannot sync staged update file');
                } finally {if($dst!==null)fclose($out);}
                if(!hash_equals($member['after'],hash_final($ctx))) throw new \RuntimeException('Update member SHA-256 mismatch: '.$member['path']);
            }
            if(fread($h,1)!=='') throw new \RuntimeException('Unexpected trailing update data');
            $ended=fstat($h);
            if (!is_array($ended)||$ended['size']!==$opened['size']||$ended['mtime']!==$opened['mtime']||$ended['ctime']!==$opened['ctime']) throw new \RuntimeException('Package mutated during verification');
            return ['manifest'=>$manifest,'key_sha256'=>$fingerprint,'package_sha256'=>hash_final($packageDigest)];
        } finally {fclose($h);}
    }
    private static function validate(mixed $data): void
    {
        if(!is_array($data)||array_keys($data)!==['format','product','from','to','php_min','schema_min','schema_max','files']||
            $data['format']!==1||$data['product']!=='CajeerEngineLite'||!is_array($data['files'])||
            !DistributionIndex::version($data['from'])||
            !DistributionIndex::version($data['to'])||version_compare($data['from'],$data['to'],'>=')||
            $data['php_min']!=='8.4.1'||$data['schema_min']!==14||$data['schema_max']!==14||
            count($data['files'])<2||count($data['files'])>self::MAX_FILES||!array_is_list($data['files'])) throw new \RuntimeException('Unsupported update manifest');
        $prior='';$contains=[];
        foreach($data['files'] as $entry) {
            if(!is_array($entry)||array_keys($entry)!==['path','action','before','after','size','mode']||
                !is_string($entry['path'])||!self::permitted($entry['path'])||strcmp($prior,$entry['path'])>=0||
                !in_array($entry['action'],['write','remove'],true)||
                ($entry['before']!==null&&!self::validHash($entry['before']))) throw new \RuntimeException('Unsafe update path or operation');
            if($entry['action']==='remove' && in_array($entry['path'],['bootstrap.php','bin/cajeer','public/index.php'],true)) {
                throw new \RuntimeException('Cannot remove a required CMS entry point');
            }
            if($entry['action']==='write') {
                if(!self::validHash($entry['after'])||!is_int($entry['size'])||$entry['size']<0||$entry['size']>self::MAX_FILE||
                    !in_array($entry['mode'],[0644,0755],true)) throw new \RuntimeException('Invalid release file metadata');
            } elseif($entry['after']!==null||$entry['size']!==0||$entry['mode']!==0) throw new \RuntimeException('Invalid removal operation');
            $prior=$entry['path']; $contains[$prior]=$entry['action'];
        }
        if(($contains['VERSION']??null)!=='write'||($contains['core/Lite.php']??null)!=='write') throw new \RuntimeException('Update must include new version');
    }
}
