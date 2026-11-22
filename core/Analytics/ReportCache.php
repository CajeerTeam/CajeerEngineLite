<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;
use CajeerEngine\Update\OperationFiles;

/** Private atomic, short-lived snapshots; no credentials or raw personal analytics are persisted. */
final class ReportCache
{
    public function __construct(private readonly string $root) {}
    private function filename(array $site, int $days): string
    {
        $key=hash('sha256',json_encode([$site['settings']['url'],$site['reporting'],$site['integrations'],$days],JSON_THROW_ON_ERROR));
        return OperationFiles::path($this->root,'storage/analytics-cache/'.$key.'.json');
    }
    public function read(array $site, int $days): ?array
    {
        ReportService::days($days);
        $path=$this->filename($site,$days);
        if (!file_exists($path) && !is_link($path)) return null;
        $stat=@lstat($path);
        if (!is_array($stat) || ($stat['mode']&0170000)!==0100000 || $stat['nlink']!==1 || ($stat['mode']&0077)!==0 ||
            ($stat['size']??0)>131072) throw new \RuntimeException('Unsafe analytics cache');
        $raw=file_get_contents($path);
        $data=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_int($data['stored_at']??null) || !is_array($data['report']??null)) {
            throw new \RuntimeException('Corrupt analytics cache');
        }
        $report=$data['report'];
        $report['cache_age_seconds']=max(0,time()-$data['stored_at']);
        $report['stale']=$report['cache_age_seconds']>900;
        return $report;
    }
    public function save(array $site,int $days,array $report): void
    {
        $path=$this->filename($site,$days);
        $parent=dirname($path);
        OperationFiles::dir($parent,0700);
        $mode=@fileperms($parent);
        if ($mode===false || (($mode & 0077)!==0 && !@chmod($parent,0700))) {
            throw new \RuntimeException('Analytics cache directory must be private');
        }
        $json=json_encode(['stored_at'=>time(),'report'=>$report],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>131072) throw new \RuntimeException('Analytics cache report too large');
        OperationFiles::write($path,$json."\n",0600);
    }
}
