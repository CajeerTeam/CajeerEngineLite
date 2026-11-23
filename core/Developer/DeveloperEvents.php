<?php
declare(strict_types=1);
namespace CajeerEngine\Developer;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Bounded, private, structured events: never accept raw exceptions, requests or credentials. */
final class DeveloperEvents
{
    private const MAX_BYTES = 262144;
    private static function filename(string $root): string
    {
        return OperationFiles::path($root,'storage/app/developer/events.jsonl');
    }

    public static function record(string $root,string $event,string $status='info'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$event) ||
            !in_array($status,['info','warn','error'],true)) throw new \InvalidArgumentException('Invalid developer event');
        $path=self::filename($root);
        OperationFiles::dir(dirname($path),0700);
        $lock=OperationLock::openLockFile($path,0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Event lock unavailable');
            $stat=fstat($lock);
            if (!is_array($stat) || ($stat['mode'] & 0077)!==0 || $stat['size']>self::MAX_BYTES) {
                throw new \RuntimeException('Event file must be private and bounded');
            }
            $entry=['at'=>gmdate('Y-m-d\TH:i:s\Z'),'id'=>bin2hex(random_bytes(6)),
                'event'=>$event,'status'=>$status];
            $line=json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            $bytes=stream_get_contents($lock);
            if (!is_string($bytes)) throw new \RuntimeException('Cannot read event log');
            if (strlen($bytes)+strlen($line)>self::MAX_BYTES) {
                // Keep only complete recent JSONL entries; never retain a fragment.
                $bytes=substr($bytes,-(int)(self::MAX_BYTES*0.7));
                $pos=strpos($bytes,"\n");
                $bytes=$pos===false?'':substr($bytes,$pos+1);
            }
            rewind($lock);
            if (!ftruncate($lock,0) || fwrite($lock,$bytes.$line)!==strlen($bytes.$line) || !fflush($lock) || !fsync($lock)) {
                throw new \RuntimeException('Cannot write event log');
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    /** @return list<array{at:string,id:string,event:string,status:string}> */
    public static function recent(string $root,int $limit=30): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Invalid event limit');
        $path=self::filename($root);
        if (!file_exists($path)) return [];
        if (is_link($path)) throw new \RuntimeException('Unsafe event file');
        $handle=OperationLock::openLockFile($path,0600);
        try {
            if (!flock($handle,LOCK_SH)) throw new \RuntimeException('Event lock unavailable');
            $stat=fstat($handle);
            if (!is_array($stat) || ($stat['mode'] & 0077)!==0 || $stat['size']>self::MAX_BYTES) {
                throw new \RuntimeException('Invalid event log permissions or size');
            }
            $raw=stream_get_contents($handle,self::MAX_BYTES+1);
            if (!is_string($raw)) throw new \RuntimeException('Cannot read events');
        } finally { flock($handle,LOCK_UN); fclose($handle); }
        $rows=[];
        foreach (array_slice(explode("\n",trim($raw)),-$limit) as $line) {
            if ($line==='') continue;
            $value=json_decode($line,true);
            if (is_array($value) && isset($value['at'],$value['id'],$value['event'],$value['status']) &&
                is_string($value['at']) && is_string($value['id']) && is_string($value['event']) && is_string($value['status'])) {
                $rows[]=$value;
            }
        }
        return array_reverse($rows);
    }
}
