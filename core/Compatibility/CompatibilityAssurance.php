<?php
declare(strict_types=1);
namespace CajeerEngine\Compatibility;

use CajeerEngine\Storage\{SiteRepository,SiteState};
use CajeerEngine\Update\UpdatePackage;
use CajeerEngine\Lite;

/** Read-only runtime/deployment checks and deterministic, independently verifiable migration previews. */
final class CompatibilityAssurance
{
    public static function preview(string $root): array
    {
        $repository=new SiteRepository($root);
        $raw=$repository->raw();
        if ($raw===null) throw new \RuntimeException('Site not installed');
        $old=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($old)) throw new \RuntimeException('Site JSON is not an object');
        SiteState::validate($old);
        $upgraded=SiteState::migrate($old); // In memory; does not write or create backups.
        $encoded=SiteState::encode($upgraded);
        if ($old['password']!==$upgraded['password'] ||
            $old['settings']!==$upgraded['settings'] ||
            $old['pages']!==$upgraded['pages']) {
            throw new \RuntimeException('Legacy migration would alter credentials or site content');
        }
        $schema=$old['schema'];
        return ['source_schema'=>$schema,'target_schema'=>SiteState::SCHEMA,
            'migration_required'=>$schema!==SiteState::SCHEMA,
            'page_count'=>count($old['pages']),'source_sha256'=>hash('sha256',$raw),
            'target_sha256'=>hash('sha256',$encoded),'target_bytes'=>strlen($encoded)];
    }

    /** Executes existing journaled migration, requiring maintenance confirmation and an intact snapshot. */
    public static function apply(string $root): array
    {
        if (getenv('CAJEER_COMPATIBILITY_CONFIRM')!=='MIGRATE') {
            throw new \RuntimeException('Stop PHP-FPM/jobs and set CAJEER_COMPATIBILITY_CONFIRM=MIGRATE');
        }
        $before=self::preview($root);
        $repository=new SiteRepository($root);
        $backups=new \CajeerEngine\Storage\BackupService($repository);
        $existing=$before['migration_required'] ? $backups->names() : [];
        $changed=$repository->migrate($before['source_sha256']); // Lock and preflight exact preview revision.
        $after=self::preview($root);
        if ($after['source_schema']!==SiteState::SCHEMA ||
            $before['migration_required']!==$changed) {
            throw new \RuntimeException('Migration postcondition failed; use verified migration backup');
        }
        if ($changed) {
            $found=false;
            foreach (array_diff($backups->names(),$existing) as $name) {
                $info=$backups->verify($name);
                if (($info['reason']??null)==='migration' && $info['schema']===$before['source_schema']) {
                    $found=true;break;
                }
            }
            if (!$found) throw new \RuntimeException('Migration snapshot not found after commit');
        }
        return ['changed'=>$changed,'before'=>$before,'after'=>$after];
    }

    public static function check(string $root,?string $documentRoot=null): array
    {
        $checks=[];
        $add=static function(string $id,string $status,string $detail) use (&$checks): void {
            $checks[]=['id'=>$id,'status'=>$status,'detail'=>$detail];
        };
        $add('php.runtime',PHP_VERSION_ID>=80401?'pass':'fail',
            'Running PHP '.PHP_VERSION.'; minimum supported PHP 8.4.1');
        foreach (['json','hash','filter','session','openssl'] as $ext) {
            $add('php.'.$ext,extension_loaded($ext)?'pass':'fail',
                'Required PHP extension '.$ext);
        }
        $add('php.fsync',function_exists('fsync')?'pass':'fail','Durable fsync support is required');
        $add('app.version',trim((string)@file_get_contents($root.'/VERSION'))===Lite::VERSION?'pass':'fail',
            'VERSION file must match the running application');
        $public=realpath($root.'/public');
        $storage=realpath($root.'/storage');
        $add('deployment.public',is_string($public)&&is_file($public.'/index.php')?'pass':'fail',
            'Public document root must contain index.php');
        $documentRoot??=getenv('CAJEER_COMPAT_DOCUMENT_ROOT')?:null;
        if ($documentRoot!==null) {
            $actual=realpath($documentRoot);
            $add('deployment.document_root',$actual!==false&&$public!==false&&$actual===$public?'pass':'fail',
                'Web server DocumentRoot must resolve to the public/ directory');
        } else $add('deployment.document_root','manual','Set CAJEER_COMPAT_DOCUMENT_ROOT to verify the actual DocumentRoot');
        $add('deployment.storage',($public!==false && $storage!==false &&
            !str_starts_with($storage,$public.'/') && $storage!==$public)?'pass':'fail',
            'Private storage must be outside public/');
        try {
            $ips=TrustedProxy::addresses();
            $add('proxy.trust','pass',$ips===[]?'Direct TLS mode; forwarded headers are not trusted':
                'Explicit exact-IP trusted proxies: '.count($ips));
        } catch (\Throwable $e) { $add('proxy.trust','fail','Invalid CAJEER_TRUSTED_PROXY_IPS configuration'); }
        try {
            $repository=new SiteRepository($root);
            $raw=$repository->raw();
            if($raw===null) {
                $add('data.site','manual','Site is not installed; run the CLI installer');
            } else {
                $preview=self::preview($root);
                $add('data.site','pass','Valid schema '.$preview['source_schema'].'; '. $preview['page_count'].' pages');
                $add('data.migration',$preview['migration_required']?'manual':'pass',
                    $preview['migration_required']?'Schema upgrade is available via compatibility migration apply':'Schema 14 is current');
            }
        } catch(\Throwable $e) {
            $add('data.site','fail','Invalid, unreadable or unsupported site state: '.get_class($e));
        }
        return ['version'=>Lite::VERSION,'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,
            'supported_php'=>'8.4.1+','schema'=>SiteState::SCHEMA,'checks'=>$checks,
            'ok'=>!in_array('fail',array_column($checks,'status'),true),
            'manual_required'=>in_array('manual',array_column($checks,'status'),true)];
    }
}
