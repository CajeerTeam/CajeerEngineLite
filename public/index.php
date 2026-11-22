<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Consent\ConsentConfig;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig,ClarityConfig,MatomoConfig,PublicCsp,IntegrationDiagnostics};
$app=new Lite(dirname(__DIR__));
function e(string $s): string { return Lite::esc($s); }
function stop(int $status,string $msg): never { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }
function go(string $path): never { header('Location: '.$path,true,303); exit; }
function shell(string $title,string $html): never { header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).'</title><link rel="stylesheet" href="/assets/style.css"><body><header><a href="/">На сайт</a><a href="/admin">CajeerEngine Lite</a></header><main><h1>'.e($title).'</h1>'.$html.'</main></body></html>'; exit; }
function input(string $name,string $value='',string $type='text'): string { return '<label>'.e($name).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"></label>'; }
function token(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function form(string $action,string $html): string { return '<form method="post" action="/admin">'.token().'<input type="hidden" name="action" value="'.e($action).'">'.$html.'</form>'; }
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Content-Type: text/html; charset=utf-8');
try {
    $raw=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    if (!is_string($raw)) stop(400,'Bad path');
    try { $path=PublicPath::normalize($raw); } catch(InvalidArgumentException) { stop(400,'Bad path'); }
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD','POST'],true)) { header('Allow: GET, HEAD, POST'); stop(405,'Method not allowed'); }
    if ($path==='/health/live') { if($method==='POST') stop(405,'Method not allowed'); header('Content-Type: application/json'); echo '{"status":"ok"}'; exit; }
    if (!is_file($app->file())) stop(503,'Not installed. Run php bin/cajeer install.');
    $d=$app->load();
    if ($path==='/admin') {
        header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
        $secure=str_starts_with($d['settings']['url'],'https://') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        session_name('CajeerLite'); session_set_cookie_params(['path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Strict']); session_start();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        $auth=isset($_SESSION['auth'],$_SESSION['seen']) && hash_equals(hash('sha256',$d['password']),$_SESSION['auth']) && time()-$_SESSION['seen']<3600;
        if ($auth) $_SESSION['seen']=time();
        $error='';
        if ($method==='POST') {
            if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) stop(403,'Invalid CSRF token');
            $action=$_POST['action']??'';
            try {
                if ($action==='login' && !$auth) {
                    if ($app->login(is_string($_POST['password']??null)?$_POST['password']:'')) { session_regenerate_id(true); $_SESSION=['auth'=>hash('sha256',$d['password']),'seen'=>time(),'csrf'=>bin2hex(random_bytes(32))]; go('/admin'); }
                    http_response_code(401); $error='Неверный пароль или временная блокировка. Повторите через 5 минут.';
                } elseif (!$auth) stop(403,'Login required');
                elseif ($action==='logout') { $_SESSION=[]; session_destroy(); go('/admin'); }
                elseif ($action==='settings') { $settings=Lite::settings($_POST); $app->change(function($d)use($settings){$d['settings']=$settings;return $d;}); go('/admin'); }
                elseif ($action==='modules') {
                    $submitted = $_POST['module'] ?? [];
                    if (!is_array($submitted) || array_diff(array_keys($submitted), array_keys(ModuleConfig::DEFAULTS))) {
                        throw new InvalidArgumentException('Invalid module settings');
                    }
                    $app->change(static function(array $d) use ($submitted): array {
                        foreach (ModuleConfig::DEFAULTS as $key => $_) {
                            $value = $submitted[$key] ?? null;
                            if ($value !== null && $value !== '1') throw new InvalidArgumentException('Invalid module flag');
                            $d = ModuleConfig::set($d, $key, $value === '1');
                        }
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='consent') {
                    $app->change(static function(array $d): array {
                        $d['consent'] = ConsentConfig::fromPost($_POST, $d['consent']);
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='ga4') {
                    $config = GA4Config::fromPost($_POST);
                    $app->change(static function(array $d) use ($config): array {
                        $d['integrations']['ga4'] = $config;
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='metrica') {
                    $config = MetricaConfig::fromPost($_POST);
                    $app->change(static function(array $d) use ($config): array {
                        $d['integrations']['metrica'] = $config;
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='gtm') {
                    $config = GTMConfig::fromPost($_POST);
                    $app->change(static function(array $d) use ($config): array {
                        $d['integrations']['gtm'] = $config;
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='clarity') {
                    $config = ClarityConfig::fromPost($_POST);
                    $app->change(static function(array $d) use ($config): array {
                        $d['integrations']['clarity'] = $config;
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='matomo') {
                    $config = MatomoConfig::fromPost($_POST);
                    $app->change(static function(array $d) use ($config): array {
                        $d['integrations']['matomo'] = $config;
                        return $d;
                    }); go('/admin');
                }
                elseif ($action==='save') {
                    $p=Lite::page($_POST); $old=Lite::field($_POST,'old',1024); $revision=Lite::field($_POST,'revision',64);
                    $app->change(function($d)use($p,$old,$revision){
                        if ($old!=='' && (!isset($d['pages'][$old]) || !hash_equals(hash('sha256',json_encode($d['pages'][$old],JSON_THROW_ON_ERROR)),$revision))) throw new InvalidArgumentException('Page changed; reload');
                        if ($old==='/' && ($p['path']!=='/' || !$p['published'])) throw new InvalidArgumentException('Home must remain published at /');
                        if (isset($d['pages'][$p['path']]) && $old!==$p['path']) throw new InvalidArgumentException('Path already exists');
                        if ($old!=='') unset($d['pages'][$old]);
                        if (count($d['pages'])>=100) throw new InvalidArgumentException('Page limit: 100');
                        $d['pages'][$p['path']]=$p;return $d;
                    }); go('/admin');
                } elseif ($action==='delete') {
                    $old=Lite::field($_POST,'old',1024,true); $revision=Lite::field($_POST,'revision',64,true);
                    if ($old==='/') throw new InvalidArgumentException('Cannot delete home');
                    $app->change(function($d)use($old,$revision){if(!isset($d['pages'][$old]) || !hash_equals(hash('sha256',json_encode($d['pages'][$old],JSON_THROW_ON_ERROR)),$revision)) throw new InvalidArgumentException('Page changed; reload'); unset($d['pages'][$old]);return $d;}); go('/admin');
                } else stop(400,'Unknown action');
            } catch(InvalidArgumentException $ex) { http_response_code(422); $error=$ex->getMessage(); }
        }
        $html=$error!==''?'<p class="error" role="alert">'.e($error).'</p>':'';
        if (!$auth) shell('Вход в админ-панель',$html.form('login',input('password','','password').'<button>Войти</button>'));
        $html.=form('logout','<button>Выйти</button>').'<section><h2>Страницы</h2><ul>';
        foreach($d['pages'] as $p) $html.='<li><a href="/admin?edit='.rawurlencode($p['path']).'">'.e($p['title']).'</a> — '.e($p['path']).($p['published']?'':' (черновик)').'</li>';
        $html.='</ul><a href="/admin?new=1">Создать страницу</a></section>';
        $edit=$_GET['edit']??null;
        if ((is_string($edit) && isset($d['pages'][$edit])) || isset($_GET['new'])) {
            $p=is_string($edit)&&isset($d['pages'][$edit])?$d['pages'][$edit]:['path'=>'','title'=>'','description'=>'','body'=>'','published'=>false,'menu'=>true];
            $old=$p['path']; $rev=$old!==''?hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)):'';
            $hidden='<input type="hidden" name="old" value="'.e($old).'"><input type="hidden" name="revision" value="'.e($rev).'">';
            $html.=form('save','<h2>Редактор страницы</h2>'.$hidden.input('path',$p['path']).input('title',$p['title']).input('description',$p['description']).'<label>Текст<textarea name="body">'.e($p['body']).'</textarea></label><label><input type="checkbox" name="published" value="1" '.($p['published']?'checked':'').'> Опубликовано</label><label><input type="checkbox" name="menu" value="1" '.($p['menu']?'checked':'').'> В меню</label><button>Сохранить</button>');
            if ($old!==''&&$old!=='/') $html.=form('delete',$hidden.'<button>Удалить страницу</button>');
        }
        $s=$d['settings']; $html.=form('settings','<h2>Настройки сайта</h2>'.input('name',$s['name']).input('url',$s['url']).input('email',$s['email'],'email').input('phone',$s['phone']).'<button>Сохранить настройки</button>');
        $flags='<h2>Модули</h2>';
        foreach (ModuleConfig::DEFAULTS as $name => $_) {
            $flags.='<label><input type="checkbox" name="module['.e($name).']" value="1" '.(ModuleConfig::enabled($d,$name)?'checked':'').'> '.e($name).'</label>';
        }
        $html.=form('modules',$flags.'<button>Сохранить модули</button>');
        $c=$d['consent'];
        $consentForm='<h2>Cookie Consent</h2><p>Версия политики: '.e((string)$c['revision']).'. При изменении настроек ранее сохранённые разрешения истекают.</p>';
        $consentForm.='<label><input type="checkbox" name="enabled" value="1" '.($c['enabled']?'checked':'').'> Показывать баннер и настройки cookie</label>';
        $consentForm.=input('title',$c['title']).'<label>Описание<textarea name="description" maxlength="1000">'.e($c['description']).'</textarea></label>';
        $consentForm.=input('days',(string)$c['days'],'number').input('privacy_url',$c['privacy_url']);
        $consentForm.='<p>Срок действия в днях (1–365). Ссылка на политику — локальный путь (например, /privacy) или пустое поле. Категории: необходимые, аналитика, маркетинг, запись сессий.</p><button>Сохранить Cookie Consent</button>';
        $html.=form('consent',$consentForm);
        $g=$d['integrations']['ga4'];
        $ga4Form='<h2>Google Analytics 4 (GA4)</h2>';
        $ga4Form.='<p>Прямое подключение через gtag.js. Скрипт загружается только после согласия на аналитику. Настраивайте GA4 без дублирующего тега в GTM.</p>';
        $ga4Form.='<label><input type="checkbox" name="enabled" value="1" '.($g['enabled']?'checked':'').'> Включить GA4</label>';
        $ga4Form.=input('measurement_id',$g['measurement_id']);
        $ga4Form.='<p>Measurement ID формата G-XXXXXXXXXX (а не GTM-...). Для работы требуется включённый Cookie Consent.</p>';
        foreach (['page_views'=>'Отправлять page_view', 'contact_clicks'=>'Отправлять contact_click (без адреса почты и телефона)', 'debug_mode'=>'Debug mode (отладочные события GA4)'] as $key=>$caption) {
            $ga4Form.='<label><input type="checkbox" name="'.e($key).'" value="1" '.($g[$key]?'checked':'').'> '.e($caption).'</label>';
        }
        if (!$c['enabled']) $ga4Form.='<p class="error">Cookie Consent отключён. Отслеживание GA4 останется заблокированным до его включения.</p>';
        $ga4Form.='<button>Сохранить GA4</button>';
        $html.=form('ga4',$ga4Form);
        $m=$d['integrations']['metrica'];
        $metricaForm='<h2>Яндекс Метрика</h2>';
        $metricaForm.='<p>Счётчик, цели для контактов и Вебвизор. Сбор начинается только после согласия посетителя. Для Вебвизора и карты кликов требуется также разрешение «Запись сессий».</p>';
        $metricaForm.='<label><input type="checkbox" name="enabled" value="1" '.($m['enabled']?'checked':'').'> Включить Яндекс Метрику</label>';
        $metricaForm.=input('counter_id',$m['counter_id']).'<p>Числовой ID счётчика из Яндекс Метрики. Не вставляйте HTML-код счётчика.</p>';
        foreach (['page_views'=>'Учитывать просмотры страниц', 'accurate_bounce'=>'Точный показатель отказов', 'clickmap'=>'Карта кликов (только с согласием на запись)', 'webvisor'=>'Вебвизор (только с согласием на запись)', 'contact_goals'=>'Отправлять цели при кликах по email и телефону'] as $key=>$caption) {
            $metricaForm.='<label><input type="checkbox" name="'.e($key).'" value="1" '.($m[$key]?'checked':'').'> '.e($caption).'</label>';
        }
        $metricaForm.=input('email_goal',$m['email_goal']).input('phone_goal',$m['phone_goal']);
        $metricaForm.='<p>Создайте в Яндекс Метрике JavaScript-цели с указанными идентификаторами. При записи сессий изменение или отзыв разрешения приводит к перезагрузке страницы для немедленного прекращения записи.</p>';
        if (!$c['enabled']) $metricaForm.='<p class="error">Cookie Consent выключен. Метрика останется заблокированной.</p>';
        $metricaForm.='<button>Сохранить Яндекс Метрику</button>';
        $html.=form('metrica',$metricaForm);
        $t=$d['integrations']['gtm'];
        $gtmForm='<h2>Google Tag Manager (GTM)</h2>';
        $gtmForm.='<p>Контейнер GTM загружается только после явного разрешения аналитики, маркетинга или записи сессий. Consent Mode v2 и события передаются через dataLayer до загрузки контейнера.</p>';
        $gtmForm.='<label><input type="checkbox" name="enabled" value="1" '.($t['enabled']?'checked':'').'> Включить Google Tag Manager</label>';
        $gtmForm.=input('container_id',$t['container_id']);
        $gtmForm.='<p>ID контейнера GTM-XXXXXXX. Настраивайте разрешения отдельно для каждого тега внутри GTM. При работе GTM прямой загрузчик GA4 отключён во избежание двойных просмотров (настройки GA4 сохраняются).</p>';
        $gtmForm.='<label><input type="checkbox" name="contact_events" value="1" '.($t['contact_events']?'checked':'').'> Передавать cajeer_contact_click для кликов по email и телефону (без адреса и номера)</label>';
        if (!$c['enabled']) $gtmForm.='<p class="error">Cookie Consent выключен: GTM останется заблокированным.</p>';
        $gtmForm.='<p>В GTM настройте Consent Settings и триггеры для категорий аналитика / маркетинг / запись сессий. Сторонние пользовательские теги не проверяются Lite автоматически.</p><button>Сохранить GTM</button>';
        $html.=form('gtm',$gtmForm);
        $cl=$d['integrations']['clarity'];
        $clarityForm='<h2>Microsoft Clarity</h2>';
        $clarityForm.='<p>Поведенческая аналитика, тепловые карты и записи сессий. Clarity загружается только при одновременном согласии на аналитику и запись сессий. Без этих двух разрешений запросы к Microsoft не отправляются.</p>';
        $clarityForm.='<label><input type="checkbox" name="enabled" value="1" '.($cl['enabled']?'checked':'').'> Включить Microsoft Clarity</label>';
        $clarityForm.=input('project_id',$cl['project_id']);
        $clarityForm.='<p>Project ID из Settings → Setup → Install manually (только идентификатор, не HTML и не URL).</p>';
        $clarityForm.='<label><input type="checkbox" name="recordings" value="1" '.($cl['recordings']?'checked':'').'> Разрешить модуль записи сессий (при отключении Clarity полностью не загружается)</label>';
        $clarityForm.='<label><input type="checkbox" name="contact_events" value="1" '.($cl['contact_events']?'checked':'').'> Отправлять события кликов по контактам (без адресов и номеров)</label>';
        $clarityForm.='<p>В проекте Clarity включите Consent Mode. Согласие передаётся через Consent API v2. Настройте маскирование чувствительных элементов и исключения страниц в Microsoft Clarity. Не устанавливайте этот же проект Clarity повторно через GTM: это приведёт к дублям.</p>';
        if (!$c['enabled']) $clarityForm.='<p class="error">Cookie Consent выключен: Microsoft Clarity останется заблокированным.</p>';
        if (!$cl['recordings']) $clarityForm.='<p>Запись выключена: вместе с ней отключена загрузка Clarity. Сервис не поддерживает отдельный режим тепловых карт без сбора взаимодействий.</p>';
        $clarityForm.='<button>Сохранить Microsoft Clarity</button>';
        $html.=form('clarity',$clarityForm);
        $mt=$d['integrations']['matomo'];
        $matomoForm='<h2>Matomo Analytics (Cloud / On-Premise)</h2>';
        $matomoForm.='<p>Аналитика Matomo загружается только при разрешении категории «Аналитика». Без согласия внешние запросы к Matomo не выполняются. Для Matomo Cloud укажите https://ваш-сайт.matomo.cloud/, для On-Premise — HTTPS-адрес установки, включая подкаталог при необходимости.</p>';
        $matomoForm.='<label><input type="checkbox" name="enabled" value="1" '.($mt['enabled']?'checked':'').'> Включить Matomo</label>';
        $matomoForm.='<label>Режим<select name="mode"><option value="onpremise" '.($mt['mode']==='onpremise'?'selected':'').'>On-Premise (свой сервер)</option><option value="cloud" '.($mt['mode']==='cloud'?'selected':'').'>Matomo Cloud</option></select></label>';
        $matomoForm.=input('server_url',$mt['server_url'],'url').input('site_id',$mt['site_id']);
        $matomoForm.='<p>Site ID — положительное число из настроек сайта Matomo. URL должен использовать HTTPS, без логина, параметров или фрагментов. Для Matomo Cloud используется официальный CDN, для On-Premise — matomo.js на вашем сервере.</p>';
        $matomoForm.='<label><input type="checkbox" name="page_views" value="1" '.($mt['page_views']?'checked':'').'> Отправлять просмотры страниц</label>';
        $matomoForm.='<label><input type="checkbox" name="contact_events" value="1" '.($mt['contact_events']?'checked':'').'> События кликов по email и телефону (без отправки адреса и номера)</label>';
        $matomoForm.='<p>Произвольные события и цели можно отправлять через CajeerMatomo.event() / CajeerMatomo.goal() после согласия. Не подключайте тот же трекер Matomo второй раз через GTM. При отзыве согласия загрузка сторонних скриптов прекращается после перезагрузки.</p>';
        if (!$c['enabled']) $matomoForm.='<p class="error">Cookie Consent выключен: Matomo останется заблокированным.</p>';
        $matomoForm.='<button>Сохранить Matomo</button>';
        $html.=form('matomo',$matomoForm);
        // Live diagnostics on the authenticated admin page; no publicly reachable
        // debug endpoint, secrets or third-party network fetches.
        $report=IntegrationDiagnostics::run($app->root,$d);
        $html.='<section><h2>Диагностика интеграций</h2>';
        $html.='<p>'.($report['ok'] ? 'Локальные проверки: успешно.' :
            'Есть ошибки файлов или конфигурации. Используйте CLI integrations check.').'</p><ul>';
        foreach ($report['providers'] as $name => $status) {
            $html.='<li>'.e(strtoupper($name)).': '.e($status).'</li>';
        }
        $html.='</ul>';
        foreach ($report['checks'] as $check) {
            if ($check['status'] !== 'pass') {
                $html.='<p class="'.($check['status'] === 'fail' ? 'error' : 'notice').'">'.
                    e(strtoupper($check['status']).': '.$check['message']).'</p>';
            }
        }
        $html.='<p>Подробные результаты: php bin/cajeer integrations check --json. '.
            'Проверка не подтверждает доставку событий во внешние сервисы.</p></section>';
        shell('Управление сайтом',$html);
    }
    if ($method==='POST') { header('Allow: GET, HEAD'); stop(405,'Method not allowed'); }
    if ($path==='/robots.txt' && ModuleConfig::enabled($d, 'robots')) { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nDisallow: /admin\n"; if (ModuleConfig::enabled($d, 'sitemap')) echo "Sitemap: ".$d['settings']['url']."/sitemap.xml\n"; exit; }
    if ($path==='/sitemap.xml' && ModuleConfig::enabled($d, 'sitemap')) { header('Content-Type: application/xml; charset=utf-8'); echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'; foreach($d['pages'] as $p) if($p['published']) echo '<url><loc>'.e($d['settings']['url'].$p['path']).'</loc></url>'; echo '</urlset>'; exit; }
    if (!isset($d['pages'][$path]) || !$d['pages'][$path]['published']) stop(404,'Страница не найдена');
    if ($path!==$raw) { header('Location: '.$path,true,301); exit; }
    // Personalized consent state must never be shared via a public intermediary cache.
    header('Cache-Control: private, no-store');
    $csp = PublicCsp::headers($d);
    if ($csp['webvisor_frames']) header_remove('X-Frame-Options');
    header('Content-Security-Policy: '.$csp['policy']);
    echo $app->render($d,$d['pages'][$path]);
} catch(Throwable $ex) { error_log('CajeerEngine Lite: '.$ex->getMessage()); stop(500,'Internal server error'); }
