<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Durable, locked daily request/token accounting; pessimistic for interrupted requests. */
final class UsageLimiter
{
    private string $dir;
    public function __construct(private readonly string $root)
    {
        $this->dir=OperationFiles::path($root,'storage/ai');
        OperationFiles::dir(OperationFiles::path($root,'storage'),0700);
        OperationFiles::dir($this->dir,0700);
        foreach ([$root.'/storage',$this->dir] as $dir) {
            if ((fileperms($dir)&0077)!==0 && !@chmod($dir,0700)) throw new \RuntimeException('Cannot secure AI storage');
            clearstatcache(true,$dir);
            if ((fileperms($dir)&0077)!==0) throw new \RuntimeException('AI storage must be private');
        }
    }

    private function file(string $day): string { return OperationFiles::path($this->root,'storage/ai/usage-'.$day.'.json'); }

    private function withLock(callable $callback,?string $day=null): mixed
    {
        $handle=OperationLock::openLockFile(OperationFiles::path($this->root,'storage/ai/usage.lock'),0600);
        try {
            if (!flock($handle,LOCK_EX)) throw new \RuntimeException('Cannot acquire AI quota lock');
            $day??=gmdate('Y-m-d');
            if (!preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]$/D',$day)) throw new \RuntimeException('Invalid AI usage date');
            $file=$this->file($day);
            if (is_link($file)) throw new \RuntimeException('Unsafe AI usage file');
            $data=['day'=>$day,'requests'=>0,'tokens'=>0,'pending'=>[]];
            if (is_file($file)) {
                if (!is_file($file) || filesize($file)>250000 || (lstat($file)['nlink']??0)!==1 ||
                    ((lstat($file)['mode']??0)&0170000)!==0100000) throw new \RuntimeException('Unsafe AI usage ledger');
                $stored=OperationFiles::read($file);
                if (!is_array($stored) || array_keys($stored)!==array_keys($data) || $stored['day']!==$data['day'] ||
                    !is_int($stored['requests']) || $stored['requests']<0 ||
                    !is_int($stored['tokens']) || $stored['tokens']<0 || !is_array($stored['pending'])) {
                    throw new \RuntimeException('Corrupt AI quota ledger');
                }
                foreach ($stored['pending'] as $id=>$reservation) {
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) ||
                        !is_array($reservation) || !is_int($reservation['tokens']??null) ||
                        $reservation['tokens']<0 || $reservation['tokens']>1000000) {
                        throw new \RuntimeException('Corrupt AI quota reservation');
                    }
                }
                $data=$stored;
            }
            $result=$callback($data);
            if ($result['write']) {
                OperationFiles::write($file,json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n",0600);
            }
            return $result['value'];
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    public function status(): array
    {
        return $this->withLock(static fn(array &$data):array=>['write'=>false,'value'=>[
            'day'=>$data['day'],'requests'=>$data['requests'],'tokens'=>$data['tokens'],
            'reserved_tokens'=>array_sum(array_column($data['pending'],'tokens'))]]);
    }

    public function reserve(array $config,string $prompt): string
    {
        AIConfig::validate($config);
        // UTF-8 payload bytes are a conservative upper bound on encoded input tokens;
        // reserve full max_output_tokens plus response framing overhead.
        $reserve=strlen($prompt)+$config['max_output_tokens']+256;
        $day=gmdate('Y-m-d');
        return $day.':'.$this->withLock(static function(array &$data)use($config,$reserve):array {
            $outstanding=array_sum(array_column($data['pending'],'tokens'));
            if ($data['requests'] >= $config['daily_requests'] ||
                $data['tokens']+$outstanding+$reserve > $config['daily_tokens']) {
                throw new \RuntimeException('Daily OpenAI request or token budget exhausted');
            }
            $id=bin2hex(random_bytes(16));
            $data['pending'][$id]=['tokens'=>$reserve];
            ++$data['requests'];
            return ['write'=>true,'value'=>$id];
        },$day);
    }

    public function finish(string $id,?int $tokens): void
    {
        [$day,$id]=explode(':',$id,2)+[null,null];
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid AI reservation');
        $this->withLock(static function(array &$data)use($id,$tokens):array {
            if (!isset($data['pending'][$id])) throw new \RuntimeException('Unknown AI request reservation');
            $reserved=$data['pending'][$id]['tokens'];
            // Even a transport failure could have incurred charges. Count full
            // reservation if usage is unknown rather than reopening the budget.
            $data['tokens']+=is_int($tokens) && $tokens>=0 ? $tokens : $reserved;
            unset($data['pending'][$id]);
            return ['write'=>true,'value'=>null];
        },$day);
    }

    /** Multi-turn generation with the same durable quota accounting as test generations. */
    public function chat(OpenAIClient $client,array $config,array $messages,bool $stream=false,?callable $onDelta=null): array
    {
        $prompt=implode("\n",array_column($messages,'content'));
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if (strlen($prompt)>$config['max_input_chars']) throw new \InvalidArgumentException('Chat context exceeds limit');
        $id=$this->reserve($config,$prompt);
        $tokens=null;
        try {
            $result=$stream ? $client->streamChat($messages,$config,$onDelta ?? static function(string $delta):void {})
                : $client->chat($messages,$config);
            $tokens=$result['input_tokens']+$result['output_tokens'];
            return $result;
        } finally { $this->finish($id,$tokens); }
    }

    public function generate(OpenAIClient $client,array $config,string $prompt): array
    {
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if ($prompt==='' || strlen($prompt)>$config['max_input_chars'] ||
            !preg_match('//u',$prompt) || str_contains($prompt,"\0")) {
            throw new \InvalidArgumentException('Invalid OpenAI prompt');
        }
        $id=$this->reserve($config,$prompt);
        $tokens=null;
        try {
            $result=$client->generate($prompt,$config);
            $tokens=$result['input_tokens']+$result['output_tokens'];
            return $result;
        } finally { $this->finish($id,$tokens); }
    }
}
