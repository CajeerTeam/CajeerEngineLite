<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

/** Read-only, bounded update alerts derived from the validated control mailbox. */
final class UpdateMonitoring
{
    public static function report(array $state, ?int $now=null): array
    {
        $now??=time();$alerts=[];
        $add=static function(string $id,string $severity)use(&$alerts):void{$alerts[]=['id'=>$id,'severity'=>$severity];};
        $heartbeat=$state['scheduler'];$age=$heartbeat===null?null:$now-($heartbeat['finished_at']??$heartbeat['started_at']);
        if($state['pending'])$add('recovery_required','error');
        if(($state['settings']['checks']||$state['job']!==null)&&($age===null||$age<0||$age>900))$add('scheduler_stale','error');
        if(in_array($heartbeat['action']??null,['failed','recovery_required','interrupted'],true))$add('scheduler_failure','error');
        $latest=[];foreach(array_reverse($state['history']) as $row)if(!isset($latest[$row['type']]))$latest[$row['type']]=$row;
        foreach($latest as $type=>$row)if(in_array($row['result'],['failed','interrupted','expired'],true))$add($type.'_failure','error');
        $job=$state['job'];
        if($job!==null&&($job['at']>$now||$now-$job['at']>86400))$add('job_expired','error');
        if($state['fresh']&&($state['check']['verified']??null)!==null)$add('update_available','info');
        if($state['settings']['checks']&&$state['last_attempt']>0&&$now-$state['last_attempt']>$state['settings']['interval']*3600+1800)$add('check_overdue','warning');
        // The independent, root-owned supervisor uses one host-wide state directory.
        // Only the presence of its hold is reported, not its contents.
        if(file_exists('/var/lib/cajeer-maintenance/active')||is_link('/var/lib/cajeer-maintenance/active'))$add('supervisor_maintenance','warning');
        if(file_exists('/var/lib/cajeer-maintenance/hold')||is_link('/var/lib/cajeer-maintenance/hold'))$add('supervisor_hold','error');
        return ['ok'=>!array_filter($alerts,static fn($a)=>$a['severity']==='error'),'alerts'=>$alerts,'heartbeat_age_seconds'=>$age,'last_results'=>$latest];
    }
}
