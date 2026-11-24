<?php
declare(strict_types=1);
namespace CajeerEngine\Operations;

use CajeerEngine\AI\AIConfig;
use CajeerEngine\Auth\{AuthPolicy,OwnerIdentity,RecoveryService,SessionRegistry};
use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Storage\{SiteRepository,SiteState,BackupService};
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Real CMS operations: complete checked site+media snapshots, portable import and bounded maintenance. */
final class CmsOperations
{
    private SiteRepository $repo;
    private MediaLibrary $media;
    public function __construct(private readonly string $root)
    {
        $this->repo=new SiteRepository($root);
        $this->media=new MediaLibrary($root);
    }
    private function folder(string $name): string
    {
        $dir=OperationFiles::path($this->root,'storage/app/cms-operations/'.$name);
        OperationFiles::dir($dir,0700);
        $mode=@fileperms($dir);
        if (!is_int($mode) || ($mode & 0077)!==0) throw new \RuntimeException('CMS operations directory must be private (0700)');
        return $dir;
    }
    private function stage(): string
    {
        $root=$this->folder('tmp');
        $path=$root.'/work-'.bin2hex(random_bytes(12));
        if (!mkdir($path,0700)) throw new \RuntimeException('Cannot stage archive');
        return $path;
    }
    private static function cleanup(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) return;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $path=$entry->getPathname();
            if ($entry->isLink()) throw new \RuntimeException('Unsafe staging entry');
            if ($entry->isDir()) self::cleanup($path);
            elseif ($entry->isFile()) { if (!unlink($path)) throw new \RuntimeException('Cannot remove staging file'); }
            else throw new \RuntimeException('Unsafe staging entry');
        }
        if (!rmdir($dir)) throw new \RuntimeException('Cannot remove staging directory');
    }
    private static function json(array $data): string
    {
        return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    }
    private function backupName(): string
    {
        // Orderable within the same second: 6 hex digits of microseconds +
        // 5 random bytes. The existing 16-hex filename format is unchanged.
        $now=microtime(true);
        return 'cms-'.gmdate('Ymd\THis\Z',(int)$now).'-'.
            sprintf('%06x',(int)(($now-floor($now))*1000000)).bin2hex(random_bytes(5)).'.tar';
    }
    private function backupFile(string $name): string
    {
        if (!preg_match('/^cms-\d{8}T\d{6}Z-[a-f0-9]{16}\.tar$/D',$name)) throw new \InvalidArgumentException('Invalid CMS backup name');
        return $this->folder('backups').'/'.$name;
    }
    private static function safeFile(string $file, int $max=TarPackage::MAX_SIZE): int
    {
        $stat=@lstat($file);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 ||
            $stat['size']<1 || $stat['size']>$max) throw new \RuntimeException('Unsafe archive file');
        return $stat['size'];
    }

    /** Creates a consistent, checksummed private snapshot. Export intentionally removes login secrets. */
    private function archive(string $kind): string
    {
        if (!in_array($kind,['backup','export'],true)) throw new \InvalidArgumentException('Invalid archive kind');
        $operation=OperationLock::acquire($this->root,true);
        try {
            (new RecoveryJournal($this->root))->recover($this->repo,$this->media);
            $stage=$this->stage();
            $target=$kind==='backup' ? $this->backupFile($this->backupName()) : $stage.'/portable.tar';
        } catch (\Throwable $failure) {
            OperationLock::release($operation);
            throw $failure;
        }
        $created=false;
        // Never expose an in-progress TAR under its final backup name.
        $stagedArchive=$stage.'/complete.tar';
        try {
            $this->media->guard(function () use ($kind,$stage,$stagedArchive): void {
                $this->repo->exclusive(function () use ($kind,$stage,$stagedArchive): void {
                    $state=$this->repo->read();
                    if ($state===null) throw new \RuntimeException('Site not installed');
                    SiteState::validate($state);
                    if ($kind==='export') {
                        // Portable content is not a credential/SSO/account export.
                        $state['password']=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
                        $state['identity']=['owner'=>null,'generation'=>OwnerIdentity::generation()];
                        $state['login']=['count'=>0,'until'=>0];
                        $state['auth_policy']=AuthPolicy::DEFAULTS;
                        $state['ai']=AIConfig::DEFAULTS;
                    }
                    $files=[];
                    $site=$stage.'/site.json';
                    OperationFiles::write($site,SiteState::encode($state));
                    $files['site.json']=$site;
                    $items=[];
                    foreach ($this->media->listing() as $item) {
                        $id=$item['id'];
                        $file=$this->media->read($id);
                        if ($file===null) throw new \RuntimeException('Media disappeared during snapshot');
                        $name='media/files/'.$id.'.'.$item['extension'];
                        $path=$stage.'/'.$id.'.'.$item['extension'];
                        OperationFiles::write($path,$file['bytes']);
                        $files[$name]=$path;
                        $items[$id]=$item;
                    }
                    ksort($items,SORT_STRING);
                    $index=$stage.'/index.json';
                    OperationFiles::write($index,self::json(['version'=>1,'files'=>$items]));
                    $files['media/index.json']=$index;
                    ksort($files,SORT_STRING);
                    $checks=[];
                    foreach ($files as $name=>$file) $checks[$name]=['size'=>filesize($file),'sha256'=>OperationFiles::hash($file)];
                    $manifest=$stage.'/manifest.json';
                    OperationFiles::write($manifest,self::json(['format'=>1,'kind'=>$kind,'created_at'=>gmdate('Y-m-d\TH:i:s\Z'),'files'=>$checks]));
                    TarPackage::create($stagedArchive,['manifest.json'=>$manifest]+$files);
                });
            });
            // Verify the complete TAR on disk, including all checksums and
            // image metadata, before declaring a backup ready for restoration.
            $this->verifyFile($stagedArchive);
            // Reuse the same no-clobber, durable file publication as media imports.
            OperationFiles::copy($stagedArchive,$target,0600);
            $created=true;
            if ($kind==='backup') return basename($target);
            return $target;
        } finally {
            try {
                if (!$created || $kind==='backup') {
                    // A failed publication must not delete a file another process created.
                    self::cleanup($stage);
                }
            } finally { OperationLock::release($operation); }
        }
    }
    public function createBackup(): string { return $this->archive('backup'); }
    /** Caller must remove the private temporary export after transmission. */
    public function exportArchive(): string { return $this->archive('export'); }
    public function removeExport(string $path): void
    {
        $tmp=$this->folder('tmp');
        if (preg_match('~^'.preg_quote($tmp,'~').'/work-[a-f0-9]{24}/portable\.tar$~D',$path) && !is_link($path)) {
            self::cleanup(dirname($path));
        } else throw new \InvalidArgumentException('Invalid export path');
    }
    public function exportTo(string $destination): void
    {
        if ($destination==='' || !str_starts_with($destination,'/') || str_contains($destination,"\0") ||
            is_link($destination) || file_exists($destination)) throw new \InvalidArgumentException('Export requires a new absolute file path');
        $private=$this->exportArchive();
        try {
            $directory=dirname($destination);
            if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('Destination directory is not writable');
            OperationFiles::copy($private,$destination,0600);
        } finally { $this->removeExport($private); }
    }
    public function names(): array
    {
        $names=[];
        foreach (new \DirectoryIterator($this->folder('backups')) as $entry) {
            if ($entry->isDot()) continue;
            $name=$entry->getFilename();
            $this->backupFile($name);
            if ($entry->isLink() || !$entry->isFile()) throw new \RuntimeException('Unsafe backup directory entry');
            $names[]=$name;
        }
        rsort($names,SORT_STRING);
        return $names;
    }
    public function backupPath(string $name): string
    {
        $file=$this->backupFile($name);
        self::safeFile($file);
        $entry=@lstat($file);
        if (!is_array($entry) || ($entry['mode']&0077)!==0) throw new \RuntimeException('CMS backup must be private');
        return $file;
    }
    /** @return array{state:array,media:array,paths:array,summary:array} */
    private function inspect(string $file,string $stage): array
    {
        $package=TarPackage::unpack($file,$stage);
        $members=$package['files'];
        $state=json_decode((string)file_get_contents($members['site.json']['path']),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Invalid archived site state');
        SiteState::validate($state);
        $index=json_decode((string)file_get_contents($members['media/index.json']['path']),true,100,JSON_THROW_ON_ERROR);
        if (!is_array($index) || array_keys($index)!==['version','files'] || $index['version']!==1 ||
            !is_array($index['files']) || count($index['files'])>MediaLibrary::MAX_FILES) throw new \RuntimeException('Invalid archived media index');
        $paths=[];
        foreach ($index['files'] as $id=>$entry) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_array($entry) ||
                !is_string($entry['extension']??null)) throw new \RuntimeException('Invalid archived media item');
            $name='media/files/'.$id.'.'.$entry['extension'];
            if (!isset($members[$name])) throw new \RuntimeException('Archive media file missing');
            $paths[$id]=$members[$name]['path'];
        }
        if (count($paths)!==count($members)-3) throw new \RuntimeException('Unindexed archive media entry');
        MediaLibrary::validateArchive($index['files'],$paths);
        // Every imported asset reference must be satisfied by this self-contained bundle.
        $referenced=[];
        foreach ($state['pages'] as $page) {
            foreach (\CajeerEngine\Content\MediaReferences::ids($page['blocks']??[]) as $id) $referenced[$id]=true;
            if (($page['seo']['og_image']??'')!=='') $referenced[$page['seo']['og_image']]=true;
        }
        if (($state['seo']['default_image']??'')!=='') $referenced[$state['seo']['default_image']]=true;
        foreach ($referenced as $id=>$_) if (!isset($paths[$id])) throw new \RuntimeException('Archive has missing media references');
        return ['state'=>$state,'media'=>$index['files'],'paths'=>$paths,'summary'=>[
            'kind'=>$package['manifest']['kind'],'created_at'=>$package['manifest']['created_at'],
            'schema'=>$state['schema'],'pages'=>count($state['pages']),'media'=>count($paths),
            'bytes'=>self::safeFile($file),
        ]];
    }
    public function verifyFile(string $file): array
    {
        self::safeFile($file);
        $stage=$this->stage();
        try { return $this->inspect($file,$stage)['summary']; }
        finally { self::cleanup($stage); }
    }
    public function verifyBackup(string $name): array { return $this->verifyFile($this->backupPath($name)); }

    /** Apply verified site and ID-preserved media. Existing authentication/AI credentials always win. */
    public function importFile(string $source): string
    {
        self::safeFile($source);
        $operation=OperationLock::acquire($this->root,true);
        $journal=new RecoveryJournal($this->root);
        try { $journal->recover($this->repo,$this->media); $stage=$this->stage(); }
        catch (\Throwable $failure) { OperationLock::release($operation); throw $failure; }
        try {
            $decoded=$this->inspect($source,$stage);
            $imported=SiteState::migrate($decoded['state']);
            try {
                $this->media->importPreservingIds($decoded['media'],$decoded['paths'],function () use ($imported): void {
                $this->repo->exclusive(function () use ($imported): void {
                    $live=$this->repo->read();
                    if ($live===null) throw new \RuntimeException('Site not installed');
                    $live=SiteState::migrate($live);
                    $next=$imported;
                    foreach (['password','identity','login','auth_policy','ai'] as $key) $next[$key]=$live[$key];
                    // Never switch the administrative origin to a foreign domain during import.
                    $next['settings']['url']=$live['settings']['url'];
                    $recovery=RecoveryService::restoredCredentials($this->root,$live);
                    if ($recovery!==null) foreach ($recovery as $key=>$value) $next[$key]=$value;
                    SiteState::validate($next);
                    // Always create a recoverable pre-import copy before changing site.json.
                    (new BackupService($this->repo))->createLocked('pre-restore');
                    // The site and media transaction must roll back if session revocation
                    // fails (for instance, an unreadable registry or a full filesystem).
                    // Revocation is therefore after commit, with best-effort durable
                    // site rollback before the media library restores its previous index.
                    try {
                        $this->repo->commit($next);
                        (new SessionRegistry($this->root))->invalidateAll($next);
                    } catch (\Throwable $failure) {
                        // A failed filesystem sync can throw *after* an atomic
                        // rename has already made the new site visible. Restore
                        // the old site even when commit itself reported failure.
                        try { $this->repo->commit($live); }
                        catch (\Throwable $rollbackFailure) {
                            throw new \RuntimeException('CMS import rollback failed; restore manually from pre-restore backup',0,$rollbackFailure);
                        }
                        throw $failure;
                    }
                });
                },function (?string $beforeIndex,array $added) use ($journal): void {
                    // Capture both pre-import files while holding media -> site
                    // locks. The durable marker precedes all media/index writes.
                    $this->repo->exclusive(function () use ($journal,$beforeIndex,$added): void {
                        $old=$this->repo->raw();
                        if ($old===null) throw new \RuntimeException('Site not installed');
                        $journal->begin($old,$beforeIndex,$added);
                    });
                });
                $journal->complete();
            } catch (\Throwable $failure) {
                // This also recovers an exception thrown after rename/fsync.
                // If recovery itself fails the marker remains for the next boot.
                try { $journal->recover($this->repo,$this->media); }
                catch (\Throwable $recoveryFailure) {
                    throw new \RuntimeException('CMS import recovery failed; keep the site offline and run operations recover',0,$recoveryFailure);
                }
                throw $failure;
            }
            return 'imported';
        } finally {
            try { self::cleanup($stage); }
            finally { OperationLock::release($operation); }
        }
    }
    public function restoreBackup(string $name): string { return $this->importFile($this->backupPath($name)); }

    /** Replay the durable undo journal after an interrupted import. */
    public function recoverPending(): bool
    {
        if (!RecoveryJournal::pending($this->root)) return false;
        $operation=OperationLock::acquire($this->root,true);
        try { return (new RecoveryJournal($this->root))->recover($this->repo,$this->media); }
        finally { OperationLock::release($operation); }
    }

    /** Full audited media check or explicit orphan cleanup (no content mutation). */
    public function auditStorage(bool $pruneOrphans = false): array
    {
        $this->recoverPending();
        $guard=OperationLock::acquire($this->root,$pruneOrphans);
        try { return $this->media->audit($pruneOrphans); }
        finally { OperationLock::release($guard); }
    }

    public function health(): array
    {
        $site=$this->repo->read();
        if ($site===null) throw new \RuntimeException('Site not installed');
        $storage=OperationFiles::path($this->root,'storage');
        $stat=$this->media->statistics();
        $names=$this->names();
        $free=disk_free_space($storage);
        return ['version'=>Lite::VERSION,'schema'=>$site['schema'],'pages'=>count($site['pages']),
            'media_files'=>$stat['count'],'media_bytes'=>$stat['bytes'],'backups'=>count($names),
            'disk_free_bytes'=>is_float($free)?(int)$free:$free,
            'storage_private'=>is_dir($storage) && ((fileperms($storage)&0077)===0),
            'operations_active'=>OperationLock::maintenance($this->root),
        ];
    }
    /** Only analytics report caches are removable; auth/recovery/history/media never touched. */
    public function clearAnalyticsCache(): int
    {
        $guard=OperationLock::acquire($this->root,true);
        try {
            $dir=OperationFiles::path($this->root,'storage/analytics-cache');
            if (!file_exists($dir) && !is_link($dir)) return 0;
            if (!is_dir($dir) || is_link($dir)) throw new \RuntimeException('Unsafe analytics cache');
            $count=0;
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDot()) continue;
                if ($entry->isLink() || !$entry->isFile() || !preg_match('/^[a-f0-9]{64}\.json$/D',$entry->getFilename())) {
                    throw new \RuntimeException('Unexpected file in analytics cache');
                }
                OperationFiles::remove($entry->getPathname());$count++;
            }
            return $count;
        } finally { OperationLock::release($guard); }
    }
    public function pruneBackups(int $keep): int
    {
        if ($keep<1 || $keep>100) throw new \InvalidArgumentException('Keep must be between 1 and 100');
        $guard=OperationLock::acquire($this->root,true);
        try {
            $names=$this->names();$remove=array_slice($names,$keep);
            // Refuse destructive retention if the retained recovery points are
            // damaged: otherwise a corrupt newest archive can displace the last
            // known-good backup.
            if ($remove!==[]) foreach (array_slice($names,0,$keep) as $name) $this->verifyBackup($name);
            foreach ($remove as $name) OperationFiles::remove($this->backupFile($name));
            return count($remove);
        } finally { OperationLock::release($guard); }
    }
}
