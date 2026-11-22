<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Auth\{OwnerIdentity,OidcClient,SessionRegistry};

function ensure119(bool $ok, string $why): void { if (!$ok) throw new RuntimeException('Concurrency: '.$why); }
function clean119(string $path): void {
    if (is_link($path)||is_file($path)) { unlink($path); return; }
    if (is_dir($path)) { foreach (new FilesystemIterator($path) as $file) clean119($file->getPathname());rmdir($path); }
}
$root=sys_get_temp_dir().'/lite-concurrent-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);
try {
    $app=new Lite($root);
    $app->install('RaceSafetyPassword119','https://lite.example.test','Concurrent admin');
    $old=$app->load();
    $worker=$root.'/worker.php';
    file_put_contents($worker, <<<'WORKER'
<?php
require $argv[1].'/bootstrap.php';
$app = new CajeerEngine\Lite($argv[2]);
if ($argv[3] === 'bind') {
    try {
        $app->change(static fn(array $site):array => CajeerEngine\Auth\OwnerIdentity::bind($site,
            ['iss'=>CajeerEngine\Auth\OidcClient::ISSUER,'sub'=>$argv[4]], $argv[5]));
        echo 'bound';
    } catch (RuntimeException $error) { echo 'conflict'; }
} else {
    $site=$app->load();
    $reg=new CajeerEngine\Auth\SessionRegistry($argv[2]);
    $session=[];
    $reg->issue($session,$site,['HTTP_USER_AGENT'=>$argv[4]]);
    echo CajeerEngine\Auth\SessionRegistry::currentId($session);
}
WORKER);
    $run=static function(array $args)use($worker,$root): array {
        $cmd=[PHP_BINARY,$worker,dirname(__DIR__),$root,...$args];
        $desc=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $p=proc_open($cmd,$desc,$pipes,$root);
        if (!is_resource($p)) throw new RuntimeException('Cannot spawn integration worker');
        fclose($pipes[0]);
        return [$p,$pipes[1],$pipes[2]];
    };
    $jobs=[];
    foreach (['racing-owner-A','racing-owner-B'] as $owner) {
        $jobs[]=$run(['bind',$owner,$old['identity']['generation']]);
    }
    $outputs=[];
    foreach ($jobs as [$p,$stdout,$stderr]) {
        $outputs[]=trim(stream_get_contents($stdout));
        $error=stream_get_contents($stderr);
        fclose($stdout); fclose($stderr);
        ensure119(proc_close($p)===0, 'worker failed: '.$error);
    }
    sort($outputs);
    ensure119($outputs===['bound','conflict'], 'exactly one competing owner write must succeed');
    $site=$app->load();
    ensure119(in_array($site['identity']['owner']['sub'],['racing-owner-A','racing-owner-B'],true),
        'winning identity persisted without partial write');
    ensure119($site['identity']['generation']!==$old['identity']['generation'], 'generation rotated');
    $ids=[];$jobs=[];
    for($n=0;$n<10;$n++) $jobs[]=$run(['session','Device-'.$n]);
    foreach ($jobs as [$p,$stdout,$stderr]) {
        $id=trim(stream_get_contents($stdout)); $err=stream_get_contents($stderr);
        fclose($stdout); fclose($stderr);
        ensure119(proc_close($p)===0, 'session worker failed: '.$err);
        ensure119(preg_match('/\A[a-f0-9]{64}\z/D',$id)===1,'worker session token');
        $ids[]=$id;
    }
    ensure119(count(array_unique($ids))===10,'unique session leases');
    $registry=new SessionRegistry($root);
    ensure119($registry->inspect($app->load())['active']===10, 'locked session writes do not lose concurrent devices');
    $raw=file_get_contents($root.'/storage/auth/sessions.json');
    ensure119(!str_contains($raw,'RaceSafetyPassword119') && !str_contains($raw,'racing-owner'), 'registry contains no secrets or user identities');
} finally { clean119($root); }
echo "Lite 1.2.0 multi-process owner CAS and 10 simultaneous session writes OK\n";
