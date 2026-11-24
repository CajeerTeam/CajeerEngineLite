<?php
declare(strict_types=1);
namespace CajeerEngine\Release;

use CajeerEngine\Lite;
use CajeerEngine\Security\SecurityAudit;
use CajeerEngine\Compatibility\CompatibilityAssurance;
use CajeerEngine\Monitoring\OperationalHealth;
use CajeerEngine\Update\UpdateEngine;
use CajeerEngine\AI\{Acceptance,ProductionAIAcceptance};
use CajeerEngine\Auth\StableAcceptance;
use CajeerEngine\Analytics\{HttpClient,ReportService};

/** Operator-only staging gate. No network or billable request without explicit flags. */
final class ReleaseCandidate
{
    public const PROVIDERS=['oidc','openai','ga4','metrica','matomo','clarity','gsc','yandex_webmaster'];

    public static function run(string $root,array $site,bool $live=false,array $providers=[],bool $paid=false): array
    {
        if (array_diff($providers,self::PROVIDERS) || count($providers)!==count(array_unique($providers)) ||
            ($providers!==[] && !$live) || ($paid && !in_array('openai',$providers,true))) {
            throw new \InvalidArgumentException('Invalid RC provider selection; use --live for provider probes');
        }
        if ($live && getenv('CAJEER_RC_STAGING_CONFIRM')!=='STAGING') {
            throw new \InvalidArgumentException('Live RC probes require CAJEER_RC_STAGING_CONFIRM=STAGING');
        }
        if ($paid && getenv('CAJEER_AI_PAID_PROBE_CONFIRM')!=='I_ACCEPT_API_CHARGES') {
            throw new \InvalidArgumentException('Paid AI probe requires separate charge consent');
        }
        $checks=[];
        $check=static function(string $id,callable $fn)use(&$checks):void {
            try { $status=$fn()?'pass':'fail'; }
            catch (\Throwable) { $status='fail'; }
            $checks[]=['id'=>$id,'status'=>$status];
        };
        $check('security',static fn()=>SecurityAudit::run($root)['ok']);
        $check('compatibility',static fn()=>CompatibilityAssurance::check($root)['ok']);
        $check('update.no_pending',static fn()=>(new UpdateEngine($root))->status()['pending']===null);
        $check('cms',static fn()=>StableCMSAcceptance::run($root,$site,$live)['automated_ok']);
        $check('operations',static function()use($root):bool {
            $report=OperationalHealth::run($root,60,true);
            foreach ($report['checks'] as $item) {
                if ($item['id']==='backup.age_seconds' && $item['status']!=='pass') return false;
            }
            return $report['ok'];
        });
        // Do not contact providers if the staging deployment itself is broken.
        $localOk=!in_array('fail',array_column($checks,'status'),true);
        foreach (self::PROVIDERS as $name) {
            if (!in_array($name,$providers,true)) {
                $checks[]=['id'=>'provider.'.$name,'status'=>'not_run']; continue;
            }
            if (!$localOk) { $checks[]=['id'=>'provider.'.$name,'status'=>'blocked']; continue; }
            $check('provider.'.$name,static function()use($root,$site,$name,$paid):bool {
                return match($name) {
                    'oidc'=>StableAcceptance::run($root,$site,true)['automated_ok'],
                    'openai'=>$paid ? ProductionAIAcceptance::run($root,$site,true,true)['paid_smoke_passed'] :
                        Acceptance::run($root,$site,true)['automated_ok'],
                    default=>(new ReportService(new HttpClient(),$root))->probe($site,$name)['status']==='ok',
                };
            });
        }
        $ok=!in_array('fail',array_column($checks,'status'),true);
        return ['version'=>Lite::VERSION,'schema'=>$site['schema'],'checked_at'=>gmdate('c'),
            'automated_ok'=>$ok,'live_requested'=>$live,'paid_requested'=>$paid,'production_accepted'=>false,
            'status'=>!$ok?'failed':($live?'pending_manual':'pending_live'),'checks'=>$checks,
            'manual'=>['Record exact deployed signed artifact and CI evidence.',
                'Verify real OIDC owner login/logout, browser consent and provider account delivery where enabled.',
                'Rehearse independent off-host restore and rollback on staging; record reviewer and outcome.']];
    }
}
