<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** HTTPS-only, bounded streaming GitHub transport. No curl extension required. */
class ReleaseHttp
{
    private const HOSTS = ['api.github.com','github.com','release-assets.githubusercontent.com',
        'objects.githubusercontent.com','github-releases.githubusercontent.com'];
    public static function assertSafeUrl(string $url): void
    {
        if (strlen($url)>4096 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) throw new \RuntimeException('Invalid release URL');
        $parsed=parse_url($url);
        if (!is_array($parsed)||($parsed['scheme']??null)!=='https'||
            !is_string($parsed['host']??null)||!in_array(strtolower($parsed['host']),self::HOSTS,true)||
            isset($parsed['port'])||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['fragment'])||
            !str_starts_with($parsed['path']??'','/')) throw new \RuntimeException('Untrusted release host or URL');
    }
    /** @return array{status:int,headers:array<string,string>,stream:resource} */
    protected function open(string $url, string $accept, ?string $etag = null): array
    {
        $headers=['User-Agent: CajeerEngineLite-Update/1.5.2','Accept: '.$accept,'Connection: close'];
        if ($etag !== null) {
            if (!self::validEtag($etag)) throw new \RuntimeException('Invalid release ETag');
            $headers[] = 'If-None-Match: '.$etag;
        }
        if ($accept === 'application/vnd.github+json') $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        $ctx=stream_context_create([
            'http'=>['method'=>'GET','timeout'=>20,'follow_location'=>0,'ignore_errors'=>true,
                'max_redirects'=>0,'header'=>implode("\r\n",$headers)],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,
                'SNI_enabled'=>true,'disable_compression'=>true],
        ]);
        $stream=@fopen($url,'rb',false,$ctx);
        if(!is_resource($stream))throw new \RuntimeException('Cannot contact GitHub release endpoint');
        $meta=stream_get_meta_data($stream);
        $lines=$meta['wrapper_data']??[];
        $first=is_array($lines)?($lines[0]??''):'';
        if(!is_string($first)||!preg_match('~^HTTP/\d(?:\.\d)? (\d{3})(?:\s|$)~',$first,$m)) {
            fclose($stream);throw new \RuntimeException('Invalid GitHub HTTP response');
        }
        $map=[];
        foreach($lines as $line) if(is_string($line)&&preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/D',$line,$match)) {
            $key=strtolower($match[1]);
            if(isset($map[$key]) && in_array($key,['location','content-length','content-encoding','etag','retry-after','x-ratelimit-remaining','x-ratelimit-reset'],true)) {
                fclose($stream);throw new \RuntimeException('Conflicting HTTP metadata');
            }
            $map[$key]=$key==='link' && isset($map[$key]) ? $map[$key].','.trim($match[2]) : trim($match[2]);
        }
        return ['status'=>(int)$m[1],'headers'=>$map,'stream'=>$stream];
    }
    /** @return resource */
    private function follow(string $url,string $accept): mixed
    {
        for($i=0;$i<5;$i++) {
            self::assertSafeUrl($url);
            $reply=$this->open($url,$accept);
            $status=$reply['status'];
            if(in_array($status,[301,302,303,307,308],true)) {
                fclose($reply['stream']);
                $target=$reply['headers']['location']??null;
                if(!is_string($target)||!str_starts_with($target,'https://')) throw new \RuntimeException('Invalid GitHub redirect');
                self::assertSafeUrl($target);
                $url=$target;
                continue;
            }
            if($status!==200) {fclose($reply['stream']);throw new \RuntimeException('GitHub release HTTP status '.$status);}
            if(($reply['headers']['content-encoding']??'identity')!=='identity') {
                fclose($reply['stream']);throw new \RuntimeException('Compressed releases are not supported');
            }
            return $reply['stream'];
        }
        throw new \RuntimeException('Too many GitHub redirects');
    }
    public static function validEtag(string $etag): bool
    {
        return strlen($etag) <= 256 && preg_match('~^(?:W/)?"[!\x23-\x7e]*"$~D', $etag) === 1;
    }

    /** Release metadata never follows redirects or sends credentials. Each page is independently revalidated. */
    public function page(string $url, ?string $etag = null): array
    {
        self::assertSafeUrl($url);
        if (!preg_match('~^https://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases\?per_page=30(?:&page=[1-9][0-9]*)?$~D', $url)) {
            throw new \RuntimeException('Invalid GitHub release API endpoint');
        }
        $reply = $this->open($url, 'application/vnd.github+json', $etag);
        $stream = $reply['stream'];
        try {
            $status = $reply['status']; $headers = $reply['headers'];
            if ($status !== 200) return ['status'=>$status,'headers'=>$headers,'releases'=>null];
            if (($headers['content-encoding'] ?? 'identity') !== 'identity') throw new \RuntimeException('Compressed release metadata is not supported');
            if (isset($headers['content-length']) && (!ctype_digit($headers['content-length']) || (float)$headers['content-length'] > 512000)) {
                throw new \RuntimeException('GitHub response exceeds size limit');
            }
            $body = ''; $deadline = hrtime(true) + 20_000_000_000;
            while (!feof($stream)) {
                $chunk = fread($stream,32768);
                if ($chunk === false || ($chunk === '' && !feof($stream)) || hrtime(true) > $deadline) throw new \RuntimeException('Incomplete GitHub JSON response');
                $body .= $chunk;
                if (strlen($body) > 512000) throw new \RuntimeException('GitHub response exceeds size limit');
            }
            if (isset($headers['content-length']) && strlen($body) !== (int)$headers['content-length']) throw new \RuntimeException('Incomplete GitHub JSON response');
            $data = json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($data) || !array_is_list($data) || count($data)>30) throw new \RuntimeException('Unexpected GitHub releases response');
            return ['status'=>200,'headers'=>$headers,'releases'=>$data];
        } finally { fclose($stream); }
    }
    public function json(string $url): array
    {
        $reply = $this->page($url);
        if ($reply['status'] !== 200) throw new \RuntimeException('GitHub release HTTP status '.$reply['status']);
        return $reply['releases'];
    }
    public function download(string $url,string $destination,int $expectedSize,?string $digest): array
    {
        if($expectedSize<60||$expectedSize>UpdatePackage::MAX_BYTES)throw new \RuntimeException('Invalid release asset size');
        self::assertSafeUrl($url);
        if(is_file($destination)||is_link($destination))throw new \RuntimeException('Download destination exists');
        $handle=@fopen($destination,'xb');
        if(!is_resource($handle))throw new \RuntimeException('Cannot create private update download');
        $stream=null;$success=false;
        try {
            if(!chmod($destination,0600)) throw new \RuntimeException('Cannot secure update download');
            $stream=$this->follow($url,'application/octet-stream');
            $hash=hash_init('sha256');$total=0;$deadline=hrtime(true)+120_000_000_000;
            while(!feof($stream)) {
                $data=fread($stream,65536);
                if(hrtime(true)>$deadline)throw new \RuntimeException('Release download time budget exceeded');
                if($data===false||($data===''&&!feof($stream)))throw new \RuntimeException('Interrupted update download');
                $size=strlen($data);$total+=$size;
                if($total>$expectedSize||$total>UpdatePackage::MAX_BYTES)throw new \RuntimeException('Release asset larger than declared');
                hash_update($hash,$data);
                for($offset=0;$offset<$size;) {
                    $written=fwrite($handle,substr($data,$offset));
                    if(!is_int($written)||$written<=0)throw new \RuntimeException('Update download write failed');
                    $offset+=$written;
                }
            }
            if($total!==$expectedSize)throw new \RuntimeException('Incomplete release asset');
            if(!fflush($handle)||!fsync($handle))throw new \RuntimeException('Cannot sync release download');
            $sha=hash_final($hash);
            if($digest!==null&&!hash_equals($digest,$sha))throw new \RuntimeException('GitHub asset digest mismatch');
            $success=true;
            return ['bytes'=>$total,'sha256'=>$sha];
        } finally {
            if(is_resource($stream))fclose($stream);
            fclose($handle);
            if(!$success)@unlink($destination);
        }
    }
}
