<?php
declare(strict_types=1);
namespace CajeerEngine;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\AI\AIConfig;
use CajeerEngine\Analytics\ReportConfig;
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Consent\{ConsentConfig,ConsentCookie};
use CajeerEngine\Integrations\{GA4Config,MetricaConfig,GTMConfig,ClarityConfig,MatomoConfig};
use CajeerEngine\Storage\{SiteRepository,BackupService,SiteState};
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};

final class Lite
{
    public const VERSION = '1.3.0';
    /** Upstream baseline for the shared, dependency-free core components, not Lite's application version. */
    public const ENGINE_BASE_VERSION = '2.1.0';
    public function __construct(public readonly string $root) {}
    private function repository(): SiteRepository { return new SiteRepository($this->root); }
    public function file(): string { return $this->root.'/storage/site.json'; }
    public function load(): array { return $this->repository()->load(); }
    public function change(callable $fn): array { return $this->repository()->transaction($fn); }
    public function migrate(): bool { return $this->repository()->migrate(); }
    public function backups(): BackupService { return new BackupService($this->repository()); }
    public function module(string $name, bool $enabled): void {
        $this->change(static fn(array $state): array => ModuleConfig::set($state, $name, $enabled));
    }
    public static function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    public static function field(array $a,string $key,int $max,bool $required=false): string {
        $v=$a[$key]??'';
        if (!is_string($v) || strlen($v)>$max || !preg_match('//u',$v) || str_contains($v,"\0")) throw new \InvalidArgumentException('Invalid field: '.$key);
        $v=trim($v); if ($required && $v==='') throw new \InvalidArgumentException('Required: '.$key); return $v;
    }
    public static function page(array $a): array {
        $raw=self::field($a,'path',1024,true);
        if (!str_starts_with($raw,'/')) throw new \InvalidArgumentException('Path must start with /');
        $path=PublicPath::content($raw);
        if (!str_starts_with($path,'/') || str_contains($path,'.')) throw new \InvalidArgumentException('Invalid page path');
        return ['path'=>$path,'title'=>self::field($a,'title',200,true),'description'=>self::field($a,'description',500),'body'=>self::field($a,'body',60000),'published'=>($a['published']??'')==='1','menu'=>($a['menu']??'')==='1'];
    }
    public static function settings(array $a): array {
        $name=self::field($a,'name',200,true); $url=PublicPath::base(self::field($a,'url',500,true));
        $email=self::field($a,'email',254); if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid email');
        $phone=self::field($a,'phone',40); if ($phone!=='' && !preg_match('/^\+?[0-9 ()-]{5,40}$/D',$phone)) throw new \InvalidArgumentException('Invalid phone');
        return compact('name','url','email','phone');
    }
    public function install(string $password,string $url,string $name): void {
        if (strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0")) throw new \InvalidArgumentException('Password: 12–72 bytes');
        $settings=self::settings(compact('name','url'));
        $this->change(function($d) use($password,$settings) {
            if ($d!==null) throw new \InvalidArgumentException('Already installed');
            $home=self::page(['path'=>'/','title'=>'Добро пожаловать','description'=>'Сайт на CajeerEngine Lite','body'=>'Расскажите о себе, своих услугах и контактах. Измените эту страницу в административной панели.','published'=>'1','menu'=>'1']);
            return ['schema'=>SiteState::SCHEMA,'settings'=>$settings,'password'=>password_hash($password,PASSWORD_DEFAULT),'pages'=>['/'=>$home],'login'=>['count'=>0,'until'=>0],'modules'=>ModuleConfig::DEFAULTS,'consent'=>ConsentConfig::DEFAULTS,'reporting'=>ReportConfig::DEFAULTS,'identity'=>['owner'=>null,'generation'=>\CajeerEngine\Auth\OwnerIdentity::generation()],'auth_policy'=>\CajeerEngine\Auth\AuthPolicy::DEFAULTS,'ai'=>AIConfig::DEFAULTS,'integrations'=>['ga4'=>GA4Config::DEFAULTS,'metrica'=>MetricaConfig::DEFAULTS,'gtm'=>GTMConfig::DEFAULTS,'clarity'=>ClarityConfig::DEFAULTS,'matomo'=>MatomoConfig::DEFAULTS]];
        });
    }
    public function login(string $password): bool {
        $ok=false;
        $this->change(function($d) use($password,&$ok) {
            $now=time(); $l=$d['login']; if ($l['until']>$now && $l['count']>=5) return $d;
            if ($l['until']<=$now) $l=['count'=>0,'until'=>$now+300];
            $ok=strlen($password)<=72 && !str_contains($password,"\0") && password_verify($password,$d['password']);
            $d['login']=$ok?['count'=>0,'until'=>0]:['count'=>$l['count']+1,'until'=>$l['until']]; return $d;
        }); return $ok;
    }
    public function render(array $d,array $p): string {
        $engine=new CajeerTemplateEngine(new TemplateSandbox($this->root.'/templates'),$this->root.'/templates');
        $nav=ModuleConfig::enabled($d, 'navigation') ? array_values(array_filter($d['pages'],fn($p)=>$p['published']&&$p['menu'])) : [];
        $choice = ConsentCookie::read($_COOKIE[ConsentCookie::NAME] ?? null, $d['consent']);
        return $engine->renderName('default.page',[
            'page'=>$p, 'site'=>$d['settings'], 'navigation'=>$nav,
            'canonical'=>$d['settings']['url'].$p['path'],
            'body_html'=>nl2br(self::esc($p['body']),false),
            'consent'=>$d['consent'], 'consent_saved'=>$choice !== null,
            'consent_mask'=>$choice === null ? 0 : ConsentCookie::mask($choice),
            'ga4_active'=>GA4Config::active($d) && !GTMConfig::active($d), 'ga4'=>$d['integrations']['ga4'],
            'metrica_active'=>MetricaConfig::active($d), 'metrica'=>$d['integrations']['metrica'],
            'gtm_active'=>GTMConfig::active($d), 'gtm'=>$d['integrations']['gtm'],
            'clarity_active'=>ClarityConfig::active($d), 'clarity'=>$d['integrations']['clarity'],
            'matomo_active'=>MatomoConfig::active($d), 'matomo'=>$d['integrations']['matomo'],
        ]);
    }
}
