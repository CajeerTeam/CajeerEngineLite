<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Update\{UpdatePackage,UpdateEngine,OperationFiles,OperationLock};

function ok144(bool $yes,string $message):void {if(!$yes)throw new RuntimeException('1.4.4: '.$message);}
function reject144(callable $f,string $message):void {try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection: '.$message);}
function clean144(string $dir):void {
    if(!is_dir($dir))return;
    foreach(new FilesystemIterator($dir) as $item) {
        $name=$item->getPathname();
        if($item->isDir()&&!$item->isLink())clean144($name);else unlink($name);
    }
    rmdir($dir);
}
function write144(string $root,string $path,string $body):void {
    $dest=$root.'/'.$path;
    if(!is_dir(dirname($dest)))mkdir(dirname($dest),0755,true);
    file_put_contents($dest,$body);
}
function fixtures144(string $base,string $candidate):void {
    foreach([$base,$candidate] as $root)mkdir($root,0700);
    write144($base,'VERSION',"1.4.3\n");
    write144($base,'core/Lite.php',"<?php class ReleaseFixture { public const VERSION = '1.4.3'; }\n");
    write144($base,'README.md',"Installed original README\n");
    write144($base,'public/index.php',"<?php echo 'before';\n");
    write144($base,'templates/default/old.cjr','Deprecated template');
    write144($candidate,'VERSION',"1.4.4\n");
    write144($candidate,'core/Lite.php',"<?php class ReleaseFixture { public const VERSION = '1.4.4'; }\n");
    write144($candidate,'README.md',"Updated README\n");
    write144($candidate,'public/index.php',"<?php echo 'after';\n");
    write144($candidate,'core/NewFeature.php',"<?php class NewFeature144 {}\n");
}

ok144(Lite::VERSION==='1.4.4','new release version');
ok144(!UpdatePackage::permitted('storage/site.json'),'no state updates');
ok144(!UpdatePackage::permitted('public/backdoor.php'),'no arbitrary public PHP');
ok144(!UpdatePackage::permitted('core/../../.env'),'no path escape');
ok144(!UpdatePackage::permitted('core/Node/.env'),'no arbitrary file extension');
$root=sys_get_temp_dir().'/cajeer-update144-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $base=$root.'/base';$candidate=$root.'/candidate';fixtures144($base,$candidate);
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    ok144($key instanceof OpenSSLAsymmetricKey,'RSA test signing key');
    openssl_pkey_export($key,$private);
    $details=openssl_pkey_get_details($key);
    file_put_contents($root.'/signing.pem',$private);chmod($root.'/signing.pem',0600);
    file_put_contents($root.'/trusted.pem',$details['key']);chmod($root.'/trusted.pem',0600);
    putenv('CAJEER_UPDATE_TRUSTED_KEY_FILE='.$root.'/trusted.pem');
    $other=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    file_put_contents($root.'/wrong.pem',openssl_pkey_get_details($other)['key']);
    $package=$root.'/update.cjrupdate';
    $built=UpdatePackage::build($base,$candidate,$package,$root.'/signing.pem');
    ok144($built['files']===6,'signed release contains actual differences, addition and deletion');
    $engine=new UpdateEngine($base);
    ok144($engine->verify($package)['to']==='1.4.4','cryptographic signature and hash verification');
    reject144(fn()=>UpdatePackage::verify($package,$root.'/wrong.pem'),'wrong signing key');
    $tampered=$root.'/tampered.cjrupdate';
    $bytes=file_get_contents($package);
    $bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);
    file_put_contents($tampered,$bytes);
    reject144(fn()=>$engine->verify($tampered),'damaged member bytes');
    ok144(file_get_contents($base.'/README.md')==="Installed original README\n",'verification never changes files');
    reject144(fn()=>$engine->install($package),'explicit offline confirmation');
    putenv('CAJEER_UPDATE_CONFIRM=INSTALL');
    // The updater acquires the same lease as CMS import/export.
    $lease=OperationLock::acquire($base,true);
    try {reject144(fn()=>$engine->install($package),'concurrent operation lease');}
    finally {OperationLock::release($lease);}
    // Malformed signed PHP must be rejected before any live file mutation.
    write144($candidate,'core/Invalid144.php','<?php function broken( {');
    $invalidPackage=$root.'/invalid-php.cjrupdate';
    UpdatePackage::build($base,$candidate,$invalidPackage,$root.'/signing.pem');
    reject144(fn()=>$engine->install($invalidPackage),'signed release with PHP syntax error');
    ok144(UpdatePackage::version($base)==='1.4.3' &&
        file_get_contents($base.'/README.md')==="Installed original README\n",'PHP lint stops release without mutation');
    unlink($candidate.'/core/Invalid144.php');
    // Signing key material cannot be left world-readable on the build host.
    chmod($root.'/signing.pem',0644);
    reject144(fn()=>UpdatePackage::build($base,$candidate,$root.'/world-readable.cjrupdate',$root.'/signing.pem'),'insecure signing-key file permissions');
    chmod($root.'/signing.pem',0600);
    // Real site state with credentials is preserved throughout code releases.
    (new Lite($base))->install('testpassword_144!', 'http://localhost:8080', 'Fixture');
    $siteRaw=file_get_contents($base.'/storage/site.json');
    $incompatible=json_decode($siteRaw,true,512,JSON_THROW_ON_ERROR);
    $incompatible['schema']=13;
    OperationFiles::write($base.'/storage/site.json',json_encode($incompatible,JSON_THROW_ON_ERROR));
    reject144(fn()=>$engine->verify($package),'incompatible existing storage schema');
    OperationFiles::write($base.'/storage/site.json',$siteRaw);
    $siteHash=hash_file('sha256',$base.'/storage/site.json');
    $installed=$engine->install($package);
    ok144($installed['from']==='1.4.3'&&$installed['to']==='1.4.4','real installation');
    ok144(UpdatePackage::version($base)==='1.4.4','VERSION installed');
    ok144(is_file($base.'/core/NewFeature.php'),'new PHP file published');
    ok144(!is_file($base.'/templates/default/old.cjr'),'removed template deleted');
    ok144(file_get_contents($base.'/README.md')==="Updated README\n",'changed doc published');
    ok144(hash_file('sha256',$base.'/storage/site.json')===$siteHash,'site data unmodified');
    ok144(is_file($base.'/storage/app/updates/history/'.$installed['id'].'/site.json'),'pre-update site snapshot exists');
    reject144(fn()=>$engine->install($package),'refuse replay/downgrade');
    putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');
    $rolled=$engine->rollback($installed['id']);
    ok144($rolled['status']==='rolled_back'&&UpdatePackage::version($base)==='1.4.3','real code rollback');
    ok144(!is_file($base.'/core/NewFeature.php'),'new file removed on rollback');
    ok144(file_get_contents($base.'/templates/default/old.cjr')==='Deprecated template','removed template restored');
    ok144(file_get_contents($base.'/README.md')==="Installed original README\n",'old code restored');
    ok144(hash_file('sha256',$base.'/storage/site.json')===$siteHash,'site unchanged after rollback');
    reject144(fn()=>$engine->rollback($installed['id']),'repeat rollback cannot run');
    putenv('CAJEER_UPDATE_CONFIRM=INSTALL');
    $again=$engine->install($package);
    // Simulate an abnormal shutdown after file publication but before journal cleanup.
    OperationFiles::json($base.'/storage/app/updates/active.json',['id'=>$again['id'],'mode'=>'install']);
    ok144($engine->recover()['status']==='recovered','interrupted update recovered from snapshot');
    ok144(UpdatePackage::version($base)==='1.4.3','recovery restores original release');
    ok144($engine->status()['pending']===null,'recovery journal removed');
    // Failure occurs during actual publication, after README was changed.
    // A file blocking a new directory is not part of the managed file inventory.
    write144($candidate,'core/BlockedDirectory/Foo.php',"<?php // should fail\n");
    write144($base,'core/BlockedDirectory','Cannot become a directory');
    $failPackage=$root.'/broken-path.cjrupdate';
    UpdatePackage::build($base,$candidate,$failPackage,$root.'/signing.pem');
    reject144(fn()=>$engine->install($failPackage),'filesystem error triggers rollback');
    ok144(UpdatePackage::version($base)==='1.4.3','failure rollback preserves version');
    ok144(file_get_contents($base.'/README.md')==="Installed original README\n",'failure rollback preserves files');
    ok144($engine->status()['pending']===null,'automatic rollback cleared journal');
    // Edited target state must not be clobbered during a manual rollback.
    unlink($base.'/core/BlockedDirectory');
    $success=$engine->install($package);
    file_put_contents($base.'/README.md','Locally edited after update');
    putenv('CAJEER_UPDATE_CONFIRM=ROLLBACK');
    reject144(fn()=>$engine->rollback($success['id']),'conflicting edit protects operator changes');
    ok144($engine->status()['pending']['mode']==='rollback','failed rollback retains recovery journal');
    // Operator resolves conflict and recovery runs against the journal.
    write144($base,'README.md',"Updated README\n");
    ok144($engine->recover()['status']==='recovered','conflict resolution recovery');
    ok144(UpdatePackage::version($base)==='1.4.3','final recovery version');
    echo "Update Engine 1.4.4: signature, per-file SHA, base version, schema, lock, install, rollback, crash recovery, tampering and conflict OK\n";
} finally {
    putenv('CAJEER_UPDATE_TRUSTED_KEY_FILE');putenv('CAJEER_UPDATE_CONFIRM');
    clean144($root);
}
