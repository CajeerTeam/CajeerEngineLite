<?php
declare(strict_types=1);
namespace CajeerEngine\Media;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Private, indexed media store. Uploaded bytes never enter a web-executable directory. */
final class MediaLibrary
{
    public const MAX_FILES = 250;
    public const MAX_FILE_BYTES = 8_000_000;
    public const MAX_TOTAL_BYTES = 100_000_000;
    public const MAX_PIXELS = 24_000_000;
    private const TYPES = [
        'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp',
        'image/gif'=>'gif', 'application/pdf'=>'pdf',
    ];

    public function __construct(private readonly string $root) {}

    private function location(string $relative): string
    {
        return OperationFiles::path($this->root, 'storage/media/'.$relative);
    }

    private function ensureDirectory(): void
    {
        foreach (['storage/media','storage/media/files'] as $folder) {
            $path = OperationFiles::path($this->root, $folder);
            OperationFiles::dir($path, 0700);
            $mode = @fileperms($path);
            if ($mode === false || (($mode & 0077) !== 0 && !@chmod($path, 0700))) {
                throw new \RuntimeException('Media storage must be private');
            }
            clearstatcache(true, $path);
            if ((fileperms($path) & 0077) !== 0) throw new \RuntimeException('Media storage permissions');
        }
    }

    private function locked(callable $fn): mixed
    {
        $this->ensureDirectory();
        $lock = OperationLock::openLockFile($this->location('.lock'), 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Media lock unavailable');
            return $fn();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Serialize content writes and deletion so a saved page never refers to a deleted file. */
    public function guard(callable $fn): mixed
    {
        return $this->locked($fn);
    }

    /** Index lookup also checks the actual private file, without reading all its bytes. */
    public function metadata(string $id): ?array
    {
        $id=self::id($id);
        $item=$this->index()[$id]??null;
        if ($item===null) return null;
        $path=$this->location('files/'.$id.'.'.$item['extension']);
        $stat=@lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 ||
            ($stat['mode'] & 0077)!==0 || $stat['size']!==$item['size']) {
            throw new \RuntimeException('Unsafe or missing media file');
        }
        return $item;
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name==='' || strlen($name)>150 || !preg_match('//u', $name) ||
            preg_match('~[/\\\\\x00-\x1f\x7f<>]~u', $name) || $name==='.' || $name==='..') {
            throw new \InvalidArgumentException('Invalid media filename');
        }
        return $name;
    }

    private static function id(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid media ID');
        return $id;
    }

    /** @return array<string,array<string,mixed>> */
    private function index(): array
    {
        $path=$this->location('index.json');
        if (!file_exists($path) && !is_link($path)) return [];
        $entry=@lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1 || ($entry['mode'] & 0077)!==0 || $entry['size'] > 220000) {
            throw new \RuntimeException('Unsafe media index');
        }
        $data=OperationFiles::read($path);
        if (($data['version']??null)!==1 || !is_array($data['files']??null) || count($data['files'])>self::MAX_FILES) {
            throw new \RuntimeException('Invalid media index');
        }
        foreach ($data['files'] as $id=>$item) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_array($item) ||
                ($item['id']??null)!==$id || !is_string($item['name']??null) ||
                !is_string($item['mime']??null) || !isset(self::TYPES[$item['mime']]) ||
                ($item['extension']??null)!==self::TYPES[$item['mime']] ||
                !is_int($item['size']??null) || $item['size']<1 || $item['size']>self::MAX_FILE_BYTES ||
                !is_int($item['created']??null) || !preg_match('/^[a-f0-9]{64}$/D', $item['sha256']??'')) {
                throw new \RuntimeException('Invalid media index entry');
            }
            try { self::name($item['name']); } catch (\InvalidArgumentException) { throw new \RuntimeException('Invalid media filename in index'); }
        }
        return $data['files'];
    }

    private function writeIndex(array $entries): void
    {
        OperationFiles::json($this->location('index.json'), ['version'=>1,'files'=>$entries], 0600);
    }

    /** @return list<array<string,mixed>> */
    public function listing(string $query = '', string $category = 'all'): array
    {
        if (strlen($query)>120 || !preg_match('//u', $query) || str_contains($query, "\0") || !in_array($category, ['all','image','document'], true)) {
            throw new \InvalidArgumentException('Invalid media filter');
        }
        $rows=[];
        foreach ($this->index() as $item) {
            if ($category==='image' && !str_starts_with($item['mime'], 'image/')) continue;
            if ($category==='document' && $item['mime']!=='application/pdf') continue;
            if ($query!=='' && (function_exists('mb_stripos') ? mb_stripos($item['name'],$query,0,'UTF-8')===false : stripos($item['name'],$query)===false)) continue;
            $rows[]=$item;
        }
        usort($rows, static fn($a,$b)=>$b['created']<=>$a['created'] ?: strcmp($b['id'],$a['id']));
        return $rows;
    }

    public function statistics(): array
    {
        $entries=$this->index();
        return ['count'=>count($entries), 'bytes'=>array_sum(array_column($entries,'size'))];
    }

    /** @return array<string,mixed> */
    public function upload(array $file): array
    {
        if (($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) ||
            !is_string($file['name']??null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Upload failed or is not an HTTP upload');
        }
        return $this->ingest($file['tmp_name'],$file['name']);
    }

    /** Shared implementation also supports trusted CLI import and executable regression tests. */
    public function ingest(string $source, string $filename): array
    {
        $filename=self::name($filename);
        if (is_link($source) || !is_file($source)) throw new \InvalidArgumentException('Invalid upload source');
        $input=@fopen($source,'rb');
        if (!is_resource($input)) throw new \InvalidArgumentException('Cannot read uploaded file');
        try {
            $stat=fstat($input);
            if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 ||
                $stat['size']<1 || $stat['size']>self::MAX_FILE_BYTES) throw new \InvalidArgumentException('File exceeds 8 MB or is unsafe');
            $raw=stream_get_contents($input,self::MAX_FILE_BYTES+1);
            if (!is_string($raw) || strlen($raw)!==$stat['size']) throw new \InvalidArgumentException('File read error');
        } finally { fclose($input); }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) throw new \InvalidArgumentException('File type is not allowed');
        $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        $allowed=$mime==='image/jpeg' ? ['jpg','jpeg'] : [self::TYPES[$mime]];
        if (!in_array($ext,$allowed,true)) throw new \InvalidArgumentException('Filename extension does not match content');
        $width=null; $height=null;
        if (str_starts_with($mime,'image/')) {
            $info=@getimagesizefromstring($raw);
            if (!is_array($info) || ($info['mime']??null)!==$mime || !isset($info[0],$info[1]) ||
                $info[0]<1 || $info[1]<1 || $info[0]>12000 || $info[1]>12000 ||
                $info[0]*$info[1]>self::MAX_PIXELS) throw new \InvalidArgumentException('Invalid image or dimensions');
            if ($mime==='image/png' && (!str_starts_with($raw,"\x89PNG\r\n\x1a\n") || !str_ends_with($raw,"IEND\xaeB`\x82"))) {
                throw new \InvalidArgumentException('Invalid PNG structure');
            }
            if ($mime==='image/gif' && (!in_array(substr($raw,0,6), ['GIF87a','GIF89a'],true) || !str_ends_with($raw,"\x3b"))) {
                throw new \InvalidArgumentException('Invalid GIF structure');
            }
            if ($mime==='image/jpeg' && (!str_starts_with($raw,"\xff\xd8\xff") || !str_ends_with($raw,"\xff\xd9"))) {
                throw new \InvalidArgumentException('Invalid JPEG structure');
            }
            if ($mime==='image/webp' && (!str_starts_with($raw,'RIFF') || substr($raw,8,4)!=='WEBP' ||
                strlen($raw)!==unpack('V',substr($raw,4,4))[1]+8)) {
                throw new \InvalidArgumentException('Invalid WebP structure');
            }
            $width=$info[0];$height=$info[1];
        } else {
            if (!str_starts_with($raw,'%PDF-') || strpos(substr($raw,-2048),'%%EOF')===false) {
                throw new \InvalidArgumentException('Invalid PDF structure');
            }
        }
        $meta=$this->locked(function() use($raw,$filename,$mime,$width,$height): array {
            $entries=$this->index();
            if (count($entries)>=self::MAX_FILES) throw new \InvalidArgumentException('Media limit: 250 files');
            $used=array_sum(array_column($entries,'size'));
            if ($used+strlen($raw)>self::MAX_TOTAL_BYTES) throw new \InvalidArgumentException('Media storage limit: 100 MB');
            do { $id=bin2hex(random_bytes(16)); } while (isset($entries[$id]));
            $extension=self::TYPES[$mime];
            $path=$this->location('files/'.$id.'.'.$extension);
            $entry=['id'=>$id,'name'=>$filename,'mime'=>$mime,'extension'=>$extension,
                'size'=>strlen($raw),'width'=>$width,'height'=>$height,'sha256'=>hash('sha256',$raw),'created'=>time()];
            OperationFiles::write($path,$raw,0600);
            try { $entries[$id]=$entry; $this->writeIndex($entries); }
            catch (\Throwable $exception) { OperationFiles::remove($path); throw $exception; }
            return $entry;
        });
        return $meta;
    }

    public function rename(string $id,string $name): void
    {
        $id=self::id($id);$name=self::name($name);
        $this->locked(function()use($id,$name): void {
            $entries=$this->index();
            if (!isset($entries[$id])) throw new \InvalidArgumentException('Media file not found');
            $mime=$entries[$id]['mime'];
            $ext=strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $expected=$mime==='image/jpeg'?['jpg','jpeg']:[self::TYPES[$mime]];
            if (!in_array($ext,$expected,true)) throw new \InvalidArgumentException('Filename extension must match media type');
            $entries[$id]['name']=$name;
            $this->writeIndex($entries);
        });
    }

    public function delete(string $id): void
    {
        $id=self::id($id);
        $this->locked(function()use($id): void {
            $entries=$this->index();
            if (!isset($entries[$id])) throw new \InvalidArgumentException('Media file not found');
            // Lock order is media -> site, matching the visual editor transaction.
            // Check actual site data under the media lock, not a stale UI snapshot.
            if (is_file($this->root.'/storage/site.json')) {
                $site=(new \CajeerEngine\Lite($this->root))->load();
                $used=\CajeerEngine\Content\MediaReferences::pages($site['pages'],$id);
                if ($used!==[]) throw new \InvalidArgumentException('Media is used by pages: '.implode(', ',array_slice($used,0,5)));
                if (($site['seo']['default_image']??'')===$id) throw new \InvalidArgumentException('Media is the default SEO social image');
            }
            $path=$this->location('files/'.$id.'.'.$entries[$id]['extension']);
            if (is_link($path) || !is_file($path)) throw new \RuntimeException('Unsafe or missing media file');
            unset($entries[$id]);
            $this->writeIndex($entries);
            // Once the index is committed the file is unreachable through the HTTP route.
            OperationFiles::remove($path);
        });
    }

    /** Verify portable media metadata and payloads without touching the live library. */
    public static function validateArchive(array $entries, array $paths): void
    {
        if (count($entries)!==count($paths) || count($entries)>self::MAX_FILES ||
            array_sum(array_map(static fn($m)=>is_array($m) && is_int($m['size']??null)?$m['size']:self::MAX_TOTAL_BYTES+1,$entries))>self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException('Invalid archived media quota');
        }
        foreach ($entries as $id=>$meta) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_array($meta) ||
                array_keys($meta)!==['id','name','mime','extension','size','width','height','sha256','created'] ||
                $meta['id']!==$id || !is_string($meta['name']) || !is_string($meta['mime']) ||
                !isset(self::TYPES[$meta['mime']]) || $meta['extension']!==self::TYPES[$meta['mime']] ||
                !is_int($meta['size']) || $meta['size']<1 || $meta['size']>self::MAX_FILE_BYTES ||
                !is_int($meta['created']) || $meta['created']<0 ||
                !is_string($meta['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']) ||
                !isset($paths[$id])) throw new \RuntimeException('Invalid archived media metadata');
            self::name($meta['name']);
            $file=$paths[$id]; $stat=@lstat($file);
            if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 ||
                $stat['size']!==$meta['size'] || !hash_equals($meta['sha256'],OperationFiles::hash($file))) {
                throw new \RuntimeException('Archived media bytes failed verification');
            }
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
            if ($mime!==$meta['mime']) throw new \RuntimeException('Archived media MIME mismatch');
            if (str_starts_with($mime,'image/')) {
                $dimensions=@getimagesize($file);
                if (!is_array($dimensions) || ($dimensions['mime']??null)!==$mime ||
                    ($dimensions[0]??null)!==$meta['width'] || ($dimensions[1]??null)!==$meta['height'] ||
                    $dimensions[0]<1 || $dimensions[1]<1 || $dimensions[0]>12000 || $dimensions[1]>12000 ||
                    $dimensions[0]*$dimensions[1]>self::MAX_PIXELS) throw new \RuntimeException('Invalid archived image');
            } elseif ($meta['width']!==null || $meta['height']!==null) {
                throw new \RuntimeException('Invalid archived PDF metadata');
            }
            $handle=@fopen($file,'rb');
            if (!is_resource($handle)) throw new \RuntimeException('Cannot inspect archived media');
            try {
                $first=fread($handle,12);
                if (fseek($handle,max(0,$meta['size']-2048))!==0) throw new \RuntimeException('Cannot inspect media suffix');
                $last=stream_get_contents($handle);
            } finally {fclose($handle);}
            if (!is_string($first) || !is_string($last) ||
                ($mime==='image/png' && (!str_starts_with($first,"\x89PNG\r\n\x1a\n") || !str_ends_with($last,"IEND\xaeB`\x82"))) ||
                ($mime==='image/jpeg' && (!str_starts_with($first,"\xff\xd8\xff") || !str_ends_with($last,"\xff\xd9"))) ||
                ($mime==='image/gif' && (!in_array(substr($first,0,6),['GIF87a','GIF89a'],true) || !str_ends_with($last,"\x3b"))) ||
                ($mime==='image/webp' && (!str_starts_with($first,'RIFF') || substr($first,8,4)!=='WEBP' ||
                    unpack('V',substr($first,4,4))[1]+8!==$meta['size'])) ||
                ($mime==='application/pdf' && (!str_starts_with($first,'%PDF-') || !str_contains($last,'%%EOF')))) {
                throw new \RuntimeException('Archived media structure is invalid');
            }
        }
    }

    /**
     * Transactional, additive import retaining stable media IDs. Caller supplies only
     * verified archive members; all files and the index are rolled back if site commit fails.
     * Existing assets are not deleted because they may be referenced by other site history.
     * @param array<string,array<string,mixed>> $incoming
     * @param array<string,string> $paths
     */
    public function importPreservingIds(array $incoming, array $paths, callable $commit): mixed
    {
        return $this->locked(function () use ($incoming,$paths,$commit): mixed {
            self::validateArchive($incoming,$paths);
            $existing=$this->index();
            $combined=$existing;
            $added=[];
            if (count($incoming)>self::MAX_FILES || count($incoming)!==count($paths)) {
                throw new \InvalidArgumentException('Invalid media archive contents');
            }
            foreach ($incoming as $id=>$meta) {
                self::id((string)$id);
                if (!is_array($meta) || ($meta['id']??null)!==$id ||
                    !is_string($meta['name']??null) || !is_string($meta['mime']??null) ||
                    !isset(self::TYPES[$meta['mime']]) || ($meta['extension']??null)!==self::TYPES[$meta['mime']] ||
                    !is_int($meta['size']??null) || $meta['size']<1 || $meta['size']>self::MAX_FILE_BYTES ||
                    !is_string($meta['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$meta['sha256']) ||
                    !is_int($meta['created']??null) || $meta['created']<0 ||
                    !array_key_exists('width',$meta) || !array_key_exists('height',$meta) ||
                    !isset($paths[$id])) throw new \InvalidArgumentException('Invalid imported media metadata');
                self::name($meta['name']);
                $file=$paths[$id];
                $stat=@lstat($file);
                if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 ||
                    $stat['size']!==$meta['size'] || !hash_equals($meta['sha256'],OperationFiles::hash($file))) {
                    throw new \RuntimeException('Invalid imported media bytes');
                }
                $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
                if ($mime!==$meta['mime']) throw new \RuntimeException('Imported media type mismatch');
                if (str_starts_with($mime,'image/')) {
                    $info=@getimagesize($file);
                    if (!is_array($info) || ($info['mime']??null)!==$mime ||
                        ($info[0]??null)!==($meta['width']??null) || ($info[1]??null)!==($meta['height']??null) ||
                        $info[0]<1 || $info[1]<1 || $info[0]*$info[1]>self::MAX_PIXELS ||
                        $info[0]>12000 || $info[1]>12000) throw new \RuntimeException('Invalid imported image');
                } elseif (($meta['width']??null)!==null || ($meta['height']??null)!==null) {
                    throw new \RuntimeException('Invalid PDF metadata');
                }
                if (isset($existing[$id]) && (
                    $existing[$id]['sha256']!==$meta['sha256'] || $existing[$id]['mime']!==$meta['mime'] ||
                    $existing[$id]['size']!==$meta['size'] || $existing[$id]['extension']!==$meta['extension'])) {
                    throw new \RuntimeException('Media ID collision');
                }
                if (isset($existing[$id])) {
                    // Do not silently accept a missing/corrupted local file as a valid collision.
                    $this->read($id);
                }
                $combined[$id]=$meta;
            }
            if (count($combined)>self::MAX_FILES || array_sum(array_column($combined,'size'))>self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('Imported media exceeds storage quota');
            }
            try {
                foreach ($incoming as $id=>$meta) {
                    if (isset($existing[$id])) continue;
                    $path=$this->location('files/'.$id.'.'.$meta['extension']);
                    if (file_exists($path) || is_link($path)) throw new \RuntimeException('Unindexed media path already exists');
                    OperationFiles::copy($paths[$id],$path,0600);
                    $added[]=$path;
                }
                $this->writeIndex($combined);
                return $commit();
            } catch (\Throwable $failure) {
                try { $this->writeIndex($existing); }
                finally { foreach ($added as $path) OperationFiles::remove($path); }
                throw $failure;
            }
        });
    }

    /** Read verified, registered bytes; never redirect clients to a storage path. */
    public function read(string $id): ?array
    {
        $id=self::id($id);
        $entries=$this->index();
        if (!isset($entries[$id])) return null;
        $meta=$entries[$id];
        $path=$this->location('files/'.$id.'.'.$meta['extension']);
        $stat=@lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || ($stat['mode'] & 0077)!==0 || $stat['nlink']!==1 || $stat['size']!==$meta['size']) {
            throw new \RuntimeException('Unsafe media file');
        }
        $handle=@fopen($path,'rb');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot read media file');
        try {
            $current=fstat($handle);
            if (!is_array($current) || $current['ino']!==$stat['ino'] || $current['dev']!==$stat['dev'] || $current['nlink']!==1) {
                throw new \RuntimeException('Media file changed');
            }
            $raw=stream_get_contents($handle,self::MAX_FILE_BYTES+1);
            if (!is_string($raw) || strlen($raw)!==$meta['size'] || !hash_equals($meta['sha256'],hash('sha256',$raw))) {
                throw new \RuntimeException('Media checksum mismatch');
            }
        } finally { fclose($handle); }
        return ['meta'=>$meta,'bytes'=>$raw];
    }
}
