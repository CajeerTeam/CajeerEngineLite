<?php
declare(strict_types=1);
namespace CajeerEngine;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};

final class Lite
{
    public const VERSION = '1.0.0';
    public function __construct(public readonly string $root) {}
    public function file(): string { return $this->root.'/storage/site.json'; }
    public function load(): array {
        $raw = file_get_contents($this->file());
        if ($raw === false) throw new \RuntimeException('Cannot read site');
        $d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($d) || ($d['schema'] ?? null) !== 1) throw new \RuntimeException('Invalid site schema');
        return $d;
    }
    // A stable lock file serializes read-modify-write transactions; atomic rename protects readers.
    public function change(callable $fn): array {
        $dir = dirname($this->file());
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) throw new \RuntimeException('Storage unavailable');
        if (is_link($this->file()) || is_link($dir.'/site.lock')) throw new \RuntimeException('Unsafe storage');
        $lock = fopen($dir.'/site.lock', 'c');
        if (!$lock) throw new \RuntimeException('Cannot open lock');
        $tmp = null;
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock');
            $data = $fn(is_file($this->file()) ? $this->load() : null);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (strlen($json)>4_000_000) throw new \InvalidArgumentException('Site exceeds 4 MB');
            $tmp = $dir.'/.tmp-'.bin2hex(random_bytes(12));
            $h = fopen($tmp, 'xb');
            if (!$h) throw new \RuntimeException('Cannot write');
            try { if (!chmod($tmp, 0600) || fwrite($h,$json)!==strlen($json) || !fflush($h) || !fsync($h)) throw new \RuntimeException('Cannot save'); }
            finally { fclose($h); }
            if (!rename($tmp,$this->file())) throw new \RuntimeException('Cannot replace site');
            return $data;
        } finally { if ($tmp && is_file($tmp)) unlink($tmp); fclose($lock); }
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
            return ['schema'=>1,'settings'=>$settings,'password'=>password_hash($password,PASSWORD_DEFAULT),'pages'=>['/'=>$home],'login'=>['count'=>0,'until'=>0]];
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
        $nav=array_values(array_filter($d['pages'],fn($p)=>$p['published']&&$p['menu']));
        return $engine->renderName('default.page',['page'=>$p,'site'=>$d['settings'],'navigation'=>$nav,'canonical'=>$d['settings']['url'].$p['path'],'body_html'=>nl2br(self::esc($p['body']),false)]);
    }
}
