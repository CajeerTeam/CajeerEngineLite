<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Private, owner-generation-scoped, atomic JSON conversations. No provider keys are stored. */
final class ConversationStore
{
    private string $dir;
    private string $lockPath;
    public function __construct(private readonly string $root, private readonly string $generation)
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $generation)) throw new \InvalidArgumentException('Invalid owner generation');
        $base=OperationFiles::path($root, 'storage/ai/conversations');
        $this->dir=OperationFiles::path($root, 'storage/ai/conversations/'.$generation);
        foreach (['storage','storage/ai','storage/ai/conversations','storage/ai/conversations/'.$generation] as $part) {
            $path=OperationFiles::path($root,$part);
            OperationFiles::dir($path,0700);
            clearstatcache(true,$path);
            if ((fileperms($path)&0077)!==0 && !@chmod($path,0700)) throw new \RuntimeException('Cannot secure conversation storage');
            clearstatcache(true,$path);
            if ((fileperms($path)&0077)!==0) throw new \RuntimeException('Conversation storage must be private');
        }
        $this->lockPath=OperationFiles::path($root,'storage/ai/conversations/'.$generation.'/chat.lock');
    }

    private static function id(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid conversation ID');
    }
    private function file(string $id): string
    {
        self::id($id);
        return OperationFiles::path($this->root,'storage/ai/conversations/'.$this->generation.'/'.$id.'.json');
    }
    private function locked(callable $run): mixed
    {
        $h=OperationLock::openLockFile($this->lockPath,0600);
        try {
            if (!flock($h,LOCK_EX)) throw new \RuntimeException('Conversation storage is busy');
            return $run();
        } finally { flock($h,LOCK_UN); fclose($h); }
    }
    private function read(string $id): array
    {
        $path=$this->file($id);
        clearstatcache(true,$path);
        $meta=@lstat($path);
        if (!is_array($meta) || ($meta['mode']&0170000)!==0100000 || $meta['nlink']!==1 ||
            ($meta['mode']&0077)!==0 || $meta['size']>2000000) {
            throw new \RuntimeException('Conversation missing or unsafe');
        }
        $data=OperationFiles::read($path);
        if (array_keys($data)!==['id','title','created','updated','messages','pending'] ||
            $data['id']!==$id || !is_string($data['title']) || strlen($data['title'])>160 ||
            !is_int($data['created']) || !is_int($data['updated']) || !is_array($data['messages']) ||
            count($data['messages'])>80 || !array_is_list($data['messages'])) {
            throw new \RuntimeException('Invalid conversation data');
        }
        foreach ($data['messages'] as $message) {
            if (!is_array($message) || array_keys($message)!==['role','content','at'] ||
                !in_array($message['role'],['user','assistant'],true) || !is_string($message['content']) ||
                strlen($message['content'])>100000 || !preg_match('//u',$message['content']) ||
                !is_int($message['at'])) throw new \RuntimeException('Invalid conversation message');
        }
        $pending=$data['pending'];
        if ($pending!==null && (!is_array($pending) || array_keys($pending)!==['id','started'] ||
            !is_string($pending['id']) || !preg_match('/^[a-f0-9]{32}$/D',$pending['id']) ||
            !is_int($pending['started']))) throw new \RuntimeException('Invalid generation state');
        return $data;
    }
    private function write(array $chat): void
    {
        $json=json_encode($chat,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>1800000) throw new \RuntimeException('Conversation storage limit reached');
        OperationFiles::write($this->file($chat['id']),$json."\n",0600);
    }
    public function create(): array
    {
        return $this->locked(function():array {
            if (count(glob($this->dir.'/*.json')?:[])>=30) throw new \RuntimeException('Conversation limit reached; delete an old conversation');
            $now=time();
            $chat=['id'=>bin2hex(random_bytes(16)),'title'=>'Новый диалог','created'=>$now,
                'updated'=>$now,'messages'=>[],'pending'=>null];
            $this->write($chat);
            return $chat;
        });
    }
    public function get(string $id): array { return $this->locked(fn():array=>$this->read($id)); }
    public function all(): array
    {
        return $this->locked(function():array {
            $items=[];
            foreach (new \DirectoryIterator($this->dir) as $entry) {
                if ($entry->isDot() || !str_ends_with($entry->getFilename(),'.json')) continue;
                $id=substr($entry->getFilename(),0,-5);
                $chat=$this->read($id);
                $items[]=['id'=>$chat['id'],'title'=>$chat['title'],'updated'=>$chat['updated']];
            }
            usort($items,static fn(array $a,array $b):int=>$b['updated']<=>$a['updated']);
            return $items;
        });
    }
    public function delete(string $id): void
    {
        $this->locked(function() use($id):void {
            $chat=$this->read($id);
            OperationFiles::remove($this->file($chat['id']));
        });
    }
    public function clear(): void
    {
        $this->locked(function():void {
            foreach (new \DirectoryIterator($this->dir) as $entry) {
                if ($entry->isDot() || !str_ends_with($entry->getFilename(),'.json')) continue;
                $this->read(substr($entry->getFilename(),0,-5));
                OperationFiles::remove($entry->getPathname());
            }
        });
    }
    public function begin(string $id,string $message,int $maxChars): array
    {
        if ($message==='' || strlen($message)>$maxChars || !preg_match('//u',$message) ||
            str_contains($message,"\0") || trim($message)==='') throw new \InvalidArgumentException('Invalid chat message');
        return $this->locked(function()use($id,$message,$maxChars):array {
            $chat=$this->read($id);
            if ($chat['pending']!==null) {
                if (time()-$chat['pending']['started']<150) throw new \RuntimeException('A reply is still being generated');
                // Stale workers must not append a second assistant message after recovery.
                if (($chat['messages'][array_key_last($chat['messages'])]['role']??null)==='user') array_pop($chat['messages']);
                $chat['pending']=null;
            }
            $chat['messages'][]=['role'=>'user','content'=>$message,'at'=>time()];
            $chat['messages']=array_slice($chat['messages'],-78);
            if ($chat['title']==='Новый диалог') {
                $clean=preg_replace('/\s+/u',' ',trim($message))??'Новый диалог';
                $parts=preg_split('//u',$clean,-1,PREG_SPLIT_NO_EMPTY);
                $chat['title']=implode('',array_slice($parts?:[],0,55));
            }
            $token=bin2hex(random_bytes(16));
            $chat['pending']=['id'=>$token,'started'=>time()];
            $chat['updated']=time();
            // Keep the most recent complete suffix within the configured byte limit.
            $context=[];$length=0;
            foreach (array_reverse($chat['messages']) as $part) {
                $bytes=strlen($part['content']);
                if ($length+$bytes+count($context)>$maxChars) break;
                array_unshift($context,['role'=>$part['role'],'content'=>$part['content']]);
                $length+=$bytes;
            }
            if (!$context || end($context)['role']!=='user') throw new \RuntimeException('Cannot prepare chat context');
            $this->write($chat);
            return ['token'=>$token,'messages'=>$context,'prompt'=>implode("\n",array_column($context,'content'))];
        });
    }
    public function active(string $id,string $token): bool
    {
        return $this->locked(function()use($id,$token):bool {
            try { $chat=$this->read($id); } catch (\RuntimeException) { return false; }
            return ($chat['pending']['id']??null)===$token;
        });
    }
    public function complete(string $id,string $token,string $text): void
    {
        if ($text==='' || strlen($text)>100000 || !preg_match('//u',$text)) throw new \RuntimeException('Invalid assistant response');
        $this->locked(function()use($id,$token,$text):void {
            $chat=$this->read($id);
            if (($chat['pending']['id']??null)!==$token) throw new \RuntimeException('Generation was cancelled');
            $chat['messages'][]=['role'=>'assistant','content'=>$text,'at'=>time()];
            $chat['messages']=array_slice($chat['messages'],-80);
            $chat['pending']=null;$chat['updated']=time();$this->write($chat);
        });
    }
    public function cancel(string $id,?string $token=null): void
    {
        $this->locked(function()use($id,$token):void {
            $chat=$this->read($id);
            if ($chat['pending']===null) return;
            if ($token!==null && $chat['pending']['id']!==$token) return;
            if (($chat['messages'][array_key_last($chat['messages'])]['role']??null)==='user') array_pop($chat['messages']);
            $chat['pending']=null;$chat['updated']=time();$this->write($chat);
        });
    }
}
