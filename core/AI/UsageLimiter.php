<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Durable quota and estimated-spend accounting. One lock guards all requests and monthly reads. */
final class UsageLimiter
{
    private string $dir;
    private const PENDING_TTL = 1200;

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

    private function file(string $day): string
    {
        return OperationFiles::path($this->root,'storage/ai/usage-'.$day.'.json');
    }

    private function readDay(string $day): array
    {
        $file=$this->file($day);
        $base=['day'=>$day,'requests'=>0,'tokens'=>0,'pending'=>[], 'cost_microcents'=>0];
        if (!file_exists($file) && !is_link($file)) return $base;
        clearstatcache(true,$file);
        $stat=@lstat($file);
        if (!is_array($stat) || ($stat['mode']&0170000)!==0100000 || ($stat['nlink']??0)!==1 ||
            ($stat['mode']&0077)!==0 || $stat['size']>250000 || is_link($file)) {
            throw new \RuntimeException('Unsafe AI usage ledger');
        }
        $stored=OperationFiles::read($file);
        // The 1.2.7 ledger had no monetary field. Upgrade it on the next write.
        $legacy=['day','requests','tokens','pending'];
        if (!in_array(array_keys($stored),[$legacy,array_keys($base)],true) ||
            ($stored['day']??null)!==$day || !is_int($stored['requests']) ||
            $stored['requests']<0 || !is_int($stored['tokens']) || $stored['tokens']<0 ||
            !is_array($stored['pending'])) throw new \RuntimeException('Corrupt AI quota ledger');
        $stored['cost_microcents']??=0;
        if (!is_int($stored['cost_microcents']) || $stored['cost_microcents']<0) {
            throw new \RuntimeException('Corrupt AI monetary ledger');
        }
        foreach ($stored['pending'] as $id=>$reservation) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !is_array($reservation) ||
                !is_int($reservation['tokens']??null) || $reservation['tokens']<0 ||
                $reservation['tokens']>1000000 ||
                (isset($reservation['cost_microcents']) && (!is_int($reservation['cost_microcents']) || $reservation['cost_microcents']<0)) ||
                (isset($reservation['created']) && (!is_int($reservation['created']) || $reservation['created']<0))) {
                throw new \RuntimeException('Corrupt AI quota reservation');
            }
        }
        return $stored;
    }

    private function writeDay(array $data): void
    {
        OperationFiles::write($this->file($data['day']),json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n",0600);
    }

    private function settleStale(array &$data): bool
    {
        $dirty=false;
        // An interrupted worker may have reached the provider: settle conservatively.
        foreach ($data['pending'] as $id=>$reservation) {
            if (time()-(int)($reservation['created']??0)>self::PENDING_TTL) {
                $data['tokens']+=$reservation['tokens'];
                $data['cost_microcents']+=(int)($reservation['cost_microcents']??0);
                unset($data['pending'][$id]);
                $dirty=true;
            }
        }
        return $dirty;
    }

    private function monthCost(string $day): array
    {
        $month=substr($day,0,7);
        $spent=0;$pending=0;
        foreach (glob($this->dir.'/usage-'.$month.'-??.json')?:[] as $path) {
            $candidate=basename($path);
            if (!preg_match('/^usage-(20[0-9]{2}-[01][0-9]-[0-3][0-9])\.json$/D',$candidate,$m)) continue;
            $entry=$this->readDay($m[1]);
            if ($this->settleStale($entry)) $this->writeDay($entry);
            $spent+=$entry['cost_microcents'];
            $pending+=array_sum(array_column($entry['pending'],'cost_microcents'));
        }
        return [$spent,$pending];
    }

    private function withLock(callable $callback,?string $day=null): mixed
    {
        $handle=OperationLock::openLockFile(OperationFiles::path($this->root,'storage/ai/usage.lock'),0600);
        try {
            if (!flock($handle,LOCK_EX)) throw new \RuntimeException('Cannot acquire AI quota lock');
            $day??=gmdate('Y-m-d');
            if (!preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]$/D',$day)) throw new \RuntimeException('Invalid AI usage date');
            $data=$this->readDay($day);
            // Commit interrupted requests before scanning the month.
            if ($this->settleStale($data)) $this->writeDay($data);
            $result=$callback($data);
            if ($result['write']) $this->writeDay($data);
            return $result['value'];
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    public function status(): array
    {
        return $this->withLock(function(array &$data):array {
            [$monthly,$monthReserved]=$this->monthCost($data['day']);
            return ['write'=>false,'value'=>[
                'day'=>$data['day'],'requests'=>$data['requests'],'tokens'=>$data['tokens'],
                'reserved_tokens'=>array_sum(array_column($data['pending'],'tokens')),
                'active_requests'=>count($data['pending']),
                'spent_microcents'=>$data['cost_microcents'],
                'reserved_microcents'=>array_sum(array_column($data['pending'],'cost_microcents')),
                'monthly_spent_microcents'=>$monthly,'monthly_reserved_microcents'=>$monthReserved,
                'cost_policy'=>CostPolicy::load($this->root),
            ]];
        });
    }

    public function reserve(array $config,string $prompt): string
    {
        AIConfig::validate($config);
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if ($prompt==='' || strlen($prompt)>$config['max_input_chars'] ||
            !preg_match('//u',$prompt) || str_contains($prompt,"\0")) throw new \InvalidArgumentException('Invalid AI prompt');
        $reserve=strlen($prompt)+$config['max_output_tokens']+256;
        $day=gmdate('Y-m-d');
        return $day.':'.$this->withLock(function(array &$data)use($config,$reserve,$prompt):array {
            $policy=CostPolicy::load($this->root);
            $outstanding=array_sum(array_column($data['pending'],'tokens'));
            if ($data['requests'] >= $config['daily_requests'] ||
                $data['tokens']+$outstanding+$reserve > $config['daily_tokens']) {
                throw new \RuntimeException('Daily OpenAI request or token budget exhausted');
            }
            if (count($data['pending']) >= $policy['max_concurrent']) {
                throw new \RuntimeException('Concurrent OpenAI request limit reached');
            }
            $cost=0;
            if ($policy['enabled']) {
                // Input byte count plus framing is intentionally conservative for most text inputs.
                $cost=CostPolicy::estimate(strlen($prompt)+256,$config['max_output_tokens'],$policy);
                [$monthSpent,$monthPending]=$this->monthCost($data['day']);
                $dailyPending=array_sum(array_column($data['pending'],'cost_microcents'));
                if ($data['cost_microcents']+$dailyPending+$cost > $policy['daily_cents']*1000000 ||
                    $monthSpent+$monthPending+$cost > $policy['monthly_cents']*1000000) {
                    throw new \RuntimeException('OpenAI estimated monetary budget exhausted');
                }
            }
            $id=bin2hex(random_bytes(16));
            $data['pending'][$id]=['tokens'=>$reserve,'created'=>time(),
                'cost_microcents'=>$cost,'input_rate'=>$policy['enabled']?$policy['input_cents_per_million']:0,
                'output_rate'=>$policy['enabled']?$policy['output_cents_per_million']:0];
            ++$data['requests'];
            return ['write'=>true,'value'=>$id];
        },$day);
    }

    public function finish(string $reservation,?int $tokens,?array $usage=null): void
    {
        [$day,$id]=explode(':',$reservation,2)+[null,null];
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid AI reservation');
        $this->withLock(static function(array &$data)use($id,$tokens,$usage):array {
            if (!isset($data['pending'][$id])) throw new \RuntimeException('Unknown AI request reservation');
            $pending=$data['pending'][$id];
            $reserved=$pending['tokens'];
            $data['tokens']+=is_int($tokens) && $tokens>=0 ? $tokens : $reserved;
            // A failed/uncertain provider operation is charged at the full reserved estimate.
            $cost=(int)($pending['cost_microcents']??0);
            if (is_array($usage) && is_int($usage['input_tokens']??null) && is_int($usage['output_tokens']??null) &&
                $usage['input_tokens']>=0 && $usage['output_tokens']>=0) {
                $cost=CostPolicy::estimate($usage['input_tokens'],$usage['output_tokens'],[
                    'input_cents_per_million'=>(int)($pending['input_rate']??0),
                    'output_cents_per_million'=>(int)($pending['output_rate']??0),
                ]);
            }
            $data['cost_microcents']+=$cost;
            unset($data['pending'][$id]);
            return ['write'=>true,'value'=>null];
        },$day);
    }

    public function chat(OpenAIClient $client,array $config,array $messages,bool $stream=false,?callable $onDelta=null): array
    {
        $prompt=implode("\n",array_column($messages,'content'));
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if (strlen($prompt)>$config['max_input_chars']) throw new \InvalidArgumentException('Chat context exceeds limit');
        $id=$this->reserve($config,$prompt);
        $tokens=null;$usage=null;
        try {
            $result=$stream ? $client->streamChat($messages,$config,$onDelta ?? static function(string $delta):void {})
                : $client->chat($messages,$config);
            $usage=['input_tokens'=>$result['input_tokens'],'output_tokens'=>$result['output_tokens']];
            $tokens=$usage['input_tokens']+$usage['output_tokens'];
            return $result;
        } finally { $this->finish($id,$tokens,$usage); }
    }

    public function structuredContent(OpenAIClient $client,array $config,string $prompt,string $mode): array
    {
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if ($prompt==='' || strlen($prompt)>$config['max_input_chars'] ||
            !preg_match('//u',$prompt) || str_contains($prompt,"\0")) {
            throw new \InvalidArgumentException('Invalid OpenAI generation context');
        }
        $id=$this->reserve($config,$prompt);
        $tokens=null;$usage=null;
        try {
            $result=$client->structuredContent($prompt,$config,$mode);
            $usage=['input_tokens'=>$result['input_tokens'],'output_tokens'=>$result['output_tokens']];
            $tokens=$usage['input_tokens']+$usage['output_tokens'];
            return $result;
        } finally { $this->finish($id,$tokens,$usage); }
    }

    public function generate(OpenAIClient $client,array $config,string $prompt): array
    {
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if ($prompt==='' || strlen($prompt)>$config['max_input_chars'] ||
            !preg_match('//u',$prompt) || str_contains($prompt,"\0")) {
            throw new \InvalidArgumentException('Invalid OpenAI prompt');
        }
        $id=$this->reserve($config,$prompt);
        $tokens=null;$usage=null;
        try {
            $result=$client->generate($prompt,$config);
            $usage=['input_tokens'=>$result['input_tokens'],'output_tokens'=>$result['output_tokens']];
            $tokens=$usage['input_tokens']+$usage['output_tokens'];
            return $result;
        } finally { $this->finish($id,$tokens,$usage); }
    }
}
