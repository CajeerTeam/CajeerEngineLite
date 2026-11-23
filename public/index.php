<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Admin\AdminExperience;
use CajeerEngine\Admin\PagesManagement;
use CajeerEngine\AI\{AIConfig,CostPolicy,OpenAIClient,UsageLimiter,ConversationStore,ChatView,ContentGenerator,ContentView,PageEditor,PageEditorView,PageHistory,SEOAudit,SEOAssistant,SEOView,AnalyticsInsights,AnalyticsInsightStore,AnalyticsInsightView,SupportDiagnostics,TechnicalSupport,SupportStore,SupportView,ProductionAIAcceptance};
use CajeerEngine\Auth\{OidcClient,OidcFlow,OwnerIdentity,SessionRegistry,AuthPolicy,StableAcceptance};
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Analytics\{ReportConfig,ReportService,ReportCache,HttpClient,DashboardView};
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Consent\ConsentConfig;
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig,ClarityConfig,MatomoConfig,PublicCsp,IntegrationDiagnostics,ProductionAcceptance};
$app=new Lite(dirname(__DIR__));
function e(string $s): string { return Lite::esc($s); }
function stop(int $status,string $msg): never { http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }
function go(string $path): never {
    // Keep the administrator in the section that was just updated.
    // Mapping is server-owned; submitted data never becomes a redirect target.
    if ($path==='/admin' && ($_SERVER['REQUEST_METHOD']??'')==='POST') {
        $action=$_POST['action']??null;
        $views=[
            'save'=>'pages','delete'=>'pages','pages_bulk'=>'pages','page_status'=>'pages','page_duplicate'=>'pages','settings'=>'settings','modules'=>'settings',
            'consent'=>'integrations','ga4'=>'integrations','metrica'=>'integrations',
            'gtm'=>'integrations','clarity'=>'integrations','matomo'=>'integrations',
            'session_revoke_others'=>'security',
        ];
        if (is_string($action) && isset($views[$action])) {
            $_SESSION['admin_notice']='Изменения сохранены.';
            $path='/admin?view='.$views[$action];
        }
    }
    header('Location: '.$path,true,303); exit;
}
function shell(string $title,string $html,bool $aiChat=false): never {
    header('Content-Type: text/html; charset=utf-8');
    $route=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    $admin=is_string($route) && ($route==='/admin' || str_starts_with($route,'/admin/ai')) &&
        $title!=='Вход в админ-панель';
    if ($admin) {
        $view=$route==='/admin' ? AdminExperience::view($_GET) : 'ai';
        $html=AdminExperience::frame($title,$html,$route,$view,$_SESSION['csrf']);
    } else {
        $html='<header><a href="/">На сайт</a><a href="/admin">CajeerEngine Lite</a></header>'.
            '<main><h1>'.e($title).'</h1>'.$html.'</main>';
    }
    echo '<!doctype html><html lang="ru"><meta charset="utf-8">'.
        '<meta name="viewport" content="width=device-width, initial-scale=1">'.
        '<title>'.e($title).'</title><link rel="stylesheet" href="/assets/style.css">'.
        ($admin?'<link rel="stylesheet" href="/assets/admin.css">':'').
        '<body'.($admin?' class="admin-body"':'').'>'.$html.
        ($aiChat?'<script src="/assets/ai-chat.js" defer></script>':'').'</body></html>';
    exit;
}
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
    if ($path==='/admin' || $path==='/admin/ai' || $path==='/admin/ai/content' || $path==='/admin/ai/editor' || $path==='/admin/ai/seo' || $path==='/admin/ai/analytics' || $path==='/admin/ai/support' || str_starts_with($path, '/admin/oidc/')) {
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
        if ($path === '/admin/ai/support') {
            if (!$auth) stop(403,'Administrator login required');
            $rawCategory=$method==='POST' ? ($_POST['category']??'general') : ($_GET['category']??'general');
            if (!is_string($rawCategory)) stop(400,'Invalid support category');
            try { $category=SupportDiagnostics::category($rawCategory); }
            catch (InvalidArgumentException) { stop(400,'Invalid support category'); }
            $snapshot=SupportDiagnostics::collect($app->root,$d);
            if ($method!=='POST' && isset($_GET['format'])) {
                if ($_GET['format']!=='json') stop(400,'Invalid report format');
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="cajeer-lite-diagnostics.json"');
                echo json_encode(['category'=>$category,'snapshot'=>$snapshot],
                    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit;
            }
            $store=new SupportStore($app->root,$d['identity']['generation']);
            $error='';$insight=null;
            if ($method==='POST') {
                if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) stop(403,'Invalid CSRF token');
                $action=$_POST['action']??null;
                if (!is_string($action) || !in_array($action,['generate','delete'],true)) stop(400,'Invalid support action');
                try {
                    if ($action==='delete') { $store->delete($category,$snapshot);go('/admin/ai/support?category='.$category); }
                    if (empty($d['ai']['enabled']) || !OpenAIClient::configured()) stop(503,'OpenAI is not configured');
                    // No arbitrary commands, raw logs, user text or server paths reach the provider.
                    $insight=TechnicalSupport::generate($category,$snapshot,$d,new OpenAIClient(),new UsageLimiter($app->root));
                    $latest=$app->load();
                    if (!hash_equals($latest['identity']['generation'],$d['identity']['generation']) ||
                        !hash_equals(SupportDiagnostics::fingerprint($snapshot),
                            SupportDiagnostics::fingerprint(SupportDiagnostics::collect($app->root,$latest))) ||
                        !$sessions->valid($_SESSION,$latest)) stop(409,'Diagnostic state or session changed; retry');
                    $store->save($category,$snapshot,$insight);
                    go('/admin/ai/support?category='.$category);
                } catch (InvalidArgumentException $failure) {
                    http_response_code(422);$error=$failure->getMessage();
                } catch (RuntimeException $failure) {
                    http_response_code(502);
                    error_log('AI technical support: '.get_class($failure));
                    $error='Не удалось выполнить AI-анализ. Проверьте подключение OpenAI, лимиты и диагностику.';
                }
            }
            try { $insight=$store->read($category,$snapshot); }
            catch (RuntimeException $failure) {
                error_log('AI technical support storage: '.get_class($failure));
                $error='Ранее сохранённые рекомендации недоступны. Проверьте права файлов.';
            }
            shell('AI Technical Support',SupportView::render($category,$snapshot,$insight,
                $_SESSION['csrf'],!empty($d['ai']['enabled']) && OpenAIClient::configured(),$error));
        }
        if ($path === '/admin/ai/analytics') {
            if (!$auth) stop(403,'Administrator login required');
            $rawDays=$method==='POST' ? ($_POST['days']??'7') : ($_GET['days']??'7');
            if (!is_string($rawDays) || !in_array($rawDays,['3','7','14','30','90'],true)) stop(400,'Invalid reporting period');
            $days=(int)$rawDays;
            $error=''; $report=null; $insight=null;
            try {
                $report=(new ReportCache($app->root))->read($d,$days);
                if ($report!==null) {
                    $facts=AnalyticsInsights::facts($report);
                    if ($facts['days']!==$days) throw new RuntimeException('Report period mismatch');
                }
            } catch (RuntimeException|JsonException|InvalidArgumentException $failure) {
                error_log('AI analytics report read: '.get_class($failure));
                $error='Кэш отчёта недоступен или повреждён. Обновите его в Analytics Dashboard.';
                $report=null;
            }
            $store=new AnalyticsInsightStore($app->root,$d['identity']['generation']);
            if ($method==='POST') {
                if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) stop(403,'Invalid CSRF token');
                $action=$_POST['action']??null;
                if (!is_string($action) || !in_array($action,['generate','clear'],true)) stop(400,'Invalid analytics action');
                if ($report===null) stop(409,'Reporting snapshot is unavailable');
                try {
                    if ($action==='clear') {
                        $store->delete($report);
                        go('/admin/ai/analytics?days='.$days);
                    }
                    if (!$d['ai']['enabled'] || !OpenAIClient::configured()) stop(503,'OpenAI is not configured');
                    $insight=AnalyticsInsights::generate($report,$d,new OpenAIClient(),new UsageLimiter($app->root));
                    $store->save($report,$insight);
                    go('/admin/ai/analytics?days='.$days);
                } catch (InvalidArgumentException $failure) {
                    http_response_code(422); $error=$failure->getMessage();
                } catch (RuntimeException $failure) {
                    http_response_code(502); $error='AI-анализ недоступен: проверьте отчёт, настройки и лимиты OpenAI.';
                    error_log('AI analytics: '.get_class($failure));
                }
            }
            if ($report!==null) {
                try { $insight=$store->read($report); }
                catch (RuntimeException|JsonException $failure) {
                    $error='Ранее сохранённый AI-анализ недоступен. Создайте новый.';
                    error_log('AI analytics history: '.get_class($failure));
                }
            }
            shell('Cajeer AI Analytics Insights',AnalyticsInsightView::render($report,$insight,
                $_SESSION['csrf'],$days,$d['ai']['enabled'] && OpenAIClient::configured(),$error));
        }
        if ($path === '/admin/ai/seo') {
            if (!$auth) stop(403, 'Administrator login required');
            $chosen = $_GET['path'] ?? $_POST['path'] ?? '/';
            if (!is_string($chosen) || !isset($d['pages'][$chosen])) stop(404, 'Page not found');
            $proposal = $_SESSION['ai_seo_proposal'] ?? null;
            if (!is_array($proposal) || !is_int($proposal['created'] ?? null) ||
                $proposal['created'] > time() || time()-$proposal['created'] > 1800 ||
                !is_string($proposal['owner_generation'] ?? null) ||
                !hash_equals($proposal['owner_generation'], $d['identity']['generation'])) {
                unset($_SESSION['ai_seo_proposal']);
                $proposal = null;
            }
            $audit = SEOAudit::run($d);
            if ($method === 'GET' || $method === 'HEAD') {
                shell('Cajeer AI SEO Assistant', SEOView::render($d, $_SESSION['csrf'], $audit,
                    $proposal, $chosen, OpenAIClient::configured()));
            }
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
                stop(403, 'Invalid CSRF token');
            }
            $action = $_POST['action'] ?? null;
            if (!is_string($action) || !in_array($action, ['generate','apply','discard'], true)) {
                stop(400, 'Invalid SEO action');
            }
            $error = '';
            try {
                if ($action === 'discard') {
                    unset($_SESSION['ai_seo_proposal']);
                    go('/admin/ai/seo?path='.rawurlencode($chosen));
                }
                if ($action === 'generate') {
                    if (!$d['ai']['enabled'] || !OpenAIClient::configured()) stop(503, 'OpenAI is not configured');
                    unset($_SESSION['ai_seo_proposal']);
                    $proposal = SEOAssistant::prepare($_POST, $d, new OpenAIClient(), new UsageLimiter($app->root));
                    $_SESSION['ai_seo_proposal'] = $proposal;
                    go('/admin/ai/seo?path='.rawurlencode($chosen));
                }
                if ($proposal === null || $proposal['path'] !== $chosen ||
                    !is_string($_POST['id'] ?? null) || !hash_equals($proposal['id'], $_POST['id'])) {
                    stop(409, 'SEO preview missing or expired');
                }
                $history = new PageHistory($app->root, $d['identity']['generation']);
                SEOAssistant::apply($app, $history, $proposal, $_POST);
                unset($_SESSION['ai_seo_proposal']);
                go('/admin/ai/seo?path='.rawurlencode($chosen));
            } catch (InvalidArgumentException $failure) {
                http_response_code(422);
                $error = $failure->getMessage();
            } catch (RuntimeException $failure) {
                http_response_code(502);
                $error = 'SEO generation is unavailable. Check API settings and storage.';
                error_log('Cajeer SEO: '.get_class($failure));
            }
            $latest = $app->load();
            shell('Cajeer AI SEO Assistant', SEOView::render($latest, $_SESSION['csrf'],
                SEOAudit::run($latest), $_SESSION['ai_seo_proposal'] ?? null,
                $chosen, OpenAIClient::configured(), $error));
        }
        if ($path === '/admin/ai/editor') {
            if (!$auth) stop(403, 'Administrator login required');
            $chosen=$method==='POST' ? ($_POST['path']??null) : ($_GET['path']??null);
            if ($chosen===null) $chosen=array_key_first($d['pages']);
            if (!is_string($chosen) || !isset($d['pages'][$chosen])) stop(400, 'Invalid editor page');
            $history=new PageHistory($app->root,$d['identity']['generation']);
            $proposal=$_SESSION['ai_editor_proposal']??null;
            if (!is_array($proposal) || !is_int($proposal['created']??null) ||
                $proposal['created']>time() || time()-$proposal['created']>1800 ||
                !is_string($proposal['owner_generation']??null) ||
                !hash_equals($d['identity']['generation'],$proposal['owner_generation'])) {
                unset($_SESSION['ai_editor_proposal']);
                $proposal=null;
            }
            if ($method==='GET' || $method==='HEAD') {
                shell('AI — редактор страниц',PageEditorView::render($d,$_SESSION['csrf'],
                    $proposal,$history->all($chosen),OpenAIClient::configured(),$chosen));
            }
            if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) {
                stop(403,'Invalid CSRF token');
            }
            $action=$_POST['action']??null;
            if (!is_string($action) || !in_array($action,['generate','apply','discard','restore'],true)) {
                stop(400,'Invalid AI editor action');
            }
            $error='';
            try {
                if ($action==='discard') {
                    unset($_SESSION['ai_editor_proposal']);
                    go('/admin/ai/editor?path='.rawurlencode($chosen));
                }
                if ($action==='generate') {
                    if (!$d['ai']['enabled'] || !OpenAIClient::configured()) stop(503,'OpenAI is not configured');
                    unset($_SESSION['ai_editor_proposal']);
                    $proposal=PageEditor::prepare($_POST,$d,new OpenAIClient(),new UsageLimiter($app->root));
                    $_SESSION['ai_editor_proposal']=$proposal;
                    go('/admin/ai/editor?path='.rawurlencode($chosen));
                }
                if ($action==='apply') {
                    if ($proposal===null || $proposal['path']!==$chosen ||
                        !is_string($_POST['id']??null) || !hash_equals($proposal['id'],$_POST['id'])) {
                        stop(409,'Editor preview is unavailable or expired');
                    }
                    PageEditor::apply($app,$history,$proposal,$_POST);
                    unset($_SESSION['ai_editor_proposal']);
                    go('/admin/ai/editor?path='.rawurlencode($chosen));
                }
                if (($_POST['confirm_restore']??null)!=='1' ||
                    !is_string($_POST['snapshot_id']??null) || !is_string($_POST['revision']??null)) {
                    throw new InvalidArgumentException('Confirm the history revision to restore');
                }
                $history->restore($app,$chosen,$_POST['snapshot_id'],$_POST['revision']);
                unset($_SESSION['ai_editor_proposal']);
                go('/admin/ai/editor?path='.rawurlencode($chosen));
            } catch (InvalidArgumentException $failure) {
                http_response_code(422);$error=$failure->getMessage();
            } catch (RuntimeException $failure) {
                http_response_code(502);$error='AI editor is temporarily unavailable. Check API limits or storage.';
                error_log('Cajeer AI editor: '.get_class($failure));
            }
            $latest=$app->load();
            shell('AI — редактор страниц',PageEditorView::render($latest,$_SESSION['csrf'],
                $_SESSION['ai_editor_proposal']??null,$history->all($chosen),
                OpenAIClient::configured(),$chosen,$error));
        }
        if ($path === '/admin/ai/content') {
            if (!$auth) stop(403, 'Administrator login required');
            $proposal = $_SESSION['ai_content_proposal'] ?? null;
            if (!is_array($proposal) || !is_int($proposal['created'] ?? null) ||
                $proposal['created'] > time() || time() - $proposal['created'] > 1800 ||
                !is_string($proposal['owner_generation'] ?? null) ||
                !hash_equals($d['identity']['generation'], $proposal['owner_generation'])) {
                unset($_SESSION['ai_content_proposal']);
                $proposal = null;
            }
            if ($method === 'GET' || $method === 'HEAD') {
                shell('AI — генерация контента', ContentView::render($d, $_SESSION['csrf'],
                    $proposal, OpenAIClient::configured()));
            }
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
                stop(403, 'Invalid CSRF token');
            }
            $action = $_POST['action'] ?? null;
            if (!is_string($action) || !in_array($action, ['generate','apply','discard'], true)) {
                stop(400, 'Invalid AI content action');
            }
            $error = '';
            try {
                if ($action === 'discard') {
                    unset($_SESSION['ai_content_proposal']);
                    go('/admin/ai/content');
                }
                if ($action === 'generate') {
                    if (!$d['ai']['enabled'] || !OpenAIClient::configured()) {
                        stop(503, 'OpenAI is not configured');
                    }
                    // Invalidate an older proposal before starting a paid request.
                    unset($_SESSION['ai_content_proposal']);
                    $proposal = ContentGenerator::generate($_POST, $d, new OpenAIClient(),
                        new UsageLimiter($app->root));
                    $_SESSION['ai_content_proposal'] = $proposal;
                    go('/admin/ai/content');
                }
                if ($proposal === null || !is_string($_POST['id'] ?? null) ||
                    !hash_equals($proposal['id'] ?? '', $_POST['id'])) {
                    stop(409, 'Generated preview is missing or expired');
                }
                $savedPath = ContentGenerator::apply($app, $proposal, $_POST);
                unset($_SESSION['ai_content_proposal']);
                go('/admin?edit='.rawurlencode($savedPath));
            } catch (InvalidArgumentException $failure) {
                http_response_code(422);
                $error = $failure->getMessage();
            } catch (RuntimeException $failure) {
                http_response_code(502);
                $error = $failure->getMessage();
            }
            shell('AI — генерация контента', ContentView::render($app->load(), $_SESSION['csrf'],
                $_SESSION['ai_content_proposal'] ?? null, OpenAIClient::configured(), $error));
        }
        if ($path==='/admin/ai') {
            if (!$auth) stop(403,'Administrator login required');
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'");
            $store=new ConversationStore($app->root,$d['identity']['generation']);
            if ($method==='GET' || $method==='HEAD') {
                $all=$store->all();
                $id=$_GET['id']??($all[0]['id']??null);
                if ($id!==null && !is_string($id)) stop(400,'Invalid conversation ID');
                try { $chat=$id!==null?$store->get($id):null; }
                catch (InvalidArgumentException|RuntimeException) { stop(404,'Conversation unavailable'); }
                shell('Cajeer AI Assistant',ChatView::render($all,$chat,$_SESSION['csrf'],$d['ai'],OpenAIClient::configured()),true);
            }
            if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) stop(403,'Invalid CSRF token');
            $action=$_POST['action']??null;
            if (!is_string($action)) stop(400,'Invalid AI action');
            try {
                if ($action==='create') {
                    $chat=$store->create();go('/admin/ai?id='.$chat['id']);
                }
                if ($action==='delete') {
                    if (!is_string($_POST['id']??null)) stop(400,'Invalid conversation ID');
                    $store->delete($_POST['id']);go('/admin/ai');
                }
                if ($action==='clear') { $store->clear();go('/admin/ai'); }
                if ($action==='cancel') {
                    if (!is_string($_POST['id']??null)) stop(400,'Invalid conversation ID');
                    $store->cancel($_POST['id']);
                    header('Content-Type: application/json; charset=utf-8');echo '{"cancelled":true}';exit;
                }
                if ($action!=='send') stop(400,'Invalid AI action');
                if (!$d['ai']['enabled'] || !OpenAIClient::configured()) stop(503,'OpenAI is not configured');
                $id=$_POST['id']??null;$message=$_POST['message']??null;
                if (!is_string($id) || !is_string($message)) stop(422,'Invalid message');
                $pending=$store->begin($id,$message,$d['ai']['max_input_chars']);
            } catch (InvalidArgumentException $failure) { stop(422,$failure->getMessage()); }
              catch (RuntimeException $failure) { stop(409,'Conversation operation unavailable'); }
            // Release the PHP session lock while OpenAI responds, so other devices
            // can revoke access and the Stop action can cancel an in-flight request.
            session_write_close();
            $stream=str_contains($_SERVER['HTTP_ACCEPT']??'','application/x-ndjson');
            if ($stream) {
                header('Content-Type: application/x-ndjson; charset=utf-8');
                header('X-Accel-Buffering: no');
                header('Cache-Control: no-store, no-transform');
                ignore_user_abort(true);
                while (ob_get_level()>0) ob_end_flush();
            }
            $emit=static function(array $event)use($stream):void {
                if ($stream) { echo json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n"; flush(); }
            };
            $stillAllowed=static function()use($app,$sessions,$store,$id,$pending):bool {
                $latest=$app->load();
                return hash_equals($latest['identity']['generation'],$pending['generation']) &&
                    $sessions->valid($_SESSION,$latest) &&
                    $store->active($id,$pending['token']);
            };
            $pending['generation']=$d['identity']['generation'];
            try {
                $client=new OpenAIClient();$quota=new UsageLimiter($app->root);
                $deltas=0;
                $onDelta=static function(string $delta)use(&$deltas,$emit,$stillAllowed):void {
                    if (++$deltas%8===1 && !$stillAllowed()) throw new RuntimeException('Chat request cancelled or access revoked');
                    $emit(['type'=>'delta','text'=>$delta]);
                };
                $result=$quota->chat($client,$d['ai'],$pending['messages'],$stream,$onDelta);
                if (!$stillAllowed()) throw new RuntimeException('Chat request cancelled or access revoked');
                $store->complete($id,$pending['token'],$result['text']);
                if ($stream) {
                    $emit(['type'=>'done','input_tokens'=>$result['input_tokens'],
                        'output_tokens'=>$result['output_tokens'],'model'=>$result['model']]);exit;
                }
                go('/admin/ai?id='.$id);
            } catch (Throwable $failure) {
                try { $store->cancel($id,$pending['token']); } catch (Throwable) {}
                error_log('Cajeer AI chat: '.get_class($failure));
                if ($stream) { $emit(['type'=>'error','message'=>'Не удалось получить ответ. Проверьте настройки, лимиты или подключение.']);exit; }
                stop(502,'Chat generation failed; retry from the conversation');
            }
        }
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
        // Validate selected administration section before handling a mutating request.
        if ($method==='GET' || $method==='HEAD') {
            try { AdminExperience::view($_GET); }
            catch (InvalidArgumentException) { stop(400,'Invalid administration view'); }
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
                elseif ($action==='ai_cost_settings') {
                    $policy=CostPolicy::fromPost($_POST);
                    CostPolicy::save($app->root,$policy);
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
                elseif ($action==='page_status') {
                    $old=Lite::field($_POST,'old',1024,true);
                    $revision=Lite::field($_POST,'revision',64,true);
                    $published=$_POST['published']??null;
                    if (!is_string($published) || !in_array($published,['0','1'],true)) throw new InvalidArgumentException('Invalid publication status');
                    $app->change(static fn(array $state): array => PagesManagement::status($state,$old,$revision,$published==='1'));
                    go('/admin');
                }
                elseif ($action==='pages_bulk') {
                    $operation=Lite::field($_POST,'operation',16,true);
                    $selected=$_POST['selected']??null;
                    $revisions=$_POST['revisions']??null;
                    $app->change(static fn(array $state): array => PagesManagement::bulk($state,$selected,$revisions,$operation));
                    go('/admin');
                }
                elseif ($action==='page_duplicate') {
                    $old=Lite::field($_POST,'old',1024,true);
                    $revision=Lite::field($_POST,'revision',64,true);
                    $app->change(static fn(array $state): array => PagesManagement::duplicate($state,$old,$revision));
                    go('/admin');
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
        $alerts=$html;
        if (isset($_SESSION['admin_notice']) && is_string($_SESSION['admin_notice'])) {
            $alerts.='<p class="admin-feedback" role="status">'.e($_SESSION['admin_notice']).'</p>';
            unset($_SESSION['admin_notice']);
        }
        $html=''; $panels=[];
        $identity=$d['identity']['owner'] ?? null;
        $ai=$d['ai'];
        $html.='<p><a href="/admin/ai">Cajeer AI Assistant — открыть чат</a></p>';
        $html.='<p><a href="/admin/ai/content">AI Content Generation — создать страницу, заголовок или описание</a></p>';
        $html.='<p><a href="/admin/ai/editor">AI Page Editor — улучшить существующие страницы и восстановить версии</a></p>';
        $html.='<p><a href="/admin/ai/seo">AI SEO Assistant — аудит и исправления страниц</a></p>';
        $html.='<p><a href="/admin/ai/analytics">AI Analytics Insights — анализ отчётов и конверсий</a></p>';
        $html.='<p><a href="/admin/ai/support">AI Technical Support — диагностика и поддержка CMS</a></p>';
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
        try {
            $policy=CostPolicy::load($app->root);
            $costFields='<label><input type="checkbox" name="cost_enabled" value="1" '.
                ($policy['enabled']?'checked':'').'> Включить денежный бюджет (оценка, не лимит биллинга OpenAI)</label>';
            foreach (['daily_cents'=>'Дневной лимит, центы USD',
                'monthly_cents'=>'Месячный лимит, центы USD',
                'input_cents_per_million'=>'Цена входных 1 млн токенов, центы USD',
                'output_cents_per_million'=>'Цена выходных 1 млн токенов, центы USD',
                'max_concurrent'=>'Одновременных запросов максимум'] as $key=>$label) {
                $costFields.='<label>'.e($label).'<input type="number" min="0" step="1" name="cost_'.e($key).
                    '" value="'.e((string)$policy[$key]).'" required></label>';
            }
            $html.='<section id="ai-cost"><h3>AI Security & Cost Control</h3><p>Цены задаются вами по тарифам выбранной модели. ' .
                'Подсчёт приблизителен, оплаченный счёт OpenAI может отличаться. Бюджет изначально отключён ' .
                'до настройки действительных цен. Лимит одновременно выполняемых запросов действует всегда.</p>'.
                form('ai_cost_settings',$costFields.'<button>Сохранить AI-бюджет</button>').'</section>';
        } catch (RuntimeException $failure) {
            $html.='<p class="error">Невозможно прочитать защищённые настройки AI-бюджета.</p>';
        }
        $html.='<section id="ai-release"><h3>Cajeer AI Assistant 1.3.0 — проверка готовности</h3>'.
            '<p>Без платных запросов и без передачи содержимого сайта в OpenAI. '.
            'Для проверки реальных генераций используйте только серверный CLI с явным разрешением расходов.</p>'.
            '<p><a href="/admin?ai_release=1#ai-release">Проверить локальную готовность AI</a></p>';
        if (($_GET['ai_release']??null)==='1') {
            try {
                $gate=ProductionAIAcceptance::run($app->root,$d);
                $html.='<p>'.($gate['automated_ok']?
                    'Локальные проверки пройдены; production-приёмка ещё не подтверждена.':
                    'Есть ошибки конфигурации или безопасности AI.').'</p>';
                foreach ($gate['checks'] as $item) {
                    if ($item['status']==='pass') continue;
                    $html.='<p class="'.($item['status']==='fail'?'error':'notice').'">'.
                        e(strtoupper($item['status']).': '.$item['message']).'</p>';
                }
                foreach ($gate['manual'] as $step) $html.='<p class="notice">'.e($step).'</p>';
            } catch (\Throwable) {
                $html.='<p class="error">Не удалось выполнить диагностику AI на этой установке.</p>';
            }
        }
        $html.='<p>CLI: <code>php bin/cajeer ai release-check --json</code>. '.
            'Платный запуск: <code>--live --paid</code> с отдельным подтверждением оператора.</p></section>';
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
            $html.='<p>Параллельных запросов: '.e((string)$usage['active_requests']).
                '. Оценка трат (центы USD): за сутки '.e(number_format($usage['spent_microcents']/1000000,4,'.','')).
                ', за месяц '.e(number_format($usage['monthly_spent_microcents']/1000000,4,'.','')).
                ', резерв на месяц '.e(number_format($usage['monthly_reserved_microcents']/1000000,4,'.','')).'.</p>';
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
        $panels['ai']=$html; $html='';
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
        $panels['security']=$html; $html='';
        try { $html.=PagesManagement::render($d['pages'],$_GET,$_SESSION['csrf']); }
        catch (InvalidArgumentException) { stop(400,'Invalid pages filter'); }
        $edit=$_GET['edit']??null;
        if ((is_string($edit) && isset($d['pages'][$edit])) || isset($_GET['new'])) {
            $p=is_string($edit)&&isset($d['pages'][$edit])?$d['pages'][$edit]:['path'=>'','title'=>'','description'=>'','body'=>'','published'=>false,'menu'=>true];
            $old=$p['path']; $rev=$old!==''?hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)):'';
            $hidden='<input type="hidden" name="old" value="'.e($old).'"><input type="hidden" name="revision" value="'.e($rev).'">';
            $html.=form('save','<h2>Редактор страницы</h2>'.$hidden.input('path',$p['path']).input('title',$p['title']).input('description',$p['description']).'<label>Текст<textarea name="body">'.e($p['body']).'</textarea></label><label><input type="checkbox" name="published" value="1" '.($p['published']?'checked':'').'> Опубликовано</label><label><input type="checkbox" name="menu" value="1" '.($p['menu']?'checked':'').'> В меню</label><button>Сохранить</button>');
            if ($old!==''&&$old!=='/') $html.=form('delete',$hidden.'<button>Удалить страницу</button>');
        }
        $panels['pages']=$html; $html='';
        $s=$d['settings']; $html.=form('settings','<h2>Настройки сайта</h2>'.input('name',$s['name']).input('url',$s['url']).input('email',$s['email'],'email').input('phone',$s['phone']).'<button>Сохранить настройки</button>');
        $flags='<h2>Модули</h2>';
        foreach (ModuleConfig::DEFAULTS as $name => $_) {
            $flags.='<label><input type="checkbox" name="module['.e($name).']" value="1" '.(ModuleConfig::enabled($d,$name)?'checked':'').'> '.e($name).'</label>';
        }
        $html.=form('modules',$flags.'<button>Сохранить модули</button>');
        $panels['settings']=$html; $html='';
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
        $panels['integrations']=$html; $html='';
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
        $panels['analytics']=$html;
        $view=AdminExperience::view($_GET);
        // Keep page-management validation errors next to the affected controls,
        // including a stale revision after another editor has modified a page.
        if ($method==='POST' && $error!=='' && in_array($action ?? '', ['pages_bulk','page_status','page_duplicate'], true)) {
            $view='pages';
        }
        $dashboard=AdminExperience::dashboard($d,count($devices),$report['ok'],OpenAIClient::configured());
        $html=AdminExperience::sections($panels,$view,$dashboard,$alerts);
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
