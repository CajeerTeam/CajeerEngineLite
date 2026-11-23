<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;
use CajeerEngine\Content\Blocks;
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Durable, private, owner-scoped pre-edit snapshots. Used INSIDE site transactions. */
final class PageHistory
{
    private string $base;
    public function __construct(private readonly string $root, private readonly string $generation)
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $generation)) throw new \InvalidArgumentException('Invalid owner generation');
        foreach (['storage','storage/ai','storage/ai/page-history','storage/ai/page-history/'.$generation] as $relative) {
            $dir=OperationFiles::path($root,$relative);
            OperationFiles::dir($dir,0700);
            clearstatcache(true,$dir);
            if ((fileperms($dir)&0077)!==0 && !@chmod($dir,0700)) throw new \RuntimeException('Cannot secure page history');
            clearstatcache(true,$dir);
            if ((fileperms($dir)&0077)!==0) throw new \RuntimeException('Page history must be private');
        }
        $this->base=OperationFiles::path($root,'storage/ai/page-history/'.$generation);
    }
    private function folder(string $path): string
    {
        if ($path==='' || strlen($path)>1024 || !str_starts_with($path,'/') || str_contains($path,"\0")) {
            throw new \InvalidArgumentException('Invalid page path');
        }
        return $this->base.'/'.hash('sha256',$path);
    }
    private function locked(string $path, callable $callback): mixed
    {
        $dir=$this->folder($path);
        OperationFiles::dir($dir,0700);
        clearstatcache(true,$dir);
        if ((fileperms($dir)&0077)!==0 && !@chmod($dir,0700)) throw new \RuntimeException('Cannot secure page history');
        clearstatcache(true,$dir);
        if ((fileperms($dir)&0077)!==0) throw new \RuntimeException('Unsafe page history directory');
        $lock=OperationLock::openLockFile($dir.'/history.lock',0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock page history');
            return $callback($dir);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    private static function readFile(string $dir,string $id,string $path,string $generation): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid revision identifier');
        $file=$dir.'/'.$id.'.json';
        clearstatcache(true,$file);
        $stat=@lstat($file);
        if (!is_array($stat) || ($stat['mode']&0170000)!==0100000 || $stat['nlink']!==1 ||
            ($stat['mode']&0077)!==0 || $stat['size']>320000) throw new \RuntimeException('Page history entry missing or unsafe');
        $s=OperationFiles::read($file);
        if (array_keys($s)!==['id','created','generation','path','revision','page'] || $s['id']!==$id ||
            $s['path']!==$path || $s['generation']!==$generation ||
            !is_int($s['created']) || $s['created']<1 || $s['created']>time()+60 ||
            !is_string($s['revision']) || !preg_match('/^[a-f0-9]{64}$/D',$s['revision']) ||
            !is_array($s['page']) || !in_array(array_keys($s['page']),[['path','title','description','body','published','menu'],['path','title','description','body','published','menu','blocks']],true) ||
            $s['page']['path']!==$path || !is_bool($s['page']['published']) || !is_bool($s['page']['menu']) ||
            !hash_equals($s['revision'],PageEditor::revision($s['page']))) {
            throw new \RuntimeException('Page history is corrupt');
        }
        self::validatePage($s['page']);
        return $s;
    }

    /** Validate a historic JSON page without normalizing its untouched text. */
    private static function validatePage(array $page): void
    {
        // Reuse canonical path/flag validation, but do not require the text to
        // equal Lite::page()'s trim-normalized copy: legacy pages may preserve
        // whitespace that is valid under SiteState::validate().
        try {
            $canonical=Lite::page([...$page, 'published'=>$page['published']?'1':'',
                'menu'=>$page['menu']?'1':'']);
        } catch (\Throwable $e) { throw new \RuntimeException('Invalid saved page',0,$e); }
        if ($canonical['path']!==$page['path'] || $canonical['published']!==$page['published'] ||
            $canonical['menu']!==$page['menu']) throw new \RuntimeException('Invalid saved page');
        if (isset($page['blocks'])) {
            try {
                if (Blocks::validate($page['blocks'])!==$page['blocks'] || Blocks::plain($page['blocks'])!==$page['body']) {
                    throw new \RuntimeException('Block snapshot differs from plain text');
                }
            } catch (\InvalidArgumentException $e) { throw new \RuntimeException('Invalid block snapshot',0,$e); }
        }
        foreach (['title'=>200,'description'=>500,'body'=>60000] as $field=>$limit) {
            $value=$page[$field];
            if (!is_string($value) || strlen($value)>$limit || str_contains($value,"\0") ||
                !preg_match('//u',$value) || ($field==='title' && trim($value)==='')) {
                throw new \RuntimeException('Invalid saved page');
            }
        }
    }
    private function entries(string $dir,string $path): array
    {
        $found=[];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot() || $entry->getFilename()==='history.lock') continue;
            $name=$entry->getFilename();
            if (!preg_match('/^([a-f0-9]{32})\.json$/D',$name)) throw new \RuntimeException('Unexpected page history entry');
            $data=self::readFile($dir,substr($name,0,32),$path,$this->generation);
            $found[]=$data;
        }
        usort($found,static fn(array $a,array $b):int=>($b['created']<=>$a['created']) ?: strcmp($b['id'],$a['id']));
        return $found;
    }
    public function all(string $path): array
    {
        return $this->locked($path, fn(string $dir):array=>$this->entries($dir,$path));
    }
    public function get(string $path,string $id): array
    {
        return $this->locked($path,fn(string $dir):array=>self::readFile($dir,$id,$path,$this->generation));
    }
    /** Called before a page is modified, inside SiteRepository::transaction. Fails closed. */
    public function save(array $page): string
    {
        $path=$page['path']??null;
        if (!is_string($path) || !in_array(array_keys($page),[['path','title','description','body','published','menu'],['path','title','description','body','published','menu','blocks']],true)) {
            throw new \InvalidArgumentException('Invalid page snapshot');
        }
        self::validatePage($page);
        return $this->locked($path,function(string $dir)use($page,$path):string {
            $existing=$this->entries($dir,$path);
            $id=bin2hex(random_bytes(16));
            $data=['id'=>$id,'created'=>time(),'generation'=>$this->generation,'path'=>$path,
                'revision'=>PageEditor::revision($page),'page'=>$page];
            $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (strlen($json)>310000) throw new \RuntimeException('Page snapshot exceeds storage limit');
            OperationFiles::write($dir.'/'.$id.'.json',$json."\n",0600);
            // Cap retained versions; the latest snapshot always survives.
            if (count($existing)>=20) foreach (array_slice($existing,19) as $old) {
                OperationFiles::remove($dir.'/'.$old['id'].'.json');
            }
            return $id;
        });
    }
    public function restore(Lite $app,string $path,string $snapshotId,string $expectedRevision): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$expectedRevision)) throw new \InvalidArgumentException('Invalid current revision');
        $snapshot=$this->get($path,$snapshotId);
        $app->change(function(array $site)use($path,$snapshot,$expectedRevision):array {
            if (!hash_equals($this->generation,$site['identity']['generation'])) throw new \InvalidArgumentException('Owner identity changed');
            $current=$site['pages'][$path]??null;
            if (!is_array($current) || !hash_equals($expectedRevision,PageEditor::revision($current))) {
                throw new \InvalidArgumentException('Page changed; reload before restoring');
            }
            if ($current===$snapshot['page']) throw new \InvalidArgumentException('Page already matches the saved revision');
            $this->save($current); // restoring is itself undoable
            $site['pages'][$path]=$snapshot['page'];
            return $site;
        });
    }
}
