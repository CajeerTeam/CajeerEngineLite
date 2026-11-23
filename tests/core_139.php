<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Media\MediaLibrary;
use CajeerEngine\Operations\{CmsOperations,TarPackage};
use CajeerEngine\Security\{AdminRequestGuard,RequestRejected,PrivateArchiveDownload};
use CajeerEngine\Storage\SiteState;

function expect139(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException('1.3.9: '.$reason);
}
function denied139(callable $callback, int $status, string $reason): void
{
    try { $callback(); }
    catch (RequestRejected $exception) {
        expect139($exception->httpStatus===$status, $reason.' returned '.$exception->httpStatus);
        return;
    }
    throw new RuntimeException('1.3.9: request accepted: '.$reason);
}
function rejected139(callable $callback,string $reason): void
{
    try { $callback(); }
    catch (RuntimeException|InvalidArgumentException|JsonException) { return; }
    throw new RuntimeException('1.3.9: expected rejection: '.$reason);
}
function cleanup139(string $root): void
{
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($root);
}

expect139(version_compare(Lite::VERSION,'1.3.9','>=') && SiteState::SCHEMA===14,'version with compatible data schema');
$origin='http://cms.test:18839';
$normal=['HTTP_HOST'=>'cms.test:18839','CONTENT_LENGTH'=>'64', 'HTTP_ORIGIN'=>$origin, 'HTTP_SEC_FETCH_SITE'=>'same-origin'];
AdminRequestGuard::check($origin,$normal,'/admin','POST');
AdminRequestGuard::check($origin,['HTTP_HOST'=>'CMS.TEST:18839','HTTP_REFERER'=>$origin.'/admin?view=pages'], '/admin','POST');
AdminRequestGuard::check('https://cms.test',['HTTP_HOST'=>'cms.test:443','HTTPS'=>'on','HTTP_ORIGIN'=>'https://cms.test'],'/admin/media','POST');
AdminRequestGuard::check('http://[::1]:18839',['HTTP_HOST'=>'[::1]:18839'],'/admin','GET');
// Failing closed for unknown authorities, origins, browsers and bodies.
denied139(fn()=>AdminRequestGuard::check($origin,['HTTP_HOST'=>'attacker.test:18839'],'/admin','GET'),421,'Host alias');
denied139(fn()=>AdminRequestGuard::check($origin,['HTTP_HOST'=>'evil@cms.test:18839'],'/admin','GET'),421,'Host userinfo');
denied139(fn()=>AdminRequestGuard::check($origin,['HTTP_HOST'=>'cms.test:18839/evil'],'/admin','GET'),421,'Host path');
denied139(fn()=>AdminRequestGuard::check($origin,['HTTP_HOST'=>'cms.test:80'],'/admin','GET'),421,'Host port');
denied139(fn()=>AdminRequestGuard::check('https://cms.test',['HTTP_HOST'=>'cms.test','HTTP_X_FORWARDED_PROTO'=>'https'],'/admin','GET'),403,'spoofed proxy TLS');
denied139(fn()=>AdminRequestGuard::check('https://cms.test',['HTTP_HOST'=>'cms.test','HTTPS'=>'OFF'],'/admin','GET'),403,'ambiguous TLS value');
denied139(fn()=>AdminRequestGuard::check($origin, $normal+['HTTP_REFERER'=>'http://evil.test/admin'], '/admin','POST'),403,'cross-origin Referer');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','HTTP_ORIGIN'=>'http://evil.test'], '/admin','POST'),403,'cross-origin Origin');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','HTTP_ORIGIN'=>'null'], '/admin','POST'),403,'opaque Origin');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','HTTP_SEC_FETCH_SITE'=>'cross-site'], '/admin','POST'),403,'fetch metadata');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','CONTENT_LENGTH'=>'350001'], '/admin','POST'),413,'form size limit');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','CONTENT_LENGTH'=>'8500001'], '/admin/media','POST'),413,'media size limit');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','CONTENT_LENGTH'=>'116000001'], '/admin/operations','POST'),413,'import size limit');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','CONTENT_LENGTH'=>'-1'], '/admin','POST'),413,'negative body length');
denied139(fn()=>AdminRequestGuard::check($origin, ['HTTP_HOST'=>'cms.test:18839','HTTP_TRANSFER_ENCODING'=>'chunked'], '/admin','POST'),413,'unbounded streaming');
// OIDC backchannel logout is a signed, token-verified server-to-server request.
AdminRequestGuard::check('https://cms.test', ['HTTP_HOST'=>'cms.test','HTTPS'=>'on','HTTP_ORIGIN'=>'https://id.example.test'], '/admin/oidc/backchannel-logout','POST');

$original = sys_get_temp_dir().'/lite139-src-'.bin2hex(random_bytes(8));
$target = sys_get_temp_dir().'/lite139-target-'.bin2hex(random_bytes(8));
mkdir($original,0700); mkdir($target,0700);
try {
    $app = new Lite($original);
    $app->install('InitialPassword139!', 'https://source.test','Site A');
    $media = new MediaLibrary($original);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
    $image = $original.'/upload.png'; file_put_contents($image,$png);
    $asset = $media->ingest($image,'Logo.png');
    $app->change(static function(array $state)use($asset):array {
        $state['pages']['/']['title']='Integrated launch';
        $state['pages']['/']['body']='Original text';
        $state['pages']['/']['description']='Original description';
        $state['seo']['default_image']=$asset['id'];
        return $state;
    });
    $service = new CmsOperations($original);
    $export = $service->exportArchive();
    $backup = $service->createBackup();
    expect139($service->verifyFile($export)['kind']==='export','portable archive valid');
    expect139($service->verifyBackup($backup)['kind']==='backup','private archive valid');
    $private=$service->backupPath($backup);
    [$handle,$archiveSize]=PrivateArchiveDownload::open($private);
    expect139($archiveSize===filesize($private) && is_resource($handle),'download uses verified regular-file descriptor');
    fclose($handle);
    $link=$original.'/download-hardlink.tar'; link($private,$link);
    rejected139(fn()=>PrivateArchiveDownload::open($private),'refuse hardlinked archive');
    unlink($link);
    $link=$original.'/download-symlink.tar'; symlink($private,$link);
    rejected139(fn()=>PrivateArchiveDownload::open($link),'refuse symbolic-link archive');
    unlink($link);
    chmod($private,0644); clearstatcache(true,$private);
    rejected139(fn()=>PrivateArchiveDownload::open($private),'refuse world-readable private archive');
    chmod($private,0600); clearstatcache(true,$private);
    $staged=$original.'/unpacked';mkdir($staged,0700);
    $tar=TarPackage::unpack($export,$staged);
    $site=json_decode(file_get_contents($tar['files']['site.json']['path']),true,512,JSON_THROW_ON_ERROR);
    expect139(!password_verify('InitialPassword139!',$site['password']),'password scrubbed on export');
    expect139($site['identity']['owner']===null && $site['seo']['default_image']===$asset['id'],'SEO preserved, SSO scrubbed');

    $destination = new Lite($target);
    $destination->install('DestinationPassword139!','https://target.test','Site B');
    $before = $destination->load();
    $restore = new CmsOperations($target);
    // A malformed session registry must fail after site commit, triggering a
    // durable site rollback AND an index/files rollback (not a partial import).
    mkdir($target.'/storage/auth',0700);
    $unsafe=$target.'/storage/auth/sessions.json';
    symlink($target.'/storage/site.json',$unsafe);
    rejected139(fn()=>$restore->importFile($export),'session registry failure rolls back import');
    expect139($destination->load()===$before,'site JSON rolled back on session failure');
    expect139((new MediaLibrary($target))->listing()===[],'media index rolled back on session failure');
    expect139(!file_exists($target.'/storage/media/files/'.$asset['id'].'.png'),'new media removed after failed restore');
    unlink($unsafe);
    $restore->importFile($export);
    $imported=$destination->load();
    expect139($imported['pages']['/']['title']==='Integrated launch','site content imported');
    expect139($imported['seo']['default_image']===$asset['id'],'SEO reference imported');
    expect139(password_verify('DestinationPassword139!',$imported['password']),'target credentials unchanged');
    expect139($imported['settings']['url']==='https://target.test','target origin unchanged');
    expect139((new MediaLibrary($target))->read($asset['id'])['bytes']===$png,'identical imported media');
    $service->removeExport($export);
    echo "CMS Experience Hardening 1.3.9: authority, origins, size limits, OIDC exception, full export, SEO, media, session-failure rollback and portable import OK\n";
} finally { cleanup139($original); cleanup139($target); }
