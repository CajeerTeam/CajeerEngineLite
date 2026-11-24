<?php
declare(strict_types=1);
namespace CajeerEngine\Operations;

use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Storage\{SiteRepository,SiteState};
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Durable undo journal for a CMS import across the site JSON and private media index. */
final class RecoveryJournal
{
    public function __construct(private readonly string $root) {}

    public static function pending(string $root): bool
    {
        $file=OperationFiles::path($root,'storage/app/cms-operations/recovery/pending.json');
        return file_exists($file) || is_link($file);
    }

    private function folder(): string
    {
        $path=OperationFiles::path($this->root,'storage/app/cms-operations/recovery');
        OperationFiles::dir($path,0700);
        clearstatcache(true,$path);
        $st=@lstat($path);
        if (!is_array($st) || ($st['mode'] & 0170000)!==0040000 || ($st['mode'] & 0077)!==0) {
            throw new \RuntimeException('Recovery journal directory must be private');
        }
        return $path;
    }

    private static function safeRead(string $path,int $max): string
    {
        $entry=@lstat($path);
        if (!is_array($entry) || ($entry['mode'] & 0170000)!==0100000 ||
            $entry['nlink']!==1 || ($entry['mode'] & 0077)!==0 ||
            $entry['size']<0 || $entry['size']>$max) throw new \RuntimeException('Unsafe recovery record');
        $h=@fopen($path,'rb');
        if (!is_resource($h)) throw new \RuntimeException('Cannot read recovery record');
        try {
            $stat=fstat($h);
            clearstatcache(true,$path);
            $now=@lstat($path);
            if (!is_array($stat) || !is_array($now) || $stat['ino']!==$now['ino'] ||
                $stat['dev']!==$now['dev'] || $stat['nlink']!==1 ||
                ($now['mode'] & 0170000)!==0100000 || ($now['mode'] & 0077)!==0) {
                throw new \RuntimeException('Recovery record changed during read');
            }
            $bytes=stream_get_contents($h,$max+1);
            if (!is_string($bytes) || strlen($bytes)!==$stat['size']) throw new \RuntimeException('Incomplete recovery record');
            return $bytes;
        } finally { fclose($h); }
    }

    /** Caller holds operation lock and media/site writer locks before first mutation. */
    public function begin(string $siteBytes,?string $indexBytes,array $added): void
    {
        if (!OperationLock::exclusiveHeld($this->root)) throw new \LogicException('Recovery journal requires exclusive operation lock');
        if (strlen($siteBytes)>SiteState::LIMIT || ($indexBytes!==null && strlen($indexBytes)>220000) ||
            count($added)>MediaLibrary::MAX_FILES) throw new \RuntimeException('Recovery snapshot exceeds limits');
        foreach ($added as $id=>$meta) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_array($meta) ||
                !is_string($meta['extension']??null) || !preg_match('/^(?:png|jpg|webp|gif|pdf)$/D',$meta['extension']) ||
                !is_string($meta['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$meta['sha256'])) {
                throw new \RuntimeException('Unsafe recovery addition');
            }
        }
        $folder=$this->folder();
        if (self::pending($this->root)) throw new \RuntimeException('Incomplete CMS import requires recovery first');
        // Snapshots left after a previously completed import can be replaced only
        // because the pending marker has already been durably removed.
        OperationFiles::write($folder.'/site.before',$siteBytes,0600);
        if ($indexBytes===null) OperationFiles::remove($folder.'/index.before');
        else OperationFiles::write($folder.'/index.before',$indexBytes,0600);
        $record=['format'=>1,'site_sha256'=>hash('sha256',$siteBytes),
            'index_present'=>$indexBytes!==null,
            'index_sha256'=>$indexBytes===null?null:hash('sha256',$indexBytes),
            'added'=>$added];
        // Publish the marker LAST: from this point normal CMS reads fail closed.
        OperationFiles::json($folder.'/pending.json',$record,0600);
    }

    /** Undo an incomplete import after worker termination, write failure or restart. */
    public function recover(SiteRepository $repo,MediaLibrary $media): bool
    {
        if (!OperationLock::exclusiveHeld($this->root)) throw new \LogicException('Recovery requires exclusive operation lock');
        if (!self::pending($this->root)) return false;
        $dir=$this->folder();
        $record=json_decode(self::safeRead($dir.'/pending.json',100_000),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_keys($record)!==['format','site_sha256','index_present','index_sha256','added'] ||
            $record['format']!==1 || !is_string($record['site_sha256']) ||
            !preg_match('/^[a-f0-9]{64}$/D',$record['site_sha256']) ||
            !is_bool($record['index_present']) || !is_array($record['added']) ||
            count($record['added'])>MediaLibrary::MAX_FILES) throw new \RuntimeException('Malformed CMS recovery journal');
        $site=self::safeRead($dir.'/site.before',SiteState::LIMIT);
        if (!hash_equals($record['site_sha256'],hash('sha256',$site))) throw new \RuntimeException('Damaged CMS site recovery copy');
        $index=null;
        if ($record['index_present']) {
            if (!is_string($record['index_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$record['index_sha256'])) {
                throw new \RuntimeException('Malformed CMS media recovery checksum');
            }
            $index=self::safeRead($dir.'/index.before',220000);
            if (!hash_equals($record['index_sha256'],hash('sha256',$index))) throw new \RuntimeException('Damaged CMS media recovery copy');
        } elseif ($record['index_sha256']!==null) throw new \RuntimeException('Unexpected media recovery checksum');
        foreach ($record['added'] as $id=>$item) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) ||
                !is_array($item) || array_keys($item)!==['extension','sha256'] ||
                !is_string($item['extension']) || !preg_match('/^(?:png|jpg|webp|gif|pdf)$/D',$item['extension']) ||
                !is_string($item['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$item['sha256'])) {
                throw new \RuntimeException('Malformed CMS media undo record');
            }
        }
        $media->guard(function () use ($repo,$media,$site,$index,$record): void {
            $repo->exclusive(function () use ($repo,$media,$site,$index,$record): void {
                // Apply undo in a repeatable order. The marker stays until every
                // operation has completed; a second interruption can retry it.
                OperationFiles::write($repo->file(),$site,0600);
                $media->restoreIndexFromRecovery($index);
                foreach ($record['added'] as $id=>$item) {
                    $path=OperationFiles::path($this->root,'storage/media/files/'.$id.'.'.$item['extension']);
                    if (!file_exists($path) && !is_link($path)) continue;
                    $st=@lstat($path);
                    if (!is_array($st) || ($st['mode'] & 0170000)!==0100000 || $st['nlink']!==1 ||
                        ($st['mode'] & 0077)!==0 || !hash_equals($item['sha256'],OperationFiles::hash($path))) {
                        throw new \RuntimeException('Recovery media asset changed; manual inspection required');
                    }
                    OperationFiles::remove($path);
                }
            });
        });
        $this->complete();
        return true;
    }

    /** Remove marker first, only after success; stale snapshots have no effect. */
    public function complete(): void
    {
        if (!OperationLock::exclusiveHeld($this->root)) throw new \LogicException('Recovery completion requires operation lock');
        $folder=$this->folder();
        OperationFiles::remove($folder.'/pending.json');
        // Leaving harmless private old snapshots is preferable to failing an
        // otherwise completed import if maintenance cleanup cannot unlink them.
        foreach (['site.before','index.before'] as $name) {
            $file=$folder.'/'.$name;
            if (is_file($file) && !is_link($file)) @unlink($file);
        }
    }
}
