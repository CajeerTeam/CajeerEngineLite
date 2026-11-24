<?php
declare(strict_types=1);
namespace CajeerEngine\Release;

use CajeerEngine\Lite;
use CajeerEngine\Monitoring\OperationalHealth;
use CajeerEngine\Update\{OperationFiles,OperationLock,UpdatePackage,DistributionIndex,TrustPolicy};
use CajeerEngine\Storage\SiteRepository;

/** Operator-only acceptance. Signed build evidence and human attestation are distinct. */
final class ProductionStable
{
    public const EVIDENCE=['ci','browser_e2e','staging_fpm_tls','providers_and_consent','offhost_restore','upgrade_rollback','load_soak','production_observation'];
    private static function file(string $path,int $max): mixed {
        clearstatcache(true,$path);$st=@lstat($path);
        if (!is_array($st)||($st['mode']&0170000)!==0100000||$st['nlink']!==1||$st['size']<1||$st['size']>$max) throw new \RuntimeException('Missing or unsafe acceptance input');
        $h=@fopen($path,'rb');if (!is_resource($h)) throw new \RuntimeException('Cannot read acceptance input');
        $opened=fstat($h);
        if (!is_array($opened)||$opened['dev']!==$st['dev']||$opened['ino']!==$st['ino']||$opened['size']!==$st['size']) {fclose($h);throw new \RuntimeException('Acceptance input replaced');}
        return $h;
    }
    private static function read(string $path,int $max): string {
        $h=self::file($path,$max);
        try {$st=fstat($h);$raw=stream_get_contents($h,$max+1);$end=fstat($h);
            if (!is_string($raw)||strlen($raw)!==$st['size']||$end['mtime']!==$st['mtime']||$end['ctime']!==$st['ctime']||$end['size']!==$st['size']) throw new \RuntimeException('Acceptance input changed');
            return $raw;
        } finally {fclose($h);}
    }
    private static function digest(string $path,int $max): string {
        $h=self::file($path,$max);
        try {$st=fstat($h);$ctx=hash_init('sha256');$n=hash_update_stream($ctx,$h);$end=fstat($h);
            if ($n!==$st['size']||$end['size']!==$st['size']||$end['mtime']!==$st['mtime']||$end['ctime']!==$st['ctime']) throw new \RuntimeException('Acceptance input changed');
            return hash_final($ctx);
        } finally {fclose($h);}
    }
    public static function runtimePath(string $path): bool {
        return in_array($path,['VERSION','bootstrap.php','router.php','bin/cajeer'],true)||preg_match('~^(?:core|templates|public)/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$path)===1;
    }
    public static function inventory(string $root): array {
        $files=['VERSION','bootstrap.php','router.php','bin/cajeer'];
        foreach (['core','templates','public'] as $dir) {
            $path=OperationFiles::path($root,$dir);
            if (!is_dir($path)) throw new \RuntimeException('Missing runtime directory');
            $files=array_merge($files,OperationFiles::files($root,$dir));
        }
        if (count($files)>1024) throw new \RuntimeException('Unexpected runtime inventory size');
        sort($files,SORT_STRING);$result=[];
        foreach ($files as $name) {
            if (!self::runtimePath($name)) throw new \RuntimeException('Unexpected runtime path');
            $path=OperationFiles::path($root,$name);$st=@lstat($path);
            if (!is_array($st)||($st['mode']&0022)!==0) throw new \RuntimeException('Unsafe runtime permissions');
            $result[$name]=self::digest($path,UpdatePackage::MAX_FILE);
        }
        return $result;
    }
    /** Verify the complete artifact set and exact installed executable/template inventory. */
    public static function artifacts(string $root,string $dir,string|TrustPolicy $keyPath): array {
        if (!is_dir($dir)||is_link($dir)) throw new \RuntimeException('Invalid release directory');
        $sums=self::read(OperationFiles::path($dir,'SHA256SUMS'),4096);
        $signature=self::read(OperationFiles::path($dir,'SHA256SUMS.sig'),16384);
        $policy=$keyPath instanceof TrustPolicy?$keyPath:TrustPolicy::single($keyPath);
        $fingerprint=$policy->verify($sums,$signature);
        $policy->assertTarget(Lite::VERSION);
        $version=Lite::VERSION;$package="CajeerEngineLite-$version.cjrupdate";$zip="CajeerEngineLite-$version.zip";
        $expected=[$package,$zip,'release-report.json'];$hashes=[];
        foreach (explode("\n",rtrim($sums,"\n")) as $line) {
            if (!preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9.-]+)$/D',$line,$m)||!in_array($m[2],$expected,true)||isset($hashes[$m[2]])) throw new \RuntimeException('Invalid signed checksum inventory');
            $hashes[$m[2]]=$m[1];
        }
        if (count($hashes)!==3) throw new \RuntimeException('Incomplete signed checksum inventory');
        $receiptRaw=self::read(OperationFiles::path($dir,'release-report.json'),1048576);
        if (!hash_equals($hashes['release-report.json'],hash('sha256',$receiptRaw))) throw new \RuntimeException('Release receipt hash mismatch');
        foreach ([$package,$zip] as $name) if (!hash_equals($hashes[$name],self::digest(OperationFiles::path($dir,$name),UpdatePackage::MAX_BYTES))) throw new \RuntimeException('Release artifact hash mismatch');
        $receipt=json_decode($receiptRaw,true,32,JSON_THROW_ON_ERROR);
        $verified=UpdatePackage::verify(OperationFiles::path($dir,$package),$keyPath);
        $required=['zip_inventory','fresh_install','loopback_http','signature','exact_upgrade','site_preserved','populated_site_media_preserved','backup_verified_before_after_rollback','rollback','tamper_rejected'];
        if (($receipt['to']??null)!==$version||($receipt['schema']??null)!==14||($receipt['production_accepted']??null)!==false||
            ($receipt['key_sha256']??null)!==$fingerprint||$verified['key_sha256']!==$receipt['key_sha256']||
            $verified['manifest']['to']!==$version||$verified['manifest']['from']!==($receipt['from']??null)||$verified['package_sha256']!==$hashes[$package]||
            !is_array($receipt['checks']??null)||array_diff($required,$receipt['checks'])||!is_array($receipt['runtime_files']??null)) throw new \RuntimeException('Invalid signed rehearsal receipt');
        $inventory=self::inventory($root);$signed=$receipt['runtime_files'];ksort($signed,SORT_STRING);
        if ($inventory!==$signed) throw new \RuntimeException('Installed runtime differs from signed release');
        $distribution=[];
        $indexPath=OperationFiles::path($dir,DistributionIndex::NAME);
        if(file_exists($indexPath)) {
            $raw=self::read($indexPath,DistributionIndex::MAX_BYTES);
            $entries=DistributionIndex::verify($raw,$version,$keyPath);$primary=false;
            foreach($entries as $entry){
                $file=OperationFiles::path($dir,$entry['name']);$v=UpdatePackage::verify($file,$keyPath);
                if($v['manifest']['from']!==$entry['from']||$v['manifest']['to']!==$version||filesize($file)!==$entry['bytes']||!hash_equals($entry['sha256'],$v['package_sha256']))throw new \RuntimeException('Signed distribution package mismatch');
                if($entry['name']===$package&&$entry['from']===$receipt['from']&&hash_equals($entry['sha256'],$hashes[$package]))$primary=true;
            }
            if(!$primary)throw new \RuntimeException('Primary package missing from signed distribution');
            $distribution=['distribution_index_sha256'=>hash('sha256',$raw),'distribution_packages'=>count($entries)];
        }
        return $distribution+['from'=>$receipt['from'],'version'=>$version,'receipt_sha256'=>$hashes['release-report.json'],'package_sha256'=>$hashes[$package],
            'zip_sha256'=>$hashes[$zip],'key_sha256'=>$receipt['key_sha256'],'runtime_sha256'=>hash('sha256',json_encode($inventory,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'target_commit'=>$receipt['target_commit']??null,'runtime_files'=>count($inventory)];
    }
    private static function requiredProviders(array $site): array {
        $p=[];$r=$site['reporting'];$i=$site['integrations'];
        if (($site['identity']['owner']??null)!==null||getenv('CAJEER_OIDC_CLIENT_ID')||getenv('CAJEER_OIDC_CLIENT_SECRET')) $p[]='oidc';
        if ($site['ai']['enabled']) $p[]='openai';
        if ($r['ga4_property_id']!=='') $p[]='ga4';
        if ($i['metrica']['counter_id']!==''&&getenv('CAJEER_REPORT_YANDEX_TOKEN')) $p[]='metrica';
        if ($i['matomo']['site_id']!==''&&getenv('CAJEER_REPORT_MATOMO_TOKEN')) $p[]='matomo';
        if ($i['clarity']['project_id']!==''&&getenv('CAJEER_REPORT_CLARITY_TOKEN')) $p[]='clarity';
        if ($r['gsc_site_url']!=='') $p[]='gsc';
        if ($r['yandex_webmaster_user_id']!==''||$r['yandex_webmaster_host_id']!=='') $p[]='yandex_webmaster';
        return $p;
    }
    private static function siteDigest(array $site): string { return hash('sha256',json_encode($site,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    private static function liveVersion(string $origin): bool {
        $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>6,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true,'header'=>"Connection: close\r\nAccept: application/json"], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $h=@fopen(rtrim($origin,'/').'/health/ready','rb',false,$ctx);
        if (!is_resource($h)) return false;
        try {$headers=stream_get_meta_data($h)['wrapper_data']??[];$body=stream_get_contents($h,1025);}
        finally {fclose($h);}
        $versions=[];foreach ($headers as $line) if (preg_match('/^X-Cajeer-Release:\s*(\S+)\s*$/i',$line,$m)) $versions[]=$m[1];
        return $versions===[Lite::VERSION]&&preg_match('~^HTTP/\S+ 200(?: |$)~',$headers[0]??'')===1&&$body==='{"status":"ready"}';
    }
    public static function run(string $root,array $site,string $dir,string|TrustPolicy $key,bool $live,array $providers,bool $paid): array {
        if ($live&&getenv('CAJEER_PRODUCTION_LIVE_CONFIRM')!=='LIVE') throw new \InvalidArgumentException('Live checks require CAJEER_PRODUCTION_LIVE_CONFIRM=LIVE');
        $artifact=self::artifacts($root,$dir,$key);
        $required=self::requiredProviders($site);
        if ($live&&array_diff($required,$providers)) throw new \InvalidArgumentException('Select all configured API providers: '.implode(',',$required));
        // RC gate is deliberately opt-in; scope its legacy staging acknowledgement to this call.
        $old=getenv('CAJEER_RC_STAGING_CONFIRM');if ($live) putenv('CAJEER_RC_STAGING_CONFIRM=STAGING');
        try {$rc=ReleaseCandidate::run($root,$site,$live,$providers,$paid);}
        finally {putenv($old===false?'CAJEER_RC_STAGING_CONFIRM':'CAJEER_RC_STAGING_CONFIRM='.$old);}
        $health=OperationalHealth::run($root,60,true);$metrics=$health['metrics'];
        $monitorOk=$health['ok']&&is_array($metrics)&&$metrics['enabled']&&$metrics['invalid_records']===0&&$metrics['all']['requests']>=20&&
            is_int($metrics['newest_retained_at'])&&$metrics['newest_retained_at']>=time()-300&&$metrics['newest_retained_at']<=time();
        $checks=$rc['checks'];$checks[]=['id'=>'release.signed_runtime','status'=>'pass'];
        $checks[]=['id'=>'monitor.recent_samples','status'=>$monitorOk?'pass':($live?'fail':'warn')];
        $checks[]=['id'=>'live.release_version','status'=>$live?($rc['automated_ok']&&self::liveVersion($site['settings']['url'])?'pass':'fail'):'not_run'];
        $ok=!in_array('fail',array_column($checks,'status'),true)&&$rc['automated_ok'];
        // Detect deployment changes while running the gate.
        if (hash('sha256',json_encode(self::inventory($root),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))!==$artifact['runtime_sha256']) throw new \RuntimeException('Runtime changed during acceptance');
        $siteHash=self::siteDigest($site);
        if (self::siteDigest((new SiteRepository($root))->read())!==$siteHash) throw new \RuntimeException('Site configuration changed during acceptance');
        return ['version'=>Lite::VERSION,'schema'=>$site['schema'],'checked_at'=>gmdate('c'),'site_sha256'=>$siteHash,'origin_sha256'=>hash('sha256',$site['settings']['url']),
            'artifact'=>$artifact,'automated_ok'=>$ok,'live_requested'=>$live,'production_accepted'=>false,'status'=>!$ok?'failed':($live?'pending_signoff':'pending_live'),
            'required_providers'=>$required,'checks'=>$checks,'monitor'=>['window_minutes'=>60,'metrics'=>$metrics['all']??null,'thresholds'=>$health['thresholds']]];
    }
    /** Attest only after a fresh passing live gate. Evidence content is reviewed by the operator, not inferred by software. */
    public static function signoff(string $root,array $report,string $evidenceDir): array {
        if (getenv('CAJEER_PRODUCTION_SIGNOFF_CONFIRM')!=='I_REVIEWED_PRODUCTION_EVIDENCE'||($report['status']??null)!=='pending_signoff'||($report['automated_ok']??false)!==true) throw new \RuntimeException('Sign-off requires explicit review and a passing live gate');
        if (!is_dir($evidenceDir)||is_link($evidenceDir)) throw new \RuntimeException('Invalid evidence directory');
        $raw=self::read(OperationFiles::path($evidenceDir,'approval.json'),65536);$approval=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $reviewer=$approval['reviewer']??null;
        if (!is_string($reviewer)||!preg_match('/^[\p{L}\p{N} ._@-]{2,100}$/uD',$reviewer)||($approval['decision']??null)!=='approve'||($approval['version']??null)!==Lite::VERSION||
            ($approval['receipt_sha256']??null)!==$report['artifact']['receipt_sha256']||($approval['origin_sha256']??null)!==$report['origin_sha256']||($approval['site_sha256']??null)!==$report['site_sha256']) throw new \RuntimeException('Approval does not match this release and deployment');
        $required=self::EVIDENCE;
        if(isset($report['updates_ga'])) {
            $ga=$report['updates_ga'];$publication=$ga['publication']['publication_sha256']??null;
            if(!is_string($publication)||($approval['publication_sha256']??null)!==$publication||
                ($approval['distribution_index_sha256']??null)!==$ga['distribution_index_sha256'])throw new \RuntimeException('Approval does not match verified GitHub publication');
            $required=array_merge($required,UpdatesGA::EVIDENCE);
        }
        $evidence=$approval['evidence']??null;
        if (!is_array($evidence)||count($evidence)!==count($required)||array_diff($required,array_keys($evidence))) throw new \RuntimeException('All review evidence is required');
        $hashes=[];
        foreach ($required as $id) {
            $hash=self::digest(OperationFiles::path($evidenceDir,$id.'.txt'),16*1048576);
            if (!is_string($evidence[$id])||!hash_equals($hash,$evidence[$id])) throw new \RuntimeException('Review evidence hash mismatch: '.$id);
            $hashes[$id]=$hash;
        }
        $dir=OperationFiles::path($root,'storage/app/release-signoff');OperationFiles::dir($dir,0700);$st=lstat($dir);
        if (($st['mode']&077)!==0) throw new \RuntimeException('Sign-off directory must be private');
        $id=gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
        $record=['id'=>$id,'recorded_at'=>gmdate('c'),'status'=>'operator_attested','production_accepted'=>true,'reviewer'=>$reviewer,
            'approval_sha256'=>hash('sha256',$raw),'evidence_sha256'=>$hashes,'live_report'=>$report,
            'note'=>'Operator attestation; evidence contents and reviewer identity are not independently verified. Not continuous certification.'];
        $lease=OperationLock::acquire($root,true);
        try {
            (new SiteRepository($root))->exclusive(static function(SiteRepository $repository) use($root,$report,$dir,$id,$record): void {
                if (OperationLock::maintenance($root)||\CajeerEngine\Operations\RecoveryJournal::pending($root)||self::siteDigest($repository->read())!==$report['site_sha256']||hash('sha256',json_encode(self::inventory($root),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))!==$report['artifact']['runtime_sha256']||strtotime($report['checked_at'])<time()-300) throw new \RuntimeException('Deployment changed or live report expired');
                if(isset($report['updates_ga'])) {
                    $control=(new \CajeerEngine\Update\ControlCenter($root))->status();$key=TrustPolicy::configured();
                    if($control['revision']!==$report['updates_ga']['control_revision']||$key->report()!==$report['updates_ga']['trust']||
                        in_array('fail',array_column(UpdatesGA::configuration($control,$key,true),'status'),true))throw new \RuntimeException('Update control or trust changed during sign-off');
                }
                OperationFiles::writeNew($dir.'/'.$id.'.json',json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",0600);
            });
        } finally {OperationLock::release($lease);}
        return $record;
    }
}
