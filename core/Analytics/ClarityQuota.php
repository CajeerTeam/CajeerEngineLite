<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;
use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Conservative per-project daily export budget; Microsoft permits at most 10/day. */
final class ClarityQuota
{
    public static function charge(string $root, string $projectId): void
    {
        if (!preg_match('/^[a-z0-9]{1,40}$/D',$projectId)) {
            throw new \RuntimeException('Invalid Clarity project for API budget');
        }
        $relative='storage/analytics-cache/clarity-budget-'.hash('sha256',$projectId);
        $path=OperationFiles::path($root,$relative.'.json');
        $dir=dirname($path);
        OperationFiles::dir($dir,0700);
        $perms=@fileperms($dir);
        if ($perms===false || (($perms&0077)!==0 && !@chmod($dir,0700))) {
            throw new \RuntimeException('Clarity quota storage must be private');
        }
        $lock=OperationLock::openLockFile(OperationFiles::path($root,$relative.'.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock Clarity API quota');
            $today=gmdate('Y-m-d'); $count=0;
            if (is_link($path)) throw new \RuntimeException('Unsafe Clarity budget file');
            if (is_file($path)) {
                if (filesize($path)>1024 || (fileperms($path)&0077)!==0) {
                    throw new \RuntimeException('Unsafe Clarity budget file');
                }
                $previous=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
                if (!is_array($previous) || !is_string($previous['date']??null) ||
                    !is_int($previous['count']??null) || $previous['count']<0 || $previous['count']>10) {
                    throw new \RuntimeException('Invalid Clarity quota state');
                }
                if ($previous['date']===$today) $count=$previous['count'];
            }
            // Reserve 2 calls for support/external tools so Lite cannot exhaust 10/day.
            if ($count>=8) throw new \RuntimeException('Clarity daily export budget reached (8 requests; retry tomorrow UTC)');
            OperationFiles::write($path,json_encode(['date'=>$today,'count'=>$count+1],JSON_THROW_ON_ERROR)."\n",0600);
        } finally {
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
}
