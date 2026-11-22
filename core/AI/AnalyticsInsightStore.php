<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\OperationFiles;

/** Persist only model interpretation (not the source analytics report) with owner and snapshot isolation. */
final class AnalyticsInsightStore
{
    private string $dir;
    public function __construct(private readonly string $root,private readonly string $ownerGeneration)
    {
        if (!preg_match('/^[a-f0-9]{32,128}$/D',$ownerGeneration)) throw new \RuntimeException('Invalid owner generation');
        $this->dir=OperationFiles::path($root,'storage/ai/analytics-insights');
        OperationFiles::dir($this->dir,0700);
        if ((fileperms($this->dir)&0077)!==0 && !@chmod($this->dir,0700)) throw new \RuntimeException('Unsafe AI insight directory');
    }
    private function path(array $report): string
    {
        $hash=hash('sha256',$this->ownerGeneration.':'.AnalyticsInsights::fingerprint($report));
        return OperationFiles::path($this->root,'storage/ai/analytics-insights/'.$hash.'.json');
    }
    public function read(array $report): ?array
    {
        $path=$this->path($report);
        if (!file_exists($path) && !is_link($path)) return null;
        $st=@lstat($path);
        if (!is_array($st) || ($st['mode']&0170000)!==0100000 || ($st['mode']&0077)!==0 ||
            $st['nlink']!==1 || $st['size']>16000) throw new \RuntimeException('Unsafe AI insights file');
        $data=OperationFiles::read($path);
        if (!is_string($data['fingerprint']??null) ||
            !hash_equals(AnalyticsInsights::fingerprint($report),$data['fingerprint']) ||
            !is_int($data['created']??null) || $data['created']>time() || time()-$data['created']>30*86400 ||
            !is_array($data['fields']??null) || array_keys($data['fields'])!==['summary','hypotheses','recommendations','limitations']) {
            throw new \RuntimeException('Invalid stored AI insights');
        }
        foreach ($data['fields'] as $v) if (!is_string($v) || strlen($v)>4000 || !preg_match('//u',$v)) {
            throw new \RuntimeException('Invalid stored AI insight text');
        }
        return $data;
    }
    public function save(array $report,array $result): void
    {
        if (!is_string($result['fingerprint']??null) ||
            !hash_equals(AnalyticsInsights::fingerprint($report),$result['fingerprint'])) {
            throw new \InvalidArgumentException('Insight/report mismatch');
        }
        $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>16000) throw new \RuntimeException('AI insight result exceeds limit');
        OperationFiles::write($this->path($report),$json."\n",0600);
    }
    public function delete(array $report): void { OperationFiles::remove($this->path($report)); }
}
