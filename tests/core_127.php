<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter,SupportDiagnostics,TechnicalSupport,SupportStore,SupportView};
use CajeerEngine\Storage\SiteState;
function must127(bool $value,string $name):void {if(!$value)throw new RuntimeException('Support 1.2.7: '.$name);}
function reject127(callable $f,string $name):void {try {$f();}catch(InvalidArgumentException|RuntimeException){return;}throw new RuntimeException('Unsafe support accepted: '.$name);}
function clean127(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(is_dir($path)){foreach(new FilesystemIterator($path) as $p)clean127($p->getPathname());rmdir($path);}}
must127(version_compare(Lite::VERSION,'1.2.7','>=') && SiteState::SCHEMA===13,'version and JSON schema');
$root=sys_get_temp_dir().'/cajeer-support-'.bin2hex(random_bytes(8));
mkdir($root.'/templates/default',0700,true);
copy(dirname(__DIR__).'/templates/default/page.cjr',$root.'/templates/default/page.cjr');
try {
    $app=new Lite($root);$app->install('SupportAdminPassword127','https://example.test','Support test');
    $config=AIConfig::DEFAULTS;$config['enabled']=true;$config['daily_requests']=5;$config['daily_tokens']=50000;
    $config['max_input_chars']=AIConfig::DEFAULTS['max_input_chars'];
    $app->change(static function(array $site)use($config):array {$site['ai']=$config;return $site;});
    $site=$app->load();$snapshot=SupportDiagnostics::collect($root,$site);
    must127(isset($snapshot['checks']['cms.state'],$snapshot['checks']['auth.state'],
        $snapshot['checks']['integration.permissions.assets']),'real diagnostic invocation');
    must127(($snapshot['counts']['pass']+$snapshot['counts']['warn']+$snapshot['counts']['fail'])===count($snapshot['checks']),'exact status accounting');
    $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
    foreach (['SupportAdminPassword127','https://example.test','/tmp/','access_token','secret','storage/site.json'] as $forbidden) {
        must127(!str_contains($json,$forbidden),'sensitive diagnostic exclusion: '.$forbidden);
    }
    must127($snapshot['checks']['integration.permissions.assets']==='fail','actually missing runtime detected');
    must127(count(SupportDiagnostics::firstAid('auth',$snapshot))>=2,'usable offline instructions');
    reject127(fn()=>SupportDiagnostics::category('passwords'),'unsupported issue category');
    $driverCalls=0;
    $driver=static function(string $url,string $method,array $headers,?string $body)use(&$driverCalls):array {
        $driverCalls++;
        must127($url==='https://api.openai.com/v1/responses' && $method==='POST','real Responses route');
        $payload=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
        must127(($payload['store']??null)===false && !isset($payload['tools']),'model does not persist or execute tools');
        must127(($payload['text']['format']['name']??null)==='cajeer_technical_support','structured support mode');
        must127(($payload['text']['format']['schema']['required']??null)===['summary','causes','steps','cautions'],'required fields');
        must127(str_contains($payload['input'],'"cms.state":"pass"') &&
            str_contains($payload['input'],'"integration.permissions.assets":"fail"'),'real runtime evidence');
        foreach (['SupportAdminPassword127','https://example.test','/tmp/','storage/site.json','ACTUAL_SECRET_TOKEN_VALUE'] as $private) {
            must127(!str_contains($payload['input'],$private),'no private data in model prompt');
        }
        $fields=['summary'=>'Проверка загрузчиков не прошла.','causes'=>'Возможно, отсутствует каталог public/assets.',
            'steps'=>'Проверьте состав релиза и права на public/assets.','cautions'=>'Не отключайте CSRF и TLS.'];
        return [200,json_encode(['status'=>'completed','model'=>'gpt-5-mini','output'=>[['type'=>'message',
            'content'=>[['type'=>'output_text','text'=>json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]],
            'usage'=>['input_tokens'=>90,'output_tokens'=>40]],JSON_THROW_ON_ERROR)];
    };
    $limiter=new UsageLimiter($root);
    $result=TechnicalSupport::generate('general',$snapshot,$site,
        new OpenAIClient($driver,'sk-TestSupportKeyForIntegration127'),$limiter);
    must127($driverCalls===1 && $result['input_tokens']===90 && $result['output_tokens']===40,'real generation and usage');
    must127($limiter->status()['requests']===1 && $limiter->status()['tokens']===130,'durable quota charged');
    $store=new SupportStore($root,$site['identity']['generation']);
    $store->save('general',$snapshot,$result);
    must127($store->read('general',$snapshot)['fields']===$result['fields'],'persistent read');
    must127((fileperms(glob($root.'/storage/ai/technical-support/*.json')[0])&0077)===0,'private result file');
    must127((fileperms($root.'/storage/ai/technical-support')&0077)===0,'private result directory');
    $view=SupportView::render('general',$snapshot,$result,str_repeat('a',64),true);
    must127(str_contains($view,'AI Technical Support') && str_contains($view,'name="action" value="generate"') &&
        str_contains($view,'PASS') && str_contains($view,'FAIL'),'working support interface');
    $evil=$result;$evil['fields']['summary']='<script>alert(1)</script>';
    $store->save('general',$snapshot,$evil);
    $view=SupportView::render('general',$snapshot,$store->read('general',$snapshot),str_repeat('a',64),true);
    must127(!str_contains($view,'<script>') && str_contains($view,'&lt;script&gt;'),'XSS escaping');
    $changed=$snapshot;$changed['checks']['cms.state']='fail';$changed['counts']['pass']--;$changed['counts']['fail']++;
    must127($store->read('general',$changed)===null,'recomputed results invalidated by changes');
    must127((new SupportStore($root,str_repeat('a',64)))->read('general',$snapshot)===null,'owner isolation');
    reject127(fn()=>TechnicalSupport::generate('bad',$snapshot,$site,
        new OpenAIClient($driver,'sk-TestSupportKeyForIntegration127'),$limiter),'invalid category');
    $file=glob($root.'/storage/ai/technical-support/*.json')[0];chmod($file,0644);
    reject127(fn()=>$store->read('general',$snapshot),'world-readable history denied');
    chmod($file,0600);
    $store->delete('general',$snapshot);must127($store->read('general',$snapshot)===null,'delete history');
    symlink('/etc/passwd',$file);reject127(fn()=>$store->read('general',$snapshot),'symlink rejected');unlink($file);
    $corrupt=$result;$corrupt['fields']['steps']='';reject127(fn()=>$store->save('general',$snapshot,$corrupt),'empty output rejected');
    echo "Lite 1.2.7 real diagnostics, OpenAI structured support, quotas, local steps, owner isolation, XSS and filesystem safety OK\n";
} finally {clean127($root);}
