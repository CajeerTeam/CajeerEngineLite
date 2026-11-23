<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Admin\AdminExperience;
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

function verify131(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Admin Experience: '.$message);
}
verify131(version_compare(Lite::VERSION, '1.3.1', '>=') && SiteState::SCHEMA === 13, 'compatible release');
foreach (['dashboard','pages','ai','analytics','integrations','security','settings'] as $view) {
    verify131(AdminExperience::view(['view'=>$view]) === $view, 'navigation view '.$view);
}
foreach (['edit'=> '/','new'=>'1','ai'=>'1','ai_release'=>'1','analytics'=>'1','auth_acceptance'=>'1'] as $key=>$value) {
    $expected=match($key) {'edit','new'=>'pages','ai','ai_release'=>'ai','analytics'=>'analytics',default=>'security'};
    verify131(AdminExperience::view([$key=>$value])===$expected, 'legacy deep link '.$key);
}
foreach (['invalid', '', 'ai/../../secret', '../admin', '<script>'] as $bad) {
    try {AdminExperience::view(['view'=>$bad]);throw new RuntimeException('accepted invalid view');}
    catch (InvalidArgumentException) {}
}
try {AdminExperience::view(['view'=>['dashboard']]);throw new RuntimeException('array view accepted');}
catch (InvalidArgumentException) {}
verify131(AdminExperience::view([])==='dashboard', 'dashboard is default');
$root=sys_get_temp_dir().'/cajeer-admin131-'.bin2hex(random_bytes(7));
mkdir($root,0700);
try {
    $site=new Lite($root);
    $site->install('AdminExperiencePassword131!', 'http://127.0.0.1:9131', 'Demo <unsafe>');
    $state=$site->load();
    $state['pages']['/']['title']='Home <script>alert(1)</script>';
    $state['pages']['/']['body']='Private contents';
    $state['pages']['/about']=['path'=>'/about','title'=>'About','description'=>'','body'=>'',
        'published'=>false,'menu'=>false];
    $html=AdminExperience::dashboard($state,2,false,false);
    verify131(str_contains($html,'Dashboard — обзор сайта'), 'real dashboard title');
    verify131(str_contains($html,'2 / 5')===false && str_contains($html,'0 / 5'), 'real provider count');
    verify131(str_contains($html,'Черновики') && str_contains($html,'Лимит: 100'), 'count and limits');
    verify131(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)</script>'), 'escaping page title');
    verify131(!str_contains($html,'Private contents') && !str_contains($html,$state['password']), 'no content or secrets exposed');
    $panels=['pages'=>'<p>Real page controls</p>','security'=>'<p>Sessions</p>'];
    $result=AdminExperience::sections($panels,'pages','<p>Summary</p>','<p>Error</p>');
    verify131(str_contains($result,'id="admin-view-pages" class="admin-view" aria-label="Страницы"'), 'active panel shown');
    verify131(str_contains($result,'id="admin-view-dashboard" class="admin-view" hidden'), 'inactive panel hidden');
    verify131(str_contains($result,'Real page controls') && str_contains($result,'Sessions'), 'existing forms preserved');
    $frame=AdminExperience::frame('Editor','<p>Content</p>','/admin/ai/editor','ai',str_repeat('a',64));
    verify131(str_contains($frame,'aria-current="page"') && str_contains($frame,'href="/admin?view=dashboard"'), 'working global navigation');
    verify131(str_contains($frame,'action="/admin"') && str_contains($frame,'name="csrf"'), 'protected logout control');
    echo "Admin Experience 1.3.1 navigation, dashboard, XSS, deep links, compatibility OK\n";
} finally {
    foreach (glob($root.'/storage/*') ?: [] as $path) if (is_file($path)) unlink($path);
    if (is_dir($root.'/storage')) rmdir($root.'/storage');
    rmdir($root);
}
