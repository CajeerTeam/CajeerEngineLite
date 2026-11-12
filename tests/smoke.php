<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Public\PublicPath;
function check(bool $ok): void { if(!$ok) throw new RuntimeException('Check failed'); }
$dir=sys_get_temp_dir().'/cajeer-lite-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
try {
    $a=new Lite($dir); $a->install('TestPassword123','http://localhost:8080','Test'); check($a->login('TestPassword123'));
    for($i=0;$i<5;$i++) check(!$a->login('wrong')); check(!$a->login('TestPassword123'));
    try { $a->install('TestPassword123','http://localhost:8080','Test'); throw new RuntimeException('Reinstall allowed'); } catch(InvalidArgumentException) {}
    $d=$a->load(); check($d['pages']['/']['published']);
    $d['pages']['/']['body']='<script>alert(1)</script>'; $site=new Lite(dirname(__DIR__));
    $html=$site->render($d,$d['pages']['/']); check(str_contains($html,'&lt;script&gt;')); check(!str_contains($html,'<script>'));
    foreach(['/admin','/assets/test','/%2f','/../a'] as $path) { try { PublicPath::content($path); throw new RuntimeException('Bad path accepted'); } catch(InvalidArgumentException) {} }
    echo "Smoke OK\n";
} finally { foreach(glob($dir.'/storage/*')?:[] as $f) unlink($f); rmdir($dir.'/storage'); rmdir($dir); }
