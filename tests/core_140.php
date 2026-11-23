<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Operations\CmsOperations;
use CajeerEngine\Release\StableCMSAcceptance;
use CajeerEngine\Update\OperationFiles;

function assert140(bool $condition,string $reason): void { if (!$condition) throw new RuntimeException('1.4.0: '.$reason); }
function cleanup140(string $folder): void {
    if (!is_dir($folder)) return;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    rmdir($folder);
}
function copy140(string $from,string $to): void {
    if (is_dir($from)) {
        mkdir($to,0755);
        foreach (new DirectoryIterator($from) as $entry) if (!$entry->isDot()) copy140($entry->getPathname(),$to.'/'.$entry->getFilename());
    } else copy($from,$to);
}
function result140(array $report,string $id): string {
    foreach ($report['checks'] as $check) if ($check['id']===$id) return $check['status'];
    throw new RuntimeException('Missing gate check '.$id);
}

assert140(Lite::VERSION==='1.4.0','version bump');
$root=sys_get_temp_dir().'/cms140-tests-'.bin2hex(random_bytes(8));mkdir($root,0700);
try {
    $source=dirname(__DIR__);
    foreach (['core','public','templates'] as $name) copy140($source.'/'.$name,$root.'/'.$name);
    mkdir($root.'/bin',0755);copy($source.'/bin/cajeer',$root.'/bin/cajeer');
    foreach (['bootstrap.php','router.php'] as $name) copy($source.'/'.$name,$root.'/'.$name);
    $cms=new Lite($root);$cms->install('SourcePassword140!','https://test.example.invalid','Recovery test');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
    $image=$root.'/upload.png';file_put_contents($image,$png);
    $media=(new MediaLibrary($root))->ingest($image,'image.png');
    $cms->change(static function(array $site) use ($media): array {
        $site['pages']['/']['body']='CMS release 1.4.0';
        $site['seo']['default_image']=$media['id'];
        return $site;
    });
    $ops=new CmsOperations($root);
    $noBackup=StableCMSAcceptance::run($root,$cms->load());
    assert140(result140($noBackup,'recovery.offline_backup')==='fail','a missing backup blocks release');
    assert140(result140($noBackup,'recovery.restore_drill')==='pass','full restore drill runs without backup inventory');
    assert140($noBackup['production_accepted']===false,'no local test signs production acceptance');
    $backup=$ops->createBackup();
    $report=StableCMSAcceptance::run($root,$cms->load());
    assert140($report['automated_ok']===true,'healthy staging release gate');
    assert140($report['status']==='pending_live','no live claim');
    assert140(result140($report,'recovery.offline_backup')==='pass','verified TAR backup');
    assert140(result140($report,'recovery.restore_drill')==='pass','real media import and credential isolation');
    assert140(result140($report,'deployment.immutable_code')==='pass','checked assets');
    $driver=static function(string $url): array {
        $route=parse_url($url,PHP_URL_PATH);
        if ($route==='/health/live') return [200,['HTTP/1.1 200 OK'],'{"status":"ok"}'];
        if ($route==='/health/ready') return [200,['HTTP/1.1 200 OK','Cache-Control: no-store'],'{"status":"ready"}'];
        if ($route==='/admin') return [200,['HTTP/1.1 200 OK',
            'Strict-Transport-Security: max-age=31536000','Cache-Control: no-store',
            "Content-Security-Policy: script-src 'none'; frame-ancestors 'none'",
            'Set-Cookie: CajeerLite=token; Secure; HttpOnly; SameSite=Lax'],'Admin'];
        return [in_array($route,['/admin/oidc/login','/admin/oidc/backchannel-logout'],true)?405:404,['HTTP/1.1 405 Method Not Allowed'],''];
    };
    $live=StableCMSAcceptance::run($root,$cms->load(),true,$driver);
    assert140($live['status']==='pending_manual' && !$live['production_accepted'],'live probes do not sign off human acceptance');
    assert140(result140($live,'live.http.ready')==='pass','live readiness probe');
    $asset=$root.'/public/assets/admin.css';chmod($asset,0666);
    $badAsset=StableCMSAcceptance::run($root,$cms->load());
    assert140(result140($badAsset,'deployment.immutable_code')==='fail','reject group writable public assets');
    chmod($asset,0644);
    $sitefile=$cms->file();chmod($sitefile,0644);
    assert140(result140(StableCMSAcceptance::run($root,$cms->load()),'storage.permissions')==='fail','reject public site data');
    chmod($sitefile,0600);
    $sourcefile=$root.'/src';$target=$root.'/dest';file_put_contents($sourcefile,'new');file_put_contents($target,'original');
    try { OperationFiles::copy($sourcefile,$target); throw new RuntimeException('Copy overwrote file'); }
    catch (RuntimeException $e) {assert140($e->getMessage()!=='Copy overwrote file','copy must not overwrite existing files');}
    assert140(file_get_contents($target)==='original','copy preserves existing bytes');
    unlink($target);OperationFiles::copy($sourcefile,$target);
    assert140(file_get_contents($target)==='new','copy works with a new path');
    assert140((fileperms($target)&0077)===0,'copy has private mode');
    $archive=$ops->backupPath($backup);$tampered=$root.'/padding.tar';
    $bytes=file_get_contents($archive);$size=octdec(trim(substr($bytes,124,12)," \\0"));
    $offset=512+$size;
    if ($size%512===0) throw new RuntimeException('Test fixture must have TAR padding');
    $bytes[$offset]='X';file_put_contents($tampered,$bytes);chmod($tampered,0600);
    try { $ops->verifyFile($tampered);throw new RuntimeException('Nonzero TAR padding accepted'); }
    catch (RuntimeException $e) {assert140($e->getMessage()!=='Nonzero TAR padding accepted','TAR padding must be zero');}
    echo "CMS Experience Stable 1.4.0: release gate, isolated real restoration, live probes, private files, no-clobber copy and TAR integrity OK\n";
} finally {cleanup140($root);}
