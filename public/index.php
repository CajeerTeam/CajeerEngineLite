<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Public\PublicPath;
$app=new Lite(dirname(__DIR__));
function e(string $s): string { return Lite::esc($s); }
function stop(int $status,string $msg): never { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }
function go(string $path): never { header('Location: '.$path,true,303); exit; }
function shell(string $title,string $html): never { header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).'</title><link rel="stylesheet" href="/assets/style.css"><body><header><a href="/">На сайт</a><a href="/admin">CajeerEngine Lite</a></header><main><h1>'.e($title).'</h1>'.$html.'</main></body></html>'; exit; }
function input(string $name,string $value='',string $type='text'): string { return '<label>'.e($name).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"></label>'; }
function token(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function form(string $action,string $html): string { return '<form method="post" action="/admin">'.token().'<input type="hidden" name="action" value="'.e($action).'">'.$html.'</form>'; }
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
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
        $s=$d['settings']; $html.=form('settings','<h2>Настройки сайта</h2>'.input('name',$s['name']).input('url',$s['url']).input('email',$s['email'],'email').input('phone',$s['phone']).'<button>Сохранить настройки</button>'); shell('Управление сайтом',$html);
    }
    if ($method==='POST') { header('Allow: GET, HEAD'); stop(405,'Method not allowed'); }
    if ($path==='/robots.txt') { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nDisallow: /admin\nSitemap: ".$d['settings']['url']."/sitemap.xml\n"; exit; }
    if ($path==='/sitemap.xml') { header('Content-Type: application/xml; charset=utf-8'); echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'; foreach($d['pages'] as $p) if($p['published']) echo '<url><loc>'.e($d['settings']['url'].$p['path']).'</loc></url>'; echo '</urlset>'; exit; }
    if (!isset($d['pages'][$path]) || !$d['pages'][$path]['published']) stop(404,'Страница не найдена');
    if ($path!==$raw) { header('Location: '.$path,true,301); exit; }
    echo $app->render($d,$d['pages'][$path]);
} catch(Throwable $ex) { error_log('CajeerEngine Lite: '.$ex->getMessage()); stop(500,'Internal server error'); }
