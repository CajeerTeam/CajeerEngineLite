<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Bounded, conditional public GitHub discovery. Never authorizes an installation. */
final class ReleaseDiscovery
{
    private const CACHE = 'storage/app/updates/auto/discovery.json';
    private const MAX_PAGES = 20;
    private const MAX_CACHE = 12_000_000;
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly string $root, private readonly ReleaseHttp $http) {}

    public static function channel(): string
    {
        $channel = getenv('CAJEER_AUTO_UPDATE_CHANNEL');
        if ($channel === false || $channel === '') return 'stable';
        if (!in_array($channel, ['stable','preview'],true)) throw new \RuntimeException('Invalid update channel (stable or preview)');
        return $channel;
    }

    private function path(): string { return OperationFiles::path($this->root,self::CACHE); }

    /** Cache is private and optional; unsafe filesystem entries fail closed. */
    private function cache(string $repo): array
    {
        $path = $this->path();
        clearstatcache(true,$path);
        $entry = @lstat($path);
        if ($entry === false) return [];
        if (($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1 || ($entry['mode'] & 0077) !== 0 || $entry['size'] > self::MAX_CACHE) {
            throw new \RuntimeException('Unsafe release discovery cache');
        }
        $h = @fopen($path,'rb');
        if (!is_resource($h)) throw new \RuntimeException('Cannot read release discovery cache');
        try {
            $stat = fstat($h);
            if (!is_array($stat) || $stat['ino'] !== $entry['ino'] || $stat['dev'] !== $entry['dev'] || $stat['nlink'] !== 1 || ($stat['mode'] & 0077) !== 0) throw new \RuntimeException('Release cache identity changed');
            $raw = stream_get_contents($h,self::MAX_CACHE+1);
            if (!is_string($raw) || strlen($raw) !== $entry['size']) throw new \RuntimeException('Cannot read complete release cache');
        } finally { fclose($h); }
        try { $data = json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return []; }
        if (!is_array($data) || !in_array($data['format']??null,[2,3],true) || ($data['repo']??null)!==$repo || !is_array($data['pages']??null) || !array_is_list($data['pages']) || count($data['pages'])>self::MAX_PAGES) return [];
        foreach ($data['pages'] as $page) {
            if (!is_array($page) || !is_array($page['releases']??null) || !array_is_list($page['releases']) || count($page['releases'])>30 || !is_bool($page['next']??null) ||
                (isset($page['etag']) && (!is_string($page['etag']) || !ReleaseHttp::validEtag($page['etag'])))) return [];
        }
        return $data;
    }

    private function save(string $repo,array $pages,int $blockedUntil=0): void
    {
        $data = json_encode(['format'=>3,'repo'=>$repo,'blocked_until'=>$blockedUntil,'pages'=>$pages],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($data)>self::MAX_CACHE) throw new \RuntimeException('Release cache exceeds size limit');
        OperationFiles::write($this->path(),$data,0600);
    }

    private static function delay(array $headers,int $now): int
    {
        $retry = $headers['retry-after']??null;
        if (is_string($retry)) {
            if (ctype_digit($retry)) return max(1,min(86400,(int)$retry));
            $date = strtotime($retry);
            if ($date !== false && $date>$now) return min(86400,$date-$now);
        }
        $reset = $headers['x-ratelimit-reset']??null;
        if (($headers['x-ratelimit-remaining']??null)==='0' && is_string($reset) && ctype_digit($reset) && (int)$reset>$now) return min(86400,(int)$reset-$now+1);
        return 60;
    }

    private function request(string $url,?string $etag,string $repo,array $oldPages,int $deadline): array
    {
        for ($attempt=0;$attempt<self::MAX_ATTEMPTS;$attempt++) {
            if (hrtime(true)>$deadline) throw new \RuntimeException('GitHub discovery time budget exceeded');
            try { $reply = $this->http->page($url,$etag); }
            catch (\RuntimeException $e) {
                // Only transport failures are retried; invalid payloads/URLs fail closed.
                if ($e->getMessage()!=='Cannot contact GitHub release endpoint' && $e->getMessage()!=='Incomplete GitHub JSON response') throw $e;
                if ($attempt===self::MAX_ATTEMPTS-1) throw $e;
                usleep(250000*(2**$attempt)); continue;
            }
            $status=$reply['status']; $headers=$reply['headers'];
            if ($status===200 || $status===304) return $reply;
            if ($status===429 || $status===403 || ($status===503 && isset($headers['retry-after']))) {
                $now=time(); $until=$now+self::delay($headers,$now);
                $this->save($repo,$oldPages,$until);
                throw new \RuntimeException('GitHub discovery throttled until '.gmdate('c',$until));
            }
            if (!in_array($status,[408,500,502,503,504],true) || $attempt===self::MAX_ATTEMPTS-1) throw new \RuntimeException('GitHub release HTTP status '.$status);
            usleep(250000*(2**$attempt));
        }
        throw new \RuntimeException('GitHub discovery retry exhausted');
    }

    public function fetch(string $repo,string $installed,string $channel): array
    {
        $cached=$this->cache($repo); $oldPages=($cached['format']??null)===3?($cached['pages']??[]):[];
        $blocked=$cached['blocked_until']??0;
        if (is_int($blocked) && $blocked>time()) throw new \RuntimeException('GitHub discovery throttled until '.gmdate('c',$blocked));
        $pages=[]; $options=[]; $indexed=[]; $seen=[]; $revalidated=0; $deadline=hrtime(true)+120_000_000_000;
        for ($n=1;$n<=self::MAX_PAGES;$n++) {
            $old=$oldPages[$n-1]??null; $etag=$old['etag']??null;
            $url='https://api.github.com/repos/'.$repo.'/releases?per_page=30'.($n===1?'':'&page='.$n);
            $reply=$this->request($url,$etag,$repo,$oldPages,$deadline);
            if ($reply['status']===304) {
                if ($etag===null || $old===null) throw new \RuntimeException('GitHub returned 304 without a cached representation');
                $page=$old; $revalidated++;
                // Pagination may change even when the representation does not.
                if (isset($reply['headers']['link'])) $page['next']=self::hasNext($reply['headers']['link']);
                elseif (count($page['releases'])===30) $page['next']=true;
            } else {
                $data=$reply['releases'];
                if (!is_array($data) || !array_is_list($data) || count($data)>30) throw new \RuntimeException('Invalid release page');
                $etag=$reply['headers']['etag']??null;
                $page=['etag'=>is_string($etag)&&ReleaseHttp::validEtag($etag)?$etag:null,'releases'=>array_map(self::compact(...),$data),
                    'next'=>isset($reply['headers']['link'])?self::hasNext($reply['headers']['link']):count($data)===30];
            }
            $pages[]=$page;
            foreach ($page['releases'] as $release) {
                if (!is_array($release)) throw new \RuntimeException('Invalid GitHub release entry');
                // Detect duplicate release IDs across moving pages; never install from a partial scan.
                if (isset($release['id']) && is_int($release['id'])) {
                    if (isset($seen[$release['id']])) throw new \RuntimeException('GitHub releases changed during pagination; retry discovery');
                    $seen[$release['id']]=true;
                }
                $tag=$release['tag_name']??null;
                if (($release['draft']??null)===false && is_bool($release['prerelease']??null) && ($channel!=='stable'||!$release['prerelease']) && is_string($tag) && preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D',$tag,$m) && version_compare($m[1],$installed,'>') && is_array($release['assets']??null)) {
                    foreach ($release['assets'] as $asset) if (($asset['name']??null)===DistributionIndex::NAME) {
                        if(isset($indexed[$m[1]]))throw new \RuntimeException('Ambiguous indexed release');
                        $indexed[$m[1]]=$release;break;
                    }
                }
                $option=self::candidate($release,$repo,$installed,$channel);
                if ($option===null) continue;
                $version=$option['version'];
                if (isset($options[$version])) throw new \RuntimeException('Ambiguous GitHub release: '.$version);
                $options[$version]=$option;
            }
            if (!$page['next']) {
                $this->save($repo,$pages);
                uksort($options,'version_compare');
                $plan=[];$mode='legacy';$available=$options?reset($options):null;$latest=$options?end($options):null;
                if($indexed){
                    if(count($indexed)>60)throw new \RuntimeException('Distribution index count exceeds limit');
                    $mode='signed';$edges=[];$distribution=new DistributionIndex($this->root,$this->http);
                    foreach($indexed as $release){
                        if(hrtime(true)>$deadline)throw new \RuntimeException('Distribution discovery time budget exceeded');
                        foreach($distribution->edges($release,$repo) as $edge) $edges[]=$edge+['notes'=>self::notes($release)];
                    }
                    $plan=DistributionIndex::plan($installed,$edges);
                    $available=$plan[0]??null;$latest=$plan?end($plan):null;
                    if(!$plan)throw new \RuntimeException('No signed update path from installed version');
                }
                return ['installed'=>$installed,'available'=>$available,'latest'=>$latest,'distribution'=>$mode,'plan'=>$plan,
                    'channel'=>$channel,'pages'=>count($pages),'revalidated_pages'=>$revalidated,'checked_at'=>gmdate('c')];
            }
        }
        throw new \RuntimeException('GitHub discovery page limit exceeded; no complete release selection');
    }

    /** Retain only metadata needed to repeat selection after a conditional 304. */
    private static function compact(mixed $release): array
    {
        if (!is_array($release)) throw new \RuntimeException('Invalid GitHub release entry');
        $data = array_intersect_key($release,array_flip(['id','draft','prerelease','tag_name']));
        foreach ($data as $key=>$value) if (!is_scalar($value) || (is_string($value) && strlen($value)>256)) unset($data[$key]);
        $data['body']=self::notes($release);
        if (is_array($release['assets']??null)) {
            $data['assets']=[];
            $tag=$data['tag_name']??null;
            if (!is_string($tag)) return $data;
            $name='CajeerEngineLite-'.ltrim($tag,'v').'.cjrupdate';
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset) || !is_string($asset['name']??null)) continue;
                $assetName=$asset['name'];
                if ($assetName!==DistributionIndex::NAME && $assetName!==$name && !preg_match('/^CajeerEngineLite-[0-9]+\.[0-9]+\.[0-9]+-to-'.preg_quote(ltrim($tag,'v'),'/').'\.cjrupdate$/D',$assetName)) continue;
                if ($assetName===$name && ($asset['state']??null)!=='uploaded') continue;
                $entry=array_intersect_key($asset,array_flip(['name','state','browser_download_url','size','digest']));
                // Do not turn invalid digests into absent digests when sanitizing.
                foreach ($entry as $key=>$value) if ((!is_scalar($value) && $value!==null) || (is_string($value)&&strlen($value)>4096)) $entry[$key]=false;
                $data['assets'][]=$entry;
                if (count($data['assets'])>32) throw new \RuntimeException('Too many distribution assets');
            }
        }
        return $data;
    }

    private static function notes(array $release): string
    {
        $body=$release['body']??'';
        if(!is_string($body)||!preg_match('//u',$body))return '';
        $body=substr($body,0,4000);
        while(!preg_match('//u',$body))$body=substr($body,0,-1);
        return $body;
    }
    private static function hasNext(string $link): bool { return preg_match('/;\s*rel\s*=\s*"next"/i',$link)===1; }

    private static function candidate(array $release,string $repo,string $installed,string $channel): ?array
    {
        if (($release['draft']??null)!==false || !is_bool($release['prerelease']??null) || ($channel==='stable' && $release['prerelease']) || !is_array($release['assets']??null)) return null;
        $tag=$release['tag_name']??null;
        if (!is_string($tag) || !preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D',$tag,$m)) return null;
        $version=$m[1]; if (version_compare($version,$installed,'<=')) return null;
        $name='CajeerEngineLite-'.$version.'.cjrupdate'; $matches=[];
        foreach ($release['assets'] as $asset) if (is_array($asset) && ($asset['name']??null)===$name && ($asset['state']??null)==='uploaded') $matches[]=$asset;
        if (count($matches)!==1) return null;
        $asset=$matches[0]; $url=$asset['browser_download_url']??null; $bytes=$asset['size']??null;
        if (!is_string($url) || !is_int($bytes) || $bytes<60 || $bytes>UpdatePackage::MAX_BYTES) return null;
        try { ReleaseHttp::assertSafeUrl($url); } catch (\RuntimeException) { return null; }
        $prefix='https://github.com/'.$repo; $suffix='/releases/download/'.$tag.'/'.$name;
        if (strtolower(substr($url,0,strlen($prefix)))!==strtolower($prefix) || substr($url,strlen($prefix))!==$suffix) return null;
        $digest=$asset['digest']??null;
        if ($digest!==null && (!is_string($digest) || !preg_match('/^sha256:[a-f0-9]{64}$/D',$digest))) return null;
        return ['version'=>$version,'tag'=>$tag,'url'=>$url,'bytes'=>$bytes,'sha256'=>$digest===null?null:substr($digest,7),'prerelease'=>$release['prerelease'],'notes'=>self::notes($release)];
    }
}
