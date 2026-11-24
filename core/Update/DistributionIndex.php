<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** A signed, bounded inventory of exact-base packages. GitHub metadata is not a trust root. */
final class DistributionIndex
{
    public const NAME='update-index.json';
    public const MAX_BYTES=65536;
    public static function version(mixed $v): bool {
        return is_string($v)&&strlen($v)<=32&&preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$v)===1;
    }
    public static function verify(string $raw,string $target,string|TrustPolicy $keyPath): array {
        if(strlen($raw)>self::MAX_BYTES)throw new \RuntimeException('Distribution index exceeds limit');
        $envelope=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($envelope)||count($envelope)!==2||!is_string($envelope['payload']??null)||!is_string($envelope['signature']??null))throw new \RuntimeException('Invalid distribution envelope');
        $payload=base64_decode($envelope['payload'],true);$sig=base64_decode($envelope['signature'],true);
        if($payload===false||$sig===false||strlen($sig)>16384)throw new \RuntimeException('Invalid distribution encoding');
        $policy=$keyPath instanceof TrustPolicy?$keyPath:TrustPolicy::single($keyPath);
        try {
            $fingerprint=$policy->verify($payload,$sig);
            $policy->assertTarget($target);
            TrustPolicy::audit('distribution_verified',['to'=>$target,'key_sha256'=>$fingerprint]);
        } catch(\Throwable $e) {TrustPolicy::audit('distribution_rejected');throw $e;}
        $data=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($data)||($data['format']??null)!==1||($data['product']??null)!=='CajeerEngineLite'||($data['to']??null)!==$target||!self::version($target)||!is_array($data['packages']??null)||!array_is_list($data['packages'])||count($data['packages'])<1||count($data['packages'])>16)throw new \RuntimeException('Invalid signed distribution index');
        $seen=[];$names=[];
        foreach($data['packages'] as $p){
            if(!is_array($p)||!self::version($p['from']??null)||version_compare($p['from'],$target,'>=')||isset($seen[$p['from']])||!is_string($p['name']??null)||isset($names[$p['name']])||!in_array($p['name'],['CajeerEngineLite-'.$target.'.cjrupdate','CajeerEngineLite-'.$p['from'].'-to-'.$target.'.cjrupdate'],true)||!is_int($p['bytes']??null)||$p['bytes']<60||$p['bytes']>UpdatePackage::MAX_BYTES||!is_string($p['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['sha256']))throw new \RuntimeException('Invalid signed distribution package');
            $seen[$p['from']]=true;$names[$p['name']]=true;
        }
        return $data['packages'];
    }
    /** Shortest path to highest reachable target, deterministic tie-break; no unsigned edges. */
    public static function plan(string $installed,array $edges): array {
        $paths=[$installed=>[]];$queue=[$installed];
        usort($edges,static fn($a,$b)=>version_compare($b['version'],$a['version']));
        for($i=0;$i<count($queue);$i++){
            $from=$queue[$i];
            foreach($edges as $edge){
                if($edge['from']!==$from||isset($paths[$edge['version']]))continue;
                $paths[$edge['version']]=[...$paths[$from],$edge];$queue[]=$edge['version'];
            }
        }
        $best=$installed;foreach($queue as $v)if(version_compare($v,$best,'>'))$best=$v;
        return $paths[$best];
    }
    public function __construct(private readonly string $root,private readonly ReleaseHttp $http){}
    public function edges(array $release,string $repo): array {
        $key=TrustPolicy::configured();
        $tag=$release['tag_name'];$version=ltrim($tag,'v');
        $index=self::asset($release,$repo,self::NAME,self::MAX_BYTES);
        $dir=OperationFiles::path($this->root,'storage/app/updates/auto/downloads');OperationFiles::dir($dir,0700);
        $file=$dir.'/'.bin2hex(random_bytes(16)).'.index';
        try {
            $this->http->download($index['url'],$file,$index['bytes'],$index['sha256']);
            $raw=UpdatePackage::readRegular($file,self::MAX_BYTES);
            if(strlen($raw)!==$index['bytes']||($index['sha256']!==null&&!hash_equals($index['sha256'],hash('sha256',$raw))))throw new \RuntimeException('Distribution index download mismatch');
            $packages=self::verify($raw,$version,$key);
        } finally {if(is_file($file)&&!is_link($file))OperationFiles::remove($file);}
        $edges=[];
        foreach($packages as $p){
            $asset=self::asset($release,$repo,$p['name'],UpdatePackage::MAX_BYTES);
            if($asset['bytes']!==$p['bytes']||($asset['sha256']!==null&&!hash_equals($asset['sha256'],$p['sha256'])))throw new \RuntimeException('GitHub asset differs from signed distribution index');
            $edges[]=['from'=>$p['from'],'version'=>$version,'tag'=>$tag,'url'=>$asset['url'],'bytes'=>$p['bytes'],'sha256'=>$p['sha256'],'prerelease'=>$release['prerelease']];
        }
        return $edges;
    }
    private static function asset(array $release,string $repo,string $name,int $max): array {
        $matches=array_values(array_filter($release['assets'],static fn($a)=>is_array($a)&&($a['name']??null)===$name));
        if(count($matches)!==1)throw new \RuntimeException('Missing or ambiguous distribution asset');
        $a=$matches[0];$url=$a['browser_download_url']??null;$size=$a['size']??null;$digest=$a['digest']??null;
        $prefix='https://github.com/'.$repo;$suffix='/releases/download/'.$release['tag_name'].'/'.$name;
        if(($a['state']??null)!=='uploaded'||!is_string($url)||strtolower(substr($url,0,strlen($prefix)))!==strtolower($prefix)||substr($url,strlen($prefix))!==$suffix||!is_int($size)||$size<60||$size>$max||($digest!==null&&(!is_string($digest)||!preg_match('/^sha256:[a-f0-9]{64}$/D',$digest))))throw new \RuntimeException('Invalid distribution asset metadata');
        ReleaseHttp::assertSafeUrl($url);
        return ['url'=>$url,'bytes'=>$size,'sha256'=>$digest===null?null:substr($digest,7)];
    }
}
