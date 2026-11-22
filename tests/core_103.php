<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use CajeerEngine\Lite;
use CajeerEngine\Consent\{ConsentConfig,ConsentCookie};
use CajeerEngine\Storage\{SiteState,SiteRepository};
use CajeerEngine\Update\OperationFiles;

function ok103(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function throws103(callable $fn, string $why): void {
    try { $fn(); } catch (Throwable) { return; }
    throw new RuntimeException('Expected rejection: '.$why);
}
function clean103(string $path): void {
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) clean103($entry->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-consent-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try {
    $app=new Lite($root);
    $app->install('StrongPassword123', 'https://lite.example.org', 'Тестовый сайт');
    $site=$app->load();
    ok103(Lite::VERSION === '1.1.6' && SiteState::SCHEMA === 11 && $site['schema'] === 11, 'version or schema');
    ok103($site['consent'] === ConsentConfig::DEFAULTS, 'new installation policy');
    ok103(str_contains((new Lite(dirname(__DIR__)))->render($site, $site['pages']['/']), 'data-consent-action="accept"'), 'initial banner is missing');

    $now=time();
    $policy=$site['consent'];
    ok103(ConsentCookie::read('v1.1.'.$now.'.0', $policy, $now) === [
        'necessary'=>true,'analytics'=>false,'marketing'=>false,'recordings'=>false], 'reject cookie parsing');
    $all=ConsentCookie::read('v1.1.'.$now.'.7', $policy, $now);
    ok103($all !== null && ConsentCookie::mask($all) === 7, 'accept all cookie parsing');
    ok103(ConsentCookie::mask(ConsentCookie::read('v1.1.'.$now.'.5', $policy, $now)) === 5, 'partial consent');
    foreach (["v1.1.$now.8", "v1.2.$now.7", 'v1.1.1000000000.7', '<script>', '', [], "v1.1.$now.0x"] as $invalid) {
        ok103(ConsentCookie::read($invalid, $policy, $now) === null, 'invalid or old cookie accepted');
    }
    ok103(ConsentCookie::read('v1.1.'.($now-181*86400).'.7', $policy, $now) === null, 'expired choice accepted');
    ok103(ConsentCookie::read('v1.1.'.($now+61).'.7', $policy, $now) === null, 'future-dated choice accepted');
    $_COOKIE[ConsentCookie::NAME]='v1.1.'.$now.'.5';
    $siteView=(new Lite(dirname(__DIR__)))->render($site, $site['pages']['/']);
    ok103(str_contains($siteView, 'data-mask="5"') && str_contains($siteView, 'data-mask="5"  hidden'), 'SSR did not honor saved choice');
    unset($_COOKIE[ConsentCookie::NAME]);

    $input=['enabled'=>'1','days'=>'60','title'=>'Параметры <cookie>','description'=>'Понятное описание','privacy_url'=>'/privacy'];
    $updated=ConsentConfig::fromPost($input, $policy);
    ok103($updated['revision'] === 2 && $updated['days'] === 60, 'revised settings');
    ok103(ConsentConfig::fromPost($input, $updated)['revision'] === 2, 'unchanged settings bumped revision');
    throws103(fn()=>ConsentConfig::fromPost([...$input, 'privacy_url'=>'https://evil.example'], $policy), 'external privacy URL');
    throws103(fn()=>ConsentConfig::fromPost([...$input, 'days'=>'9999'], $policy), 'duration limit');
    throws103(fn()=>ConsentConfig::fromPost([...$input, 'enabled'=>[]], $policy), 'array flag');
    $app->change(static function(array $state) use($updated): array { $state['consent']=$updated; return $state; });
    $site=$app->load();
    ok103($site['consent']['revision'] === 2, 'updated consent not persisted');
    ok103(ConsentCookie::read('v1.1.'.$now.'.5', $site['consent'], $now) === null, 'outdated policy still valid');
    $html=(new Lite(dirname(__DIR__)))->render($site, $site['pages']['/']);
    ok103(str_contains($html, 'Параметры &lt;cookie&gt;') && !str_contains($html, 'Параметры <cookie>'), 'escaped config');
    $disabled=ConsentConfig::fromPost([...$input, 'enabled'=>null], $updated);
    $app->change(static function(array $state) use($disabled): array { $state['consent']=$disabled; return $state; });
    $site=$app->load();
    ok103(!str_contains((new Lite(dirname(__DIR__)))->render($site,$site['pages']['/']), 'id="cookie-consent"'), 'disabled banner still present');

    // Upgrade a REAL schema 2 snapshot; migration must preserve original bytes in a backup.
    $before=$site;
    $before['schema']=2;
    unset($before['consent'], $before['integrations']);
    $raw=SiteState::encode($before);
    OperationFiles::write($app->file(),$raw,0600);
    ok103($app->migrate(), 'v2 migration did not run');
    $site=$app->load();
    ok103($site['schema'] === 11 && $site['consent'] === ConsentConfig::DEFAULTS, 'migration v2->v3 wrong');
    ok103($site['pages'] === $before['pages'] && $site['settings'] === $before['settings'] && $site['password'] === $before['password'], 'migration changed existing content or auth');
    $backupName=null;
    foreach ($app->backups()->names() as $name) {
        $info=$app->backups()->verify($name);
        if ($info['reason'] === 'migration' && $info['schema'] === 2) $backupName=$name;
    }
    ok103($backupName !== null, 'v2 snapshot not backed up');
    ok103(str_contains((string)file_get_contents($root.'/storage/backups/'.$backupName),base64_encode($raw)), 'backup lost original bytes');
    $app->backups()->restore($backupName);
    ok103($app->load()['schema'] === 11 && $app->load()['consent'] === ConsentConfig::DEFAULTS, 'restore v2 did not upgrade');
    $state=$app->load();
    $state['consent']['days']=400;
    throws103(fn()=>$app->change(fn()=> $state), 'invalid durable policy');
    ok103($app->load()['consent']['days'] === 180, 'invalid update reached disk');
    echo "Lite cookie consent legacy regression OK\n";
} finally { clean103($root); }
