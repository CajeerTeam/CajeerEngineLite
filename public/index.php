<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\AI\{AIConfig,OpenAIClient,UsageLimiter};
use CajeerEngine\Auth\{OidcClient,OidcFlow,OwnerIdentity,SessionRegistry,AuthPolicy,StableAcceptance};
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Analytics\{ReportConfig,ReportService,ReportCache,HttpClient,DashboardView};
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Consent\ConsentConfig;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig,ClarityConfig,MatomoConfig,PublicCsp,IntegrationDiagnostics,ProductionAcceptance};
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
    if ($path==='/admin' || str_starts_with($path, '/admin/oidc/')) {
        // Never trust user-provided X-Forwarded-Proto or Host for administrative
        // authentication. TLS termination must pass HTTPS=on to PHP-FPM.
        if (str_starts_with($d['settings']['url'], 'https://')) {
            if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
                stop(403, 'Administrative access requires HTTPS');
            }
            $expectedHost=parse_url($d['settings']['url'],PHP_URL_HOST);
            $expectedPort=parse_url($d['settings']['url'],PHP_URL_PORT) ?? 443;
            $received=$_SERVER['HTTP_HOST'] ?? '';
            $host=is_string($received) ? parse_url('https://'.$received, PHP_URL_HOST) : null;
            $port=is_string($received) ? (parse_url('https://'.$received, PHP_URL_PORT) ?? 443) : null;
            if (!is_string($expectedHost) || !is_string($host) ||
                !hash_equals(strtolower($expectedHost),strtolower($host)) || $port!==$expectedPort) {
                stop(421,'Unrecognized admin hostname');
            }
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
        if ($path==='/admin/oidc/backchannel-logout') {
            if ($method!=='POST') {header('Allow: POST'); stop(405,'Method not allowed');}
            if (!OidcClient::enabled($d['settings']['url'])) stop(503,'OIDC unavailable');
            $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
            if ($type!=='application/x-www-form-urlencoded' || (int)($_SERVER['CONTENT_LENGTH']??0)>20000) stop(400,'Invalid logout request');
            $jwt=$_POST['logout_token']??null;
            if (!is_string($jwt) || strlen($jwt)<100 || strlen($jwt)>16000) stop(400,'Invalid logout request');
            try {
                $claims=OidcClient::fromEnvironment($d['settings']['url'])->verifyLogoutToken($jwt);
            } catch (\Throwable $exception) {
                error_log('SkiF4er logout token verification: '.get_class($exception));
                stop(400,'Invalid logout request');
            }
            try {
                (new SessionRegistry($app->root))->backchannelLogout($claims,$d);
                header('Content-Type: text/plain; charset=utf-8'); echo 'OK'; exit;
            } catch (\Throwable $exception) {
                // Storage failure or a full replay cache is temporary: ask the IdP
                // to retry, rather than acknowledging an unperformed revocation.
                error_log('SkiF4er logout revocation unavailable: '.get_class($exception));
                header('Retry-After: 60');
                stop(503,'Logout revocation temporarily unavailable');
            }
        }
        if ($path==='/admin/oidc/logged-out') {
            if ($method!=='GET' && $method!=='HEAD') {header('Allow: GET, HEAD'); stop(405,'Method not allowed');}
            shell('Выход SkiF4er ID','<p>Сеанс Lite завершён. Выход из SkiF4er ID запрошен.</p><p><a href="/admin">Вернуться ко входу</a></p>');
        }
        $secure=str_starts_with($d['settings']['url'],'https://') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        ini_set('session.gc_maxlifetime',(string)SessionRegistry::MAX_SECONDS);
        $sessions=new SessionRegistry($app->root);
        session_save_path($sessions->phpSessionDirectory());
        session_name('CajeerLite'); session_set_cookie_params(['path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax']); session_start();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        $auth=isset($_SESSION['auth'],$_SESSION['seen']) && is_string($_SESSION['auth']) &&
            is_int($_SESSION['seen']) && hash_equals(hash('sha256',$d['password']),$_SESSION['auth']) &&
            time() >= $_SESSION['seen'] && time()-$_SESSION['seen']<3600 &&
            is_string($_SESSION['owner_generation'] ?? null) &&
            hash_equals($d['identity']['generation'], $_SESSION['owner_generation']);
        if ($auth && !AuthPolicy::sessionAllowed($d, $_SESSION)) $auth=false;
        if ($auth && isset($_SESSION['oidc_identity'])) {
            $auth = OwnerIdentity::matches($d['identity']['owner'] ?? null, $_SESSION['oidc_identity']);
        }
        if ($auth) $auth=$sessions->valid($_SESSION,$d);
        if ($auth) $_SESSION['seen']=time();
        if (str_starts_with($path, '/admin/oidc/')) {
            if ($method !== 'GET') { header('Allow: GET'); stop(405, 'Method not allowed'); }
            if (!OidcClient::enabled($d['settings']['url'])) stop(503, 'SkiF4er ID is not configured for HTTPS');
            try {
                $client=OidcClient::fromEnvironment($d['settings']['url']);
                if ($path==='/admin/oidc/login') {
                    // OIDC login initiation is POST/CSRF-only via /admin.
                    stop(405, 'Use the sign-in form');
                }
                if ($path==='/admin/oidc/callback') {
                    $hadLocalAuth=$auth && !isset($_SESSION['oidc_identity']);
                    $result=OidcFlow::finish($_SESSION, $client, $_GET);
                    $identity=$result['identity'];
                    $providerSid=$identity['sid']??null;
                    unset($identity['sid']); // ownership uses only issuer + subject
                    $mode=$result['mode'];
                    $context=$result['context'];
                    $expectedGeneration=$context['generation'] ?? null;
                    if ($mode==='bind') {
                        if (!$hadLocalAuth || !is_string($expectedGeneration)) {
                            stop(403, 'Local administrator login is required for account binding');
                        }
                        $d=$app->change(static fn(array $site): array => OwnerIdentity::bind($site, $identity, $expectedGeneration));
                    } elseif ($mode==='replace_current' || $mode==='unbind') {
                        if (!$auth || !is_string($expectedGeneration) ||
                            !hash_equals($d['identity']['generation'], $expectedGeneration) ||
                            !OwnerIdentity::matches($d['identity']['owner'] ?? null, $identity)) {
                            stop(403, 'Current linked owner reauthentication required');
                        }
                        if ($mode==='replace_current') {
                            // Verification of the old owner is completed before allowing a new one.
                            $url=OidcFlow::start($_SESSION, $client, 'replace_new', [
                                'generation'=>$expectedGeneration, 'previous_owner'=>$identity,
                            ]);
                            header('Location: '.$url, true, 303); exit;
                        }
                        $d=$app->change(static fn(array $site): array =>
                            OwnerIdentity::unbind($site, $identity, $expectedGeneration));
                    } elseif ($mode==='replace_new') {
                        if (!$auth || !is_string($expectedGeneration) ||
                            !hash_equals($d['identity']['generation'], $expectedGeneration) ||
                            !OwnerIdentity::matches($d['identity']['owner'] ?? null, $context['previous_owner'] ?? null)) {
                            stop(403, 'Owner binding changed during replacement');
                        }
                        $previous=$context['previous_owner'];
                        $d=$app->change(static fn(array $site): array =>
                            OwnerIdentity::replace($site, $previous, $identity, $expectedGeneration));
                    } elseif ($mode==='auth_policy') {
                        if (!$auth || !is_string($expectedGeneration) ||
                            !is_int($context['revision'] ?? null) ||
                            !is_string($context['target'] ?? null) ||
                            !OwnerIdentity::matches($d['identity']['owner'] ?? null, $identity)) {
                            stop(403, 'Fresh linked owner verification required');
                        }
                        $target=$context['target'];
                        $revision=$context['revision'];
                        $d=$app->change(static fn(array $site): array =>
                            AuthPolicy::switch($site, $target, $expectedGeneration, $revision, $identity));
                    } elseif ($mode!=='login' || !OwnerIdentity::matches($d['identity']['owner'] ?? null, $identity)) {
                        stop(403, 'SkiF4er ID is not authorized for this site');
                    }
                    session_regenerate_id(true);
                    $_SESSION=['auth'=>hash('sha256',$d['password']), 'seen'=>time(),
                        'csrf'=>bin2hex(random_bytes(32)), 'owner_generation'=>$d['identity']['generation']];
                    if ($mode!=='unbind') {
                        $_SESSION['oidc_identity']=$identity;
                        if (is_string($providerSid)) $_SESSION['oidc_sid']=$providerSid;
                    }
                    $sessions->issue($_SESSION,$d,$_SERVER);
                    go('/admin');
                }
                stop(404, 'Not found');
            } catch (\Throwable $exception) {
                error_log('SkiF4er OIDC: '.get_class($exception));
                stop(403, 'SkiF4er ID authentication failed');
            }
        }
        $error='';
        if ($method==='POST') {
            if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) stop(403,'Invalid CSRF token');
            $action=$_POST['action']??'';
            try {
                if ($action==='oidc_cancel') {
                    OidcFlow::cancel($_SESSION);
                    go('/admin');
                }
                elseif ($action==='oidc_login' && !$auth) {
                    if (($d['identity']['owner'] ?? null) === null || !OidcClient::enabled($d['settings']['url'])) {
                        stop(403, 'SkiF4er ID owner is not linked');
                    }
                    $url=OidcFlow::start($_SESSION, OidcClient::fromEnvironment($d['settings']['url']), 'login');
                    header('Location: '.$url, true, 303); exit;
                }
                elseif ($action==='login' && !$auth) {
                    if (!AuthPolicy::localAllowed($d)) stop(403, 'Local sign-in disabled by authentication policy');
                    if ($app->login(is_string($_POST['password']??null)?$_POST['password']:'')) { session_regenerate_id(true); $_SESSION=['auth'=>hash('sha256',$d['password']),'seen'=>time(),'csrf'=>bin2hex(random_bytes(32)),'owner_generation'=>$d['identity']['generation']]; $sessions->issue($_SESSION,$d,$_SERVER); go('/admin'); }
                    http_response_code(401); $error='Неверный пароль или временная блокировка. Повторите через 5 минут.';
                } elseif (!$auth) stop(403,'Login required');
                elseif ($action==='auth_policy') {
                    $target=$_POST['auth_mode'] ?? null;
                    $confirmation=$_POST['confirm_auth_mode'] ?? null;
                    $password=$_POST['auth_password'] ?? null;
                    if (!is_string($target) || !in_array($target,[AuthPolicy::HYBRID,AuthPolicy::OIDC_ONLY],true) ||
                        $target===$d['auth_policy']['mode'] || $confirmation!=='1') {
                        throw new InvalidArgumentException('Choose a different login mode and confirm the change');
                    }
                    if (!OwnerIdentity::validSubject($d['identity']['owner'] ?? null) ||
                        !OidcClient::enabled($d['settings']['url'])) {
                        throw new InvalidArgumentException('Link the owner and configure HTTPS OIDC before changing login mode');
                    }
                    if (!is_string($password) || !$app->login($password)) {
                        throw new InvalidArgumentException('Confirm with the local administrator password');
                    }
                    try {
                        // Fresh interactive IdP login is mandatory even for an already signed-in OIDC session.
                        $url=OidcFlow::start($_SESSION, OidcClient::fromEnvironment($d['settings']['url']),
                            'auth_policy', ['generation'=>$d['identity']['generation'],
                                'revision'=>$d['auth_policy']['revision'], 'target'=>$target]);
                    } catch (\Throwable $exception) {
                        error_log('SkiF4er authentication policy: '.get_class($exception));
                        throw new InvalidArgumentException('SkiF4er ID is unavailable; login policy was not changed');
                    }
                    header('Location: '.$url, true, 303); exit;
                }
                elseif ($action==='oidc_bind') {
                    if (isset($_SESSION['oidc_identity']) || ($d['identity']['owner'] ?? null)!==null) {
                        throw new InvalidArgumentException('Only an unlinked local owner can connect SkiF4er ID');
                    }
                    if (!OidcClient::enabled($d['settings']['url'])) {
                        throw new InvalidArgumentException('Configure HTTPS and SkiF4er OIDC credentials first');
                    }
                    $password=$_POST['oidc_password'] ?? null;
                    if (!is_string($password) || !$app->login($password)) {
                        throw new InvalidArgumentException('Re-enter the correct local administrator password');
                    }
                    try {
                        $url=OidcFlow::start($_SESSION, OidcClient::fromEnvironment($d['settings']['url']),
                            'bind', ['generation'=>$d['identity']['generation']]);
                    } catch (RuntimeException $exception) {
                        error_log('SkiF4er bind initialization failed: '.get_class($exception));
                        throw new InvalidArgumentException('SkiF4er ID is temporarily unavailable');
                    }
                    header('Location: '.$url, true, 303); exit;
                }
                elseif ($action==='oidc_replace' || $action==='oidc_unbind') {
                    if ($action==='oidc_unbind' && !AuthPolicy::localAllowed($d)) {
                        throw new InvalidArgumentException('Switch to local + SkiF4er ID mode before unlinking the only SSO owner');
                    }
                    if (!OidcClient::enabled($d['settings']['url']) || ($d['identity']['owner'] ?? null) === null) {
                        throw new InvalidArgumentException('A linked owner and configured SkiF4er ID are required');
                    }
                    if (($_POST['confirm_owner_change'] ?? null) !== '1') {
                        throw new InvalidArgumentException('Confirm the account ownership change');
                    }
                    $password=$_POST['oidc_password'] ?? null;
                    if (!is_string($password) || !$app->login($password)) {
                        throw new InvalidArgumentException('Re-enter the correct local administrator password');
                    }
                    // The first OIDC roundtrip must authenticate the *existing* owner.
                    // The replacement flow subsequently authenticates the new owner.
                    $mode=$action==='oidc_replace' ? 'replace_current' : 'unbind';
                    try {
                        $url=OidcFlow::start($_SESSION, OidcClient::fromEnvironment($d['settings']['url']),
                            $mode, ['generation'=>$d['identity']['generation']]);
                    } catch (RuntimeException $exception) {
                        error_log('SkiF4er owner operation initialization: '.get_class($exception));
                        throw new InvalidArgumentException('SkiF4er ID is temporarily unavailable');
                    }
                    header('Location: '.$url, true, 303); exit;
                }
                elseif ($action==='logout' || $action==='logout_global') {
                    // Revoke locally before redirecting to the IdP; the redirect may fail or be cancelled.
                    $global=$action==='logout_global';
                    if ($global && (!isset($_SESSION['oidc_identity']) || !OidcClient::enabled($d['settings']['url']))) {
                        throw new InvalidArgumentException('Global logout requires an active SkiF4er ID session');
                    }
                    $sessions->revoke($_SESSION,$d,'one',SessionRegistry::currentId($_SESSION));
                    $_SESSION=[];
                    session_destroy();
                    setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>'/', 'httponly'=>true,
                        'secure'=>$secure, 'samesite'=>'Lax']);
                    if ($global) {
                        try {
                            $url=OidcClient::fromEnvironment($d['settings']['url'])->globalLogoutUrl($d['settings']['url']);
                            header('Location: '.$url,true,303); exit;
                        } catch (\Throwable $exception) {
                            error_log('SkiF4er global logout: '.get_class($exception));
                            go('/admin?logout=provider_unavailable');
                        }
                    }
                    go('/admin');
                }
                elseif ($action==='session_revoke' || $action==='session_revoke_others' || $action==='session_revoke_all') {
                    if ($action==='session_revoke_all' && (!is_string($_POST['session_password']??null) ||
                        !$app->login($_POST['session_password']))) {
                        throw new InvalidArgumentException('Подтвердите завершение всех сеансов локальным паролем');
                    }
                    $scope=match($action) {
                        'session_revoke'=>'one','session_revoke_others'=>'others',default=>'all'
                    };
                    $target=$scope==='one' ? ($_POST['session_id']??null) : null;
                    if ($target!==null && !is_string($target)) throw new InvalidArgumentException('Invalid session identifier');
                    if ($sessions->revoke($_SESSION,$d,$scope,$target)) {
                        $_SESSION=[]; session_destroy();
                        setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>'/',
                            'httponly'=>true, 'secure'=>$secure, 'samesite'=>'Lax']);
                        go('/admin');
                    }
                    go('/admin');
                }
                elseif ($action==='ai_settings') {
                    $config=AIConfig::fromPost($_POST);
                    $app->change(static function(array $site)use($config):array {
                        $site['ai']=$config; return $site;
                    });
                    go('/admin?ai=1');
                }
                elseif ($action==='ai_models') {
                    if (time()-(int)($_SESSION['ai_models_at']??0)<30) {
                        throw new InvalidArgumentException('Повторите запрос моделей не ранее чем через 30 секунд');
                    }
                    $_SESSION['ai_models_at']=time();
                    try {
                        $models=(new OpenAIClient())->models();
                        $_SESSION['ai_result']=['kind'=>'models','models'=>$models];
                    } catch (RuntimeException $failure) {
                        $_SESSION['ai_result']=['kind'=>'error','message'=>$failure->getMessage()];
                    }
                    go('/admin?ai=1');
                }
                elseif ($action==='ai_test') {
                    $prompt=$_POST['ai_prompt']??null;
                    if (!is_string($prompt) || $prompt==='' || strlen($prompt)>$d['ai']['max_input_chars'] ||
                        !preg_match('//u',$prompt) || str_contains($prompt,"\0")) {
                        throw new InvalidArgumentException('Неверный запрос или превышено ограничение длины');
                    }
                    try {
                        $result=(new UsageLimiter($app->root))->generate(new OpenAIClient(),$d['ai'],$prompt);
                        $_SESSION['ai_result']=['kind'=>'response','text'=>substr($result['text'],0,12000),
                            'input'=>$result['input_tokens'],'output'=>$result['output_tokens'],
                            'model'=>$result['model']];
                    } catch (RuntimeException $failure) {
                        $_SESSION['ai_result']=['kind'=>'error','message'=>$failure->getMessage()];
                    }
                    go('/admin?ai=1');
                }
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
                elseif ($action==='analytics_settings') {
                    $config=ReportConfig::fromPost($_POST);
                    $app->change(static function(array $state) use ($config): array {
                        $state['reporting']=$config;
                        return $state;
                    });
                    go('/admin?analytics=1');
                }
                elseif ($action==='analytics_refresh') {
                    $days=filter_var($_POST['days']??null,FILTER_VALIDATE_INT);
                    if ($days===false) throw new InvalidArgumentException('Invalid report days');
                    ReportService::days($days);
                    // Administrator-triggered only; bounded provider calls, errors isolated per provider.
                    $state=$app->load();
                    $cache=new ReportCache($app->root);
                    $previous=$cache->read($state,$days);
                    if ($previous===null || $previous['cache_age_seconds']>=60) {
                        $service=new ReportService(new HttpClient(),$app->root);
                        $reports=$service->collect($state,$days);
                        $cache->save($state,$days,$reports);
                    }
                    go('/admin?analytics=1&days='.$days);
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
        if (!$auth) {
            if (($_GET['logout']??null)==='provider_unavailable') $html.='<p role="alert">Локальный сеанс завершён, но недоступен сервер SkiF4er ID. Закройте браузер и завершите SSO-сеанс отдельно.</p>';
            $loginHtml=$html;
            if (AuthPolicy::localAllowed($d)) {
                $loginHtml.=form('login',input('password','','password').'<button>Войти по паролю</button>');
            } else {
                $loginHtml.='<p>Вход по локальному паролю отключён владельцем. Для аварийного восстановления используйте серверный CLI.</p>';
            }
            if (OidcClient::enabled($d['settings']['url']) && ($d['identity']['owner'] ?? null) !== null) {
                $loginHtml.='<section><h2>SkiF4er ID</h2><p>Войти через привязанный аккаунт владельца.</p>'.
                    form('oidc_login','<button>Войти через SkiF4er ID</button>').'</section>';
            }
            if (isset($_SESSION['cajeer_oidc_transaction'])) {
                $loginHtml.='<p>Авторизация SkiF4er ID уже начата.</p>'.form('oidc_cancel','<button>Отменить незавершённый вход</button>');
            }
            shell('Вход в админ-панель', $loginHtml);
        }
        if (isset($_SESSION['cajeer_oidc_transaction'])) {
            $html.='<p>Незавершённая OIDC-авторизация.</p>'.form('oidc_cancel','<button>Отменить авторизацию</button>');
        }
        $identity=$d['identity']['owner'] ?? null;
        $ai=$d['ai'];
        $html.='<section id="ai"><h2>OpenAI — серверная интеграция</h2>'.
            '<p>Responses API. Запросы выполняются только сервером, API-ключ недоступен браузеру. ' .
            'Пробная генерация расходует токены. Ключ задаётся переменной CAJEER_OPENAI_API_KEY.</p>';
        $html.='<p>API-ключ: '.(OpenAIClient::configured()?'настроен':'не настроен').'</p>';
        $fields='<label><input type="checkbox" name="ai_enabled" value="1" '.($ai['enabled']?'checked':'').'> Включить OpenAI</label>';
        $fields.=input('ai_model',$ai['model']);
        foreach (['max_input_chars'=>'Макс. байт в запросе','max_output_tokens'=>'Макс. токенов ответа',
            'daily_requests'=>'Макс. запросов за день','daily_tokens'=>'Макс. токенов за день'] as $key=>$label) {
            $fields.='<label>'.e($label).'<input type="number" name="ai_'.e($key).'" value="'.e((string)$ai[$key]).'" required></label>';
        }
        $html.=form('ai_settings',$fields.'<button>Сохранить настройки OpenAI</button>');
        $html.=form('ai_models','<button>Проверить доступные модели через API</button>');
        if ($ai['enabled']) {
            $html.=form('ai_test','<label>Тестовый запрос (передаётся OpenAI)'.
                '<textarea name="ai_prompt" maxlength="'.e((string)$ai['max_input_chars']).'" rows="4" required></textarea></label>'.
                '<button>Сгенерировать тестовый ответ</button>');
        }
        try {
            $usage=(new UsageLimiter($app->root))->status();
            $html.='<p>UTC '.e($usage['day']).': запросов '.e((string)$usage['requests']).
                ' / '.e((string)$ai['daily_requests']).', токенов '.e((string)$usage['tokens']).
                ' (зарезервировано '.e((string)$usage['reserved_tokens']).') / '.e((string)$ai['daily_tokens']).'.</p>';
        } catch (Throwable $failure) {
            $html.='<p class="error">Файловый счётчик AI недоступен.</p>';
        }
        $report=$_SESSION['ai_result']??null; unset($_SESSION['ai_result']);
        if (is_array($report)) {
            if (($report['kind']??null)==='response') {
                $html.='<h3>Ответ OpenAI</h3><p>Модель: '.e((string)$report['model']).
                    '; вход: '.e((string)$report['input']).', выход: '.e((string)$report['output']).' токенов</p>'.
                    '<div class="ai-response">'.nl2br(e((string)$report['text']),false).'</div>';
            } elseif (($report['kind']??null)==='models') {
                $html.='<p>Модели GPT, доступные в проекте (список не гарантирует поддержку Responses API):</p><ul>';
                foreach ($report['models'] as $name) $html.='<li>'.e($name).'</li>';
                $html.='</ul>';
            } elseif (($report['kind']??null)==='error') {
                $html.='<p class="error" role="alert">'.e((string)$report['message']).'</p>';
            }
        }
        $html.='</section>';
        $html.='<section><h2>SkiF4er ID</h2>';
        if ($identity === null) {
            $html.='<p>Аккаунт владельца пока не привязан. Вход через ID не предоставляет права автоматически.</p>';
            if (OidcClient::enabled($d['settings']['url'])) {
                $html.=form('oidc_bind', input('oidc_password','','password').
                    '<p>Для привязки подтвердите локальный пароль, затем войдите в SkiF4er ID.</p>'.
                    '<button>Привязать SkiF4er ID</button>');
            } else {
                $html.='<p>Для подключения укажите HTTPS URL сайта и переменные CAJEER_OIDC_CLIENT_ID и CAJEER_OIDC_CLIENT_SECRET на сервере.</p>';
            }
        } else {
            $html.='<p>Владелец привязан к SkiF4er ID. Идентификатор: '.e(substr($identity['sub'],0,12)).'…</p>';
            if (isset($_SESSION['oidc_identity'])) $html.='<p>Текущий вход: SkiF4er ID.</p>';
            if (OidcClient::enabled($d['settings']['url'])) {
                $html.='<p>Смена владельца требует локальный пароль и два последовательных входа SkiF4er ID: сначала текущего, затем нового владельца. После смены прежние сеансы станут недействительными.</p>';
                $html.=form('oidc_replace', input('oidc_password','','password').
                    '<label><input type="checkbox" name="confirm_owner_change" value="1" required> Подтверждаю смену привязанного аккаунта</label>'.
                    '<button>Сменить аккаунт владельца</button>');
                if (AuthPolicy::localAllowed($d)) {
                    $html.='<p>Отвязка требует локальный пароль и повторный вход в текущий SkiF4er ID. Локальный пароль останется действующим, а предыдущие сеансы будут отозваны.</p>';
                    $html.=form('oidc_unbind', input('oidc_password','','password').
                        '<label><input type="checkbox" name="confirm_owner_change" value="1" required> Подтверждаю отвязку аккаунта владельца</label>'.
                        '<button>Отвязать SkiF4er ID</button>');
                } else {
                    $html.='<p>Для отвязки сначала переключитесь с SSO-only на совместный режим входа.</p>';
                }
            }
        }
        $html.='</section>';
        $policy=$d['auth_policy'];
        $policyReady=OwnerIdentity::validSubject($identity) && OidcClient::enabled($d['settings']['url']);
        $html.='<section><h2>Управление способами входа</h2>'.
            '<p>Текущий режим: <strong>'.e($policy['mode']===AuthPolicy::OIDC_ONLY?
                'Только SkiF4er ID':'Локальный пароль и SkiF4er ID').'</strong>. Ревизия: '.e((string)$policy['revision']).'.</p>';
        if ($policyReady) {
            $modeOptions='<label><input type="radio" name="auth_mode" value="hybrid" '.
                ($policy['mode']===AuthPolicy::HYBRID?'checked':'').'> Локальный пароль + SkiF4er ID</label>'.
                '<label><input type="radio" name="auth_mode" value="oidc_only" '.
                ($policy['mode']===AuthPolicy::OIDC_ONLY?'checked':'').'> Только SkiF4er ID (SSO-only)</label>';
            $html.='<p>Для смены способа входа нужны локальный пароль и новый интерактивный вход в привязанный SkiF4er ID. ' .
                'После переключения старые сессии будут отозваны. При недоступности провайдера восстановите доступ через CLI.</p>'.
                form('auth_policy',$modeOptions.input('auth_password','','password').
                    '<label><input type="checkbox" name="confirm_auth_mode" value="1" required> Подтверждаю смену режима и отзыв существующих сеансов</label>'.
                    '<button>Изменить способ входа</button>');
        } else {
            $html.='<p>Изменение режима недоступно: сначала привяжите аккаунт владельца и настройте HTTPS OIDC.</p>';
        }
        $html.='</section>';
        // On-demand local release diagnostics. No IdP requests from a page view,
        // and detailed results are visible only to a valid admin session.
        $html.='<section><h2>SkiF4er ID — приёмка 1.2.0</h2>'.
            '<p>Сетевую проверку Discovery, JWKS и публичного HTTPS запускайте через CLI: '.
            '<code>php bin/cajeer auth release-check --live --json</code>. '.
            'Успешная диагностика не заменяет реальный вход и выход на staging.</p>'.
            '<p><a href="/admin?auth_acceptance=1">Проверить локальную готовность</a></p>';
        if (($_GET['auth_acceptance'] ?? null) === '1') {
            $report=StableAcceptance::run($app->root,$d);
            $html.='<p>Статус: <strong>'.e($report['status']).'</strong>; '.
                'окончательная приёмка требует проверки на staging.</p><ul>';
            foreach ($report['checks'] as $check) {
                $html.='<li>'.e(strtoupper($check['status']).' '.$check['id'].' — '.$check['message']).'</li>';
            }
            $html.='</ul>';
        }
        $html.='</section>';
        $devices=$sessions->devices($_SESSION,$d);
        $html.='<section><h2>Активные сеансы и устройства</h2>';
        $html.='<p>Сеансы хранятся на сервере; браузер получает только HttpOnly cookie сеанса. '.
            'Бездействие: 60 минут, абсолютный срок: 24 часа. Отзыв блокирует следующий защищённый запрос.</p>';
        $html.='<table><thead><tr><th>Устройство</th><th>Тип входа</th><th>Адрес</th><th>Последняя активность (UTC)</th><th>Действие</th></tr></thead><tbody>';
        foreach ($devices as $device) {
            $html.='<tr><td>'.e($device['agent']!==''?$device['agent']:'Неизвестное устройство').
                ($device['current']?' <strong>(текущий сеанс)</strong>':'').'</td><td>'.e($device['method']).
                '</td><td>'.e($device['ip']!==''?$device['ip']:'—').'</td><td>'.
                e(gmdate('Y-m-d H:i:s',$device['seen'])).'</td><td>'.
                form('session_revoke','<input type="hidden" name="session_id" value="'.e($device['id']).'">'.
                    '<button>Завершить</button>').'</td></tr>';
        }
        $html.='</tbody></table>';
        $html.=form('session_revoke_others','<button>Завершить остальные сеансы</button>');
        $html.=form('session_revoke_all',input('session_password','','password').
            '<button>Завершить все сеансы (включая текущий)</button>');
        $html.='</section>';
        $html.=form('logout','<button>Выйти только из Lite</button>');
        if (isset($_SESSION['oidc_identity']) && OidcClient::enabled($d['settings']['url'])) {
            $html.=form('logout_global','<button>Выйти из Lite и SkiF4er ID</button>');
        }
        $html.='<section><h2>Страницы</h2><ul>';
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
        // Release check is read-only and never contacts analytics vendors.
        // The admin view must distinguish local readiness from real delivery.
        $acceptance=ProductionAcceptance::run($app->root,$d);
        $html.='<section><h2>Production-приёмка 1.1.0</h2>';
        $html.='<p>'.($acceptance['ok'] ?
            'Локальные проверки production-конфигурации прошли; внешняя приёмка ещё требуется.' :
            'Локальная production-приёмка не пройдена. Исправьте ошибки перед публикацией.').'</p>';
        foreach ($acceptance['checks'] as $check) {
            if ($check['status']==='pass') continue;
            $html.='<p class="'.($check['status']==='fail' ? 'error' : 'notice').'">'.
                e(strtoupper($check['status']).': '.$check['message']).'</p>';
        }
        foreach ($acceptance['manual'] as $step) {
            $html.='<p class="notice">Требуется проверка в кабинете провайдера: '.e($step).'</p>';
        }
        $html.='<p>CLI: php bin/cajeer release check --json. Отчёт не подтверждает доставку данных.</p></section>';
        $html.=DashboardView::settings($d,$_SESSION['csrf']);
        if (isset($_GET['analytics'])) {
            $days=filter_var($_GET['days']??'7',FILTER_VALIDATE_INT);
            if ($days===false || !in_array($days,[3,7,14,30,90],true)) stop(400,'Invalid reporting period');
            try { $snapshot=(new ReportCache($app->root))->read($d,$days); }
            catch (RuntimeException|JsonException $exception) {
                error_log('Analytics cache: '.$exception->getMessage()); $snapshot=null;
                $html.='<p class="error">Кэш аналитики недоступен: проверьте права или пересоздайте отчёт.</p>';
            }
            $html.=DashboardView::render($d,$snapshot,$_SESSION['csrf'],$days);
        }
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
