<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

/**
 * Operational 1.3.0 release gate. Paid probes are CLI-only, require explicit
 * operator opt-in, and go through the SAME durable quota/budget ledger as the UI.
 * Tests can inject a transport without opening the public API or storing secrets.
 */
final class ProductionAIAcceptance
{
    private const CONFIRM='I_ACCEPT_API_CHARGES';

    public static function run(string $root, array $site, bool $live=false, bool $paid=false,
        ?OpenAIClient $client=null): array
    {
        // Never contact a live provider as part of --paid until the operator opts in.
        $approved=getenv('CAJEER_AI_PAID_PROBE_CONFIRM')===self::CONFIRM;
        $base=Acceptance::run($root,$site,($live || $paid) && (!$paid || $approved),$client);
        $checks=$base['checks'];
        $ok=$base['automated_ok'];
        $paidOk=false;
        $usage=null;
        if (!$paid) {
            $checks[]=['id'=>'paid_api_smoke','status'=>'warn',
                'message'=>'Explicit paid smoke test has not run (--paid with operator approval)'];
        } elseif (!$approved) {
            $checks[]=['id'=>'paid_api_smoke','status'=>'fail',
                'message'=>'Paid API probe requires explicit CAJEER_AI_PAID_PROBE_CONFIRM approval'];
            $ok=false;
        } elseif (!$ok) {
            $checks[]=['id'=>'paid_api_smoke','status'=>'fail',
                'message'=>'Resolve release configuration checks before a paid API probe'];
            $ok=false;
        } else {
            try {
                $policy=CostPolicy::load($root);
                if (!$policy['enabled']) {
                    throw new \RuntimeException('Monetary budget controls are disabled');
                }
                $client??=new OpenAIClient();
                $quota=new UsageLimiter($root);
                $start=$quota->status();
                // Fixed, synthetic prompts: never send site content, owner identity,
                // diagnostics, access tokens, or private pages to the probe.
                $plain=$quota->generate($client,$site['ai'],
                    'Return one short Russian sentence about a fictional garden.');
                self::validResult($plain);
                $structured=$quota->structuredContent($client,$site['ai'],
                    'Create a short Russian heading about a fictional garden.','title');
                if (!is_string($structured['fields']['title']??null) ||
                    trim($structured['fields']['title'])==='') {
                    throw new \RuntimeException('Structured response is missing its title');
                }
                self::validUsage($structured);
                $deltas='';
                $streamed=$quota->chat($client,$site['ai'],
                    [['role'=>'user','content'=>'Respond briefly in Russian about a fictional garden.']],
                    true,static function(string $piece)use(&$deltas):void {$deltas.=$piece;});
                self::validResult($streamed);
                if ($deltas!==$streamed['text']) {
                    throw new \RuntimeException('Streamed output does not match completed response');
                }
                $end=$quota->status();
                $actual=$plain['input_tokens']+$plain['output_tokens']+
                    $structured['input_tokens']+$structured['output_tokens']+
                    $streamed['input_tokens']+$streamed['output_tokens'];
                if ($end['requests']!==$start['requests']+3 ||
                    $end['tokens']!==$start['tokens']+$actual ||
                    $end['active_requests']!==$start['active_requests']) {
                    throw new \RuntimeException('Provider usage was not fully accounted');
                }
                $paidOk=true;
                $usage=['requests'=>3,'input_tokens'=>$plain['input_tokens']+
                    $structured['input_tokens']+$streamed['input_tokens'],
                    'output_tokens'=>$plain['output_tokens']+
                    $structured['output_tokens']+$streamed['output_tokens']];
                $checks[]=['id'=>'paid_api_smoke','status'=>'pass',
                    'message'=>'Text, Structured Outputs, SSE and persistent usage passed through live API'];
            } catch (\Throwable) {
                // Never surface a third-party exception (may include provider response or credentials).
                $checks[]=['id'=>'paid_api_smoke','status'=>'fail',
                    'message'=>'Paid API response, streaming or quota verification failed; inspect protected server logs'];
                $ok=false;
            }
        }
        return [
            'version'=>\CajeerEngine\Lite::VERSION,
            'schema'=>$base['schema'],
            'automated_ok'=>$ok,
            'paid_smoke_passed'=>$paidOk,
            'production_accepted'=>false,
            'usage'=>$usage,
            'checks'=>$checks,
            // A single paid smoke cannot certify owner approval, cross-provider data,
            // live SSO, backup restores, provider billing, or browser workflows.
            'manual'=>[
                'Verify draft generation, preview, approval, history, rollback, SEO and analytics against a staging site.',
                'Verify SkiF4er ID, cookie consent and external analytics using real staging credentials.',
                'Reconcile token estimates, model prices and actual OpenAI billing; keep provider-level spending caps.',
                'Verify deployment, backup restore, recovery, and rollback under production PHP-FPM/TLS.',
            ],
        ];
    }

    private static function validResult(array $response): void
    {
        if (!is_string($response['text']??null) || trim($response['text'])==='' ||
            !preg_match('//u',$response['text'])) {
            throw new \RuntimeException('Invalid live AI output');
        }
        self::validUsage($response);
    }

    private static function validUsage(array $response): void
    {
        if (!is_int($response['input_tokens']??null) || $response['input_tokens']<0 ||
            !is_int($response['output_tokens']??null) || $response['output_tokens']<0) {
            throw new \RuntimeException('Invalid live AI result');
        }
    }
}
