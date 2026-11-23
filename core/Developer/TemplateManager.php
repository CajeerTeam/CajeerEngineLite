<?php
declare(strict_types=1);
namespace CajeerEngine\Developer;

use CajeerEngine\Lite;
use CajeerEngine\Template\{TemplateCompiler,TemplateSandbox};
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Production template maintenance: explicit enablement, CAS writes, rollback copies, no arbitrary file paths. */
final class TemplateManager
{
    private const MAX_SOURCE = 131072;
    public function __construct(private readonly string $root) {}

    public function writable(): bool
    {
        return getenv('CAJEER_DEVELOPER_TEMPLATE_WRITE')==='1' && is_writable($this->root.'/templates');
    }

    /** @return list<string> */
    public function names(): array
    {
        $directory=$this->root.'/templates';
        if (!is_dir($directory) || is_link($directory)) throw new \RuntimeException('Template directory unavailable');
        $items=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new \RuntimeException('Symlinks are not permitted in templates');
            if (!$entry->isFile() || !str_ends_with($entry->getFilename(),'.cjr')) continue;
            $relative=str_replace('\\','/',substr($entry->getPathname(),strlen($directory)+1));
            $this->path($relative);
            $items[]=$relative;
            if (count($items)>250) throw new \RuntimeException('Template list exceeds limit');
        }
        sort($items,SORT_STRING);
        return $items;
    }

    public function path(string $name): string
    {
        if (strlen($name)>160 || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.cjr$~D',$name)) {
            throw new \InvalidArgumentException('Invalid template name');
        }
        $path=OperationFiles::path($this->root,'templates/'.$name);
        (new TemplateSandbox($this->root.'/templates'))->assertTemplatePath($path);
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('Template is not a regular file');
        return $path;
    }

    /** @return array{name:string,source:string,revision:string,size:int} */
    public function read(string $name): array
    {
        $path=$this->path($name);
        if (filesize($path)>self::MAX_SOURCE) throw new \RuntimeException('Template exceeds 128 KiB');
        $source=file_get_contents($path);
        if (!is_string($source) || strlen($source)>self::MAX_SOURCE) throw new \RuntimeException('Cannot read template');
        return ['name'=>$name,'source'=>$source,'revision'=>hash('sha256',$source),'size'=>strlen($source)];
    }

    public function validateSource(string $source): void
    {
        if ($source==='' || strlen($source)>self::MAX_SOURCE || str_contains($source,"\0") || !preg_match('//u',$source)) {
            throw new \InvalidArgumentException('Template must be valid UTF-8 (1–131072 bytes)');
        }
        $compiled=(new TemplateCompiler())->compileDocument($source);
        // Parse the generated PHP syntax WITHOUT evaluating the source or running code.
        token_get_all('?>'.$compiled['template'],TOKEN_PARSE);
    }

    private function backupDirectory(string $name): string
    {
        return OperationFiles::path($this->root,'storage/app/developer/template-history/'.hash('sha256',$name));
    }

    /** @return list<string> */
    public function history(string $name): array
    {
        $this->path($name);
        $directory=$this->backupDirectory($name);
        if (!file_exists($directory)) return [];
        if (!is_dir($directory) || is_link($directory)) throw new \RuntimeException('Unsafe template history');
        $names=[];
        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot()) continue;
            $filename=$item->getFilename();
            if ($item->isLink() || !$item->isFile() || !preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{16}\.cjr$/D',$filename)) {
                throw new \RuntimeException('Unexpected template history entry');
            }
            $names[]=$filename;
        }
        rsort($names,SORT_STRING);
        return $names;
    }

    private function snapshot(string $name,string $source): void
    {
        $directory=$this->backupDirectory($name);
        OperationFiles::dir($directory,0700);
        $file=$directory.'/'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8)).'.cjr';
        OperationFiles::write($file,$source,0600);
        // Keep the latest twenty full copies. No partial or diff-only backups.
        foreach (array_slice($this->history($name),20) as $old) OperationFiles::remove($directory.'/'.$old);
    }

    private function putLocked(string $name,string $source,string $revision): void
    {
        $current=$this->read($name);
        if (!preg_match('/^[a-f0-9]{64}$/D',$revision) || !hash_equals($current['revision'],$revision)) {
            throw new \InvalidArgumentException('Template changed since opening; reload it before saving');
        }
        if ($source===$current['source']) return;
        $this->validateSource($source);
        $path=$this->path($name);
        if (!is_writable($path)) throw new \RuntimeException('Template file is read-only');
        $mode=fileperms($path);
        if ($mode===false) throw new \RuntimeException('Cannot read template permissions');
        $this->snapshot($name,$current['source']);
        OperationFiles::write($path,$source,$mode & 0777,fileowner($path)?:null,filegroup($path)?:null);
    }

    public function save(string $name,string $source,string $revision): void
    {
        if (!$this->writable()) throw new \RuntimeException('Template writes require CAJEER_DEVELOPER_TEMPLATE_WRITE=1 and filesystem access');
        $lock=OperationLock::openLockFile(OperationFiles::path($this->root,'storage/app/developer/template.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Template lock unavailable');
            $this->putLocked($name,$source,$revision);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function restore(string $name,string $backup,string $revision): void
    {
        if (!$this->writable()) throw new \RuntimeException('Template writes not enabled');
        if (!preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{16}\.cjr$/D',$backup)) throw new \InvalidArgumentException('Invalid backup ID');
        $lock=OperationLock::openLockFile(OperationFiles::path($this->root,'storage/app/developer/template.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Template lock unavailable');
            $this->path($name);
            $file=$this->backupDirectory($name).'/'.$backup;
            if (is_link($file) || !is_file($file) || !in_array($backup,$this->history($name),true)) {
                throw new \InvalidArgumentException('Backup unavailable');
            }
            $source=file_get_contents($file);
            if (!is_string($source) || strlen($source)>self::MAX_SOURCE) throw new \RuntimeException('Invalid template history data');
            $this->putLocked($name,$source,$revision);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function preview(Lite $app,array $site,string $name,string $source,string $page): string
    {
        $this->validateSource($source);
        $path=$this->path($name);
        if (!isset($site['pages'][$page]) || !$site['pages'][$page]['published']) {
            throw new \InvalidArgumentException('Preview requires a published page');
        }
        return $app->render($site,$site['pages'][$page],[$path=>$source]);
    }
}
