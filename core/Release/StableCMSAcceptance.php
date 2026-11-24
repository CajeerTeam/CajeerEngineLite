<?php
declare(strict_types=1);
namespace CajeerEngine\Release;

use CajeerEngine\Auth\DeploymentProbe;
use CajeerEngine\Developer\DeveloperDiagnostics;
use CajeerEngine\Integrations\ProductionAcceptance;
use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Operations\CmsOperations;
use CajeerEngine\Storage\SiteState;

/** Executable release gate for installed CMS code, persistent data and recoverability.
 * Staging/live sign-off is deliberately separate from local verification. */
final class StableCMSAcceptance
{
    /** @return array<string,mixed> */
    public static function run(string $root, array $state, bool $live = false, mixed $httpDriver = null): array
    {
        $checks=[];
        $add=static function(string $id, string $status, string $message) use (&$checks): void {
            if (!in_array($status,['pass','warn','fail'],true)) throw new \LogicException('Invalid status');
            $checks[]=compact('id','status','message');
        };
        $versionFile = $root.'/VERSION';
        $versionStat = @lstat($versionFile);
        $versionMatches = is_array($versionStat) && ($versionStat['mode'] & 0170000) === 0100000 &&
            $versionStat['nlink'] === 1 && ($versionStat['mode'] & 0022) === 0 &&
            trim((string) @file_get_contents($versionFile)) === Lite::VERSION;
        $add('release.version', $versionMatches ? 'pass' : 'fail',
            $versionMatches ? 'Installed release and VERSION file agree ('.Lite::VERSION.')' :
            'Installed release does not match VERSION or the version file is unsafe');
        try {
            SiteState::validate($state);
            $add('data.schema', $state['schema']===SiteState::SCHEMA?'pass':'fail',
                'Persistent site document validates against the current JSON schema');
        } catch (\Throwable) {
            $add('data.schema','fail','Site state is invalid or requires migration');
        }
        $runtime=DeveloperDiagnostics::run($root,$state);
        foreach ($runtime['checks'] as $item) {
            $add('developer.'.$item['id'],$item['status'],$item['detail']);
        }
        try {
            $integration=ProductionAcceptance::run($root,$state);
            foreach ($integration['checks'] as $item) {
                $add('integrations.'.$item['id'],$item['status'],$item['message']);
            }
        } catch (\Throwable) {
            $add('integrations.runtime','fail','Integration configuration or runtime could not be validated');
        }

        // Verify every shipped executable/template asset, not merely the entrypoint.
        $unsafe=[]; $count=0;
        foreach (['bootstrap.php','router.php','bin/cajeer'] as $name) {
            self::checkCode($root.'/'.$name,$name,$unsafe); ++$count;
        }
        foreach (['core','public','templates'] as $directory) {
            $base=$root.'/'.$directory;
            if (!is_dir($base) || is_link($base)) { $unsafe[]=$directory; continue; }
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                $path=$entry->getPathname();
                $name=substr($path,strlen($root)+1);
                if ($entry->isLink() || !$entry->isFile()) { $unsafe[]=$name; continue; }
                self::checkCode($path,$name,$unsafe); ++$count;
            }
        }
        $add('deployment.immutable_code',$unsafe===[]?'pass':'fail',
            $unsafe===[]?'Verified '. $count .' executable, public and template assets':
            'Missing, linked, hardlinked or writable assets: '.implode(', ',array_slice($unsafe,0,8)));
        $docroot=realpath($root.'/public'); $storage=realpath($root.'/storage');
        $add('deployment.document_root',is_string($docroot) && is_string($storage) &&
            $storage!==$docroot && !str_starts_with($storage,$docroot.'/')?'pass':'fail',
            'Private site data must be outside the public document root');
        $private=[];
        foreach (['storage'=>'dir','storage/site.json'=>'file','storage/media'=>'dir',
                  'storage/media/files'=>'dir'] as $name=>$type) {
            $file=$root.'/'.$name;
            clearstatcache(true,$file); $st=@lstat($file);
            if (!is_array($st)) {
                if (str_starts_with($name,'storage/media')) continue; // empty libraries are allowed
                $private[]=$name; continue;
            }
            $expected=$type==='dir'?0040000:0100000;
            if (($st['mode'] & 0170000)!==$expected || ($st['mode'] & 0077)!==0 ||
                ($type==='file' && $st['nlink']!==1)) $private[]=$name;
        }
        $add('storage.permissions',$private===[]?'pass':'fail',
            $private===[]?'Private site files and directories are restricted to their owner':
            'Unsafe private storage permissions: '.implode(', ',$private));

        $ops=new CmsOperations($root);
        try {
            $audit=$ops->auditStorage();
            $clean=($audit['orphan_files']===0);
            $add('storage.media_integrity',$clean?'pass':'fail',
                $clean ? 'Verified media index, all image/PDF bytes and absence of orphan files' :
                    'Unindexed media files detected; run operations audit and repair-orphans after checking backups');
        } catch (\Throwable) {
            $add('storage.media_integrity','fail',
                'Media integrity audit failed; restore missing/corrupted assets from a verified CMS backup');
        }
        try {
            $names=$ops->names();
            if ($names===[]) $add('recovery.offline_backup','fail','No CMS backup exists; create and verify one before release');
            else {
                $details=$ops->verifyBackup($names[0]);
                $add('recovery.offline_backup','pass','Most recent CMS backup passes TAR, data and media integrity verification');
            }
        } catch (\Throwable) {
            $add('recovery.offline_backup','fail','CMS backup inventory or most recent archive is invalid');
        }
        try {
            self::restoreDrill($root,$state,$ops);
            $add('recovery.restore_drill','pass','Full export, verified TAR import, media checksums and credential isolation passed on a disposable site');
        } catch (\Throwable) {
            $add('recovery.restore_drill','fail','Isolated export/import recovery drill failed');
        }

        if ($live) {
            try {
                $result=DeploymentProbe::run($state['settings']['url'],$httpDriver);
                foreach ($result['checks'] as $item) $add('live.'.$item['id'],$item['status'],$item['message']);
                $add('live.http.ready', self::probeReadiness($state['settings']['url'],$httpDriver)?'pass':'fail',
                    'Deployment GET /health/ready must return verified HTTPS 200 and ready JSON');
            } catch (\Throwable) {
                $add('live.https','fail','The configured HTTPS installation could not be probed securely');
            }
        } else $add('live.https','warn','Live HTTPS/TLS and reverse proxy probes were not requested (--live)');

        $manual=[
            'Run this gate from the actual PHP-FPM release environment as well as CLI; verify the canonical Host, TLS termination, restrictive permissions and real GET /health/ready.',
            'Restore an off-host copy of code, custom templates, secrets and storage to an isolated staging host; verify credentials and rollback procedure.',
            'Exercise login, SkiF4er ID OIDC and back-channel logout (if enabled) with real accounts in two browsers.',
            'Confirm live OpenAI requests and analytics/consent behavior only for the external providers actually configured.',
            'Run the full PHP/HTTP/Chromium test suite on CI and test the upgrade, backup and rollback on the production-like server under expected traffic.',
        ];
        $automatedOk=!in_array('fail',array_column($checks,'status'),true);
        return ['version'=>Lite::VERSION, 'schema'=>SiteState::SCHEMA,
            'automated_ok'=>$automatedOk,'live_checked'=>$live,
            'production_accepted'=>false,
            'status'=>!$automatedOk?'failed':($live?'pending_manual':'pending_live'),
            'counts'=>[
                'pass'=>count(array_filter($checks,static fn($c)=>$c['status']==='pass')),
                'warn'=>count(array_filter($checks,static fn($c)=>$c['status']==='warn')),
                'fail'=>count(array_filter($checks,static fn($c)=>$c['status']==='fail')),
            ], 'checks'=>$checks,'manual'=>$manual];
    }

    /** @param list<string> $unsafe */
    private static function checkCode(string $file, string $relative, array &$unsafe): void
    {
        clearstatcache(true,$file); $stat=@lstat($file);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 ||
            $stat['nlink']!==1 || ($stat['mode'] & 0022)!==0) $unsafe[]=$relative;
    }

    /** Read-only TLS-verified check against the installation readiness endpoint. */
    private static function probeReadiness(string $origin, mixed $driver): bool
    {
        try {
            if (!str_starts_with($origin,'https://')) return false;
            $url=rtrim($origin,'/').'/health/ready';
            if ($driver!==null) {
                [$status,$headers,$body]=$driver($url);
            } else {
                $context=stream_context_create([
                    'http'=>['method'=>'GET','timeout'=>6,'follow_location'=>0,
                        'max_redirects'=>0,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nConnection: close"],
                    'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
                ]);
                $stream=@fopen($url,'rb',false,$context);
                if (!is_resource($stream)) return false;
                try {
                    $headers=stream_get_meta_data($stream)['wrapper_data']??[];
                    $body=stream_get_contents($stream,1024);
                } finally { fclose($stream); }
                $status=0;
                foreach ($headers as $header) {
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~',(string)$header,$match)) $status=(int)$match[1];
                }
            }
            if ($status!==200 || !is_array($headers) || !is_string($body) || strlen($body)>1024) return false;
            $noStore=false;
            foreach ($headers as $header) {
                if (is_string($header) && preg_match('/^Cache-Control:\s*.*no-store/i',$header)) $noStore=true;
            }
            $json=json_decode($body,true,4);
            return $noStore && is_array($json) && $json===['status'=>'ready'];
        } catch (\Throwable) { return false; }
    }

    /** Actual restoration of the current content, never modifying published site state. */
    private static function restoreDrill(string $root, array $state, CmsOperations $operations): void
    {
        $temporary=sys_get_temp_dir().'/cms140-'.bin2hex(random_bytes(12));
        if (!@mkdir($temporary,0700)) throw new \RuntimeException('Cannot create isolated restoration root');
        $export=null;
        try {
            $export=$operations->exportArchive();
            $summary=$operations->verifyFile($export);
            if ($summary['kind']!=='export') throw new \RuntimeException('Not a portable export');
            $clone=new Lite($temporary);
            $password=bin2hex(random_bytes(24));
            $clone->install($password,'https://disposable.example.invalid','Restoration drill');
            (new CmsOperations($temporary))->importFile($export);
            $restored=$clone->load();
            foreach (['pages','modules','consent','integrations','reporting','seo'] as $field) {
                if (($state[$field]??null)!==($restored[$field]??null)) {
                    throw new \RuntimeException('Restored site content differs');
                }
            }
            $settings=$state['settings'];unset($settings['url']);
            $otherSettings=$restored['settings'];unset($otherSettings['url']);
            if ($otherSettings!==$settings || $restored['settings']['url']!=='https://disposable.example.invalid' ||
                !password_verify($password,$restored['password']) || ($restored['identity']['owner']??null)!==null) {
                throw new \RuntimeException('Import credential isolation failed');
            }
            $originalMedia=new MediaLibrary($root);$copiedMedia=new MediaLibrary($temporary);
            $origin=$originalMedia->listing();$copy=$copiedMedia->listing();
            if ($origin!==$copy) throw new \RuntimeException('Media registry differs after import');
            foreach ($origin as $entry) {
                $a=$originalMedia->read($entry['id']);$b=$copiedMedia->read($entry['id']);
                if ($a===null || $b===null || $a['bytes']!==$b['bytes']) throw new \RuntimeException('Media recovery checksum mismatch');
            }
        } finally {
            if (is_string($export)) $operations->removeExport($export);
            self::removeTree($temporary);
        }
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) return;
        $iterator=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                if (!@rmdir($entry->getPathname())) throw new \RuntimeException('Cannot clean test directory');
            } else {
                if (!@unlink($entry->getPathname())) throw new \RuntimeException('Cannot clean test file');
            }
        }
        if (!@rmdir($root)) throw new \RuntimeException('Cannot clean test root');
    }
}
