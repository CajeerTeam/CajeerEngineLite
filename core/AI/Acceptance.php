<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Storage\SiteState;

/** Read-only release checks against an installed Lite site; never performs a paid request. */
final class Acceptance
{
    /** @return array{version:string,schema:int,automated_ok:bool,production_accepted:bool,checks:array,manual:array} */
    public static function run(string $root,array $site,bool $live=false): array
    {
        $checks=[];$ok=true;
        $add=static function(string $id,string $status,string $message)use(&$checks,&$ok):void {
            if ($status==='fail') $ok=false;
            $checks[]=compact('id','status','message');
        };
        try {
            SiteState::validate($site);
            if ($site['schema']!==SiteState::SCHEMA) throw new \RuntimeException('Schema migration pending');
            $add('site_schema','pass','Current JSON schema validated');
        } catch (\Throwable) {
            $add('site_schema','fail','Site schema invalid or migration required');
            return self::result($site,$checks,false);
        }
        $add('integration',$site['ai']['enabled']?'pass':'fail',
            $site['ai']['enabled']?'OpenAI enabled':'Enable OpenAI in the admin panel');
        $add('https',str_starts_with($site['settings']['url'],'https://')?'pass':'fail',
            'Production AI administration requires a HTTPS origin');
        $runtime=extension_loaded('openssl') && filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN);
        $add('transport',$runtime?'pass':'fail','OpenSSL and allow_url_fopen required for TLS API requests');
        $key=OpenAIClient::configured();
        $add('credentials',$key?'pass':'fail',$key?'Server-side API key configured':'Set CAJEER_OPENAI_API_KEY on the server');
        try {
            $policy=CostPolicy::load($root);
            $add('cost_policy',$policy['enabled']?'pass':'warn',
                $policy['enabled']?'Monetary cost reservation enabled':'Monetary budgets disabled; enable before production');
            $usage=(new UsageLimiter($root))->status();
            $healthy=$usage['active_requests']<=$policy['max_concurrent'] &&
                $usage['requests']<=$site['ai']['daily_requests'] &&
                $usage['tokens']>=0 && $usage['monthly_spent_microcents']>=0;
            $add('usage_ledger',$healthy?'pass':'fail','Persistent AI usage ledger and concurrency inspected');
        } catch (\Throwable) {
            $add('usage_ledger','fail','AI cost policy or usage ledger unsafe/unreadable');
        }
        foreach (['storage'=>'storage','ai_storage'=>'storage/ai'] as $id=>$relative) {
            $path=$root.'/'.$relative;clearstatcache(true,$path);$st=@lstat($path);
            $valid=is_array($st) && ($st['mode']&0170000)===0040000 && ($st['mode']&0077)===0;
            $add($id,$valid?'pass':'fail','Private directory required (0700, no symlink)');
        }
        // Refuse unsafe entries before they can be followed by feature-specific stores.
        $dir=$root.'/storage/ai';
        if (is_dir($dir) && !is_link($dir)) {
            $unsafe=false;$inspected=0;
            try {
                $iter=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iter as $item) {
                    if (++$inspected>5000 || $item->isLink()) {$unsafe=true;break;}
                    $st=@lstat($item->getPathname());
                    if (!is_array($st) || ($st['mode']&0077)!==0 ||
                        !in_array($st['mode']&0170000,[0040000,0100000],true) ||
                        (($st['mode']&0170000)===0100000 && $st['nlink']!==1)) {$unsafe=true;break;}
                }
            } catch (\Throwable) {$unsafe=true;}
            $add('ai_files',$unsafe?'fail':'pass','AI files and directories are private, regular and not linked');
        }
        if ($live) {
            try {
                if (!$key) throw new \RuntimeException('Missing key');
                $models=(new OpenAIClient())->models();
                $available=in_array($site['ai']['model'],$models,true);
                $add('live_model',$available?'pass':'fail',
                    $available?'Configured model appears in live project models':'Configured model unavailable in live project');
            } catch (\Throwable) {
                $add('live_model','fail','OpenAI /v1/models probe failed (without a paid generation)');
            }
        } else $add('live_model','warn','Run with --live for a read-only project model probe');
        return self::result($site,$checks,$ok);
    }

    private static function result(array $site,array $checks,bool $ok): array
    {
        return ['version'=>\CajeerEngine\Lite::VERSION,'schema'=>is_int($site['schema']??null)?$site['schema']:0,
            'automated_ok'=>$ok,'production_accepted'=>false,'checks'=>$checks,
            'manual'=>[
                'Verify a paid Responses API generation and structured output with the production project and billing.',
                'Verify streaming, cancellation, 429/5xx handling and complete usage accounting on staging.',
                'Exercise draft creation, approval, revision conflicts and page rollback with real content.',
                'Verify real analytics provider data, cookie consent and SkiF4er ID sessions after upgrade.',
                'Compare the estimated AI budget against actual OpenAI billing and periodically refresh model rates.',
            ]];
    }
}
