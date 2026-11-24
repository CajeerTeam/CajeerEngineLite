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

    private int $lockDepth = 0;
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
        // Page/SEO editors and the archive builder already hold this instance's
        // media lock; do not acquire a second descriptor and deadlock ourselves.
        if ($this->lockDepth > 0) return $fn();
        $this->ensureDirectory();
        $lock = OperationLock::openLockFile($this->location('.lock'), 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Media lock unavailable');
            $this->lockDepth = 1;
            return $fn();
        } finally {
            $this->lockDepth = 0;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Serialize content writes and deletion so a saved page never refers to a deleted file. */
    public function guard(callable $fn): mixed
    {
        return $this->locked($fn);
    }

    /** Index lookup also checks the actual private file, without reading all its bytes. */
    public function metadata(string $id): ?array
    {
        return $this->locked(fn()=> $this->metadataLocked($id));
    }

    private function metadataLocked(string $id): ?array
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
        if (!OperationLock::exclusiveHeld($this->root) && \CajeerEngine\Operations\RecoveryJournal::pending($this->root)) {
            throw new \RuntimeException('CMS import recovery pending; run operations recover');
        }
        $path=$this->location('index.json');
        if (!file_exists($path) && !is_link($path)) return [];
        $entry=@lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1 || ($entry['mode'] & 0077)!==0 || $entry['size'] > 220000) {
            throw new \RuntimeException('Unsafe media index');
        }
        $raw=$this->indexBytes();
        if ($raw===null) return [];
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid media index JSON');
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

    /** Preserve the exact old index, including its absence, across failed publication. */
    private function indexBytes(): ?string
    {
        $path = $this->location('index.json');
        if (!file_exists($path) && !is_link($path)) return null;
        $entry = @lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 ||
            $entry['nlink'] !== 1 || ($entry['mode'] & 0077) !== 0 || $entry['size'] > 220000) {
            throw new \RuntimeException('Unsafe media index');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot read media index');
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($opened) || !is_array($current) ||
                $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev'] ||
                $opened['nlink'] !== 1 || ($opened['mode'] & 0170000) !== 0100000 ||
                ($current['mode'] & 0077) !== 0) {
                throw new \RuntimeException('Media index changed during read');
            }
            $raw = stream_get_contents($handle, 220001);
            if (!is_string($raw) || strlen($raw) !== $opened['size'] || strlen($raw) > 220000) {
                throw new \RuntimeException('Incomplete media index');
            }
            return $raw;
        } finally { fclose($handle); }
    }

    private function restoreIndex(?string $bytes): void
    {
        $path = $this->location('index.json');
        if ($bytes === null) OperationFiles::remove($path);
        else OperationFiles::write($path, $bytes, 0600);
    }

    /** Used only while holding the media writer lock and CMS operation lease. */
    public function restoreIndexFromRecovery(?string $bytes): void
    {
        if ($this->lockDepth===0 || !OperationLock::exclusiveHeld($this->root)) {
            throw new \LogicException('Media recovery requires both writer locks');
        }
        $this->restoreIndex($bytes);
    }

    /** Roll back even if a write reported failure after its atomic rename. */
    private function indexChange(array $entries, callable $after): mixed
    {
        $before = $this->indexBytes();
        try {
            $this->writeIndex($entries);
            return $after();
        } catch (\Throwable $failure) {
            try {
                if ($this->indexBytes() !== $before) $this->restoreIndex($before);
            } catch (\Throwable $rollback) {
                throw new \RuntimeException('Media index rollback failed; recover from a full CMS backup', 0, $rollback);
            }
            throw $failure;
        }
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
            $entry=@lstat($source);
            if (!is_array($stat) || !is_array($entry) ||
                ($stat['mode'] & 0170000)!==0100000 || ($entry['mode'] & 0170000)!==0100000 ||
                $stat['ino']!==$entry['ino'] || $stat['dev']!==$entry['dev'] ||
                $stat['nlink']!==1 || $stat['size']<1 || $stat['size']>self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException('File exceeds 8 MB or is unsafe');
            }
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
            // A new ID must never replace a stale, unindexed file or symlink.
            OperationFiles::writeNew($path,$raw,0600);
            try {
                $entries[$id]=$entry;
                $this->indexChange($entries,static fn()=>null);
            } catch (\Throwable $failure) {
                try { OperationFiles::remove($path); }
                catch (\Throwable $rollback) {
                    throw new \RuntimeException('Media upload rollback failed; check storage integrity',0,$rollback);
                }
                throw $failure;
            }
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
            $this->indexChange($entries,static fn()=>null);
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
            $before=$this->indexBytes();
            $this->indexChange($entries,static fn()=>null);
            try {
                // A crash here leaves an unindexed orphan, never a dangling index.
                // The audited maintenance command can remove such orphans safely.
                OperationFiles::remove($path);
            } catch (\Throwable $failure) {
                // A failure before unlink is reversible. A failure during directory
                // fsync may be reported *after* unlink; never resurrect a dangling ID.
                if (is_file($path) && !is_link($path)) {
                    try { $this->restoreIndex($before); }
                    catch (\Throwable $rollback) {
                        throw new \RuntimeException('Media deletion rollback failed',0,$rollback);
                    }
                }
                throw $failure;
            }
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
    public function importPreservingIds(array $incoming, array $paths, callable $commit, ?callable $beforeChange = null): mixed
    {
        return $this->locked(function () use ($incoming,$paths,$commit,$beforeChange): mixed {
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
            // Reject pre-existing unindexed paths before recording them as
            // import-created assets in the durable undo journal. Otherwise a
            // failed import could erase unrelated pre-existing orphan bytes.
            foreach ($incoming as $id=>$meta) {
                if (isset($existing[$id])) continue;
                $candidate=$this->location('files/'.$id.'.'.$meta['extension']);
                if (file_exists($candidate) || is_link($candidate)) {
                    throw new \RuntimeException('Unindexed media path already exists');
                }
            }
            // Called with the media lock held, before any file/index mutation.
            if ($beforeChange!==null) {
                $new=[];
                foreach ($incoming as $id=>$item) if (!isset($existing[$id])) {
                    $new[$id]=['extension'=>$item['extension'],'sha256'=>$item['sha256']];
                }
                $beforeChange($this->indexBytes(),$new);
            }
            try {
                foreach ($incoming as $id=>$meta) {
                    if (isset($existing[$id])) continue;
                    $path=$this->location('files/'.$id.'.'.$meta['extension']);
                    if (file_exists($path) || is_link($path)) throw new \RuntimeException('Unindexed media path already exists');
                    OperationFiles::copy($paths[$id],$path,0600);
                    $added[]=$path;
                }
                // Keep exact index bytes (or no index at all) if site commit fails.
                return $this->indexChange($combined,$commit);
            } catch (\Throwable $failure) {
                try {
                    foreach (array_reverse($added) as $path) OperationFiles::remove($path);
                } catch (\Throwable $rollback) {
                    throw new \RuntimeException('Imported media rollback failed; recover from a full CMS backup',0,$rollback);
                }
                throw $failure;
            }
        });
    }

    /**
     * Full offline storage audit. Repair removes only well-formed, unindexed
     * private regular files; missing/corrupted indexed files are never invented
     * or deleted and must be recovered from a verified CMS backup.
     */
    public function audit(bool $pruneOrphans = false): array
    {
        return $this->locked(function () use ($pruneOrphans): array {
            $entries=$this->index();
            $expected=[]; $bytes=0;
            foreach ($entries as $id=>$meta) {
                $name=$id.'.'.$meta['extension'];
                $expected[$name]=true;
                $asset=$this->readLocked($id);
                if ($asset===null) throw new \RuntimeException('Indexed media asset is missing');
                $bytes += strlen($asset['bytes']);
            }
            $orphans=[];
            foreach (new \DirectoryIterator($this->location('files')) as $entry) {
                if ($entry->isDot()) continue;
                $name=$entry->getFilename();
                $stat=@lstat($entry->getPathname());
                if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 ||
                    $stat['nlink']!==1 || ($stat['mode'] & 0077)!==0 ||
                    (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf)$/D',$name) &&
                     !preg_match('/^\.(?:new|tmp)-[a-f0-9]{24}$/D',$name))) {
                    throw new \RuntimeException('Unsafe or unexpected media directory entry');
                }
                if (!isset($expected[$name])) $orphans[]=$entry->getPathname();
            }
            // A killed process can also leave a temporary index file beside
            // index.json. Only the application's strictly named private staging
            // files are eligible for cleanup; unknown content fails closed.
            foreach (new \DirectoryIterator($this->location('')) as $entry) {
                if ($entry->isDot() || in_array($entry->getFilename(),['files','index.json','.lock'],true)) continue;
                $stat=@lstat($entry->getPathname());
                if (!preg_match('/^\.(?:new|tmp)-[a-f0-9]{24}$/D',$entry->getFilename()) ||
                    !is_array($stat) || ($stat['mode'] & 0170000)!==0100000 ||
                    $stat['nlink']!==1 || ($stat['mode'] & 0077)!==0) {
                    throw new \RuntimeException('Unsafe or unexpected media staging entry');
                }
                $orphans[]=$entry->getPathname();
            }
            $found=count($orphans);
            if ($pruneOrphans) foreach ($orphans as $path) OperationFiles::remove($path);
            return ['indexed_files'=>count($entries),'verified_bytes'=>$bytes,
                'orphan_files'=>$found,'orphan_files_removed'=>$pruneOrphans?$found:0];
        });
    }

    /** Read verified, registered bytes; never redirect clients to a storage path. */
    public function read(string $id): ?array
    {
        // Hold one consistent index/file view across deletion and import.
        return $this->locked(fn()=> $this->readLocked($id));
    }

    private function readLocked(string $id): ?array
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
