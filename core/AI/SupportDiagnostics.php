<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Auth\AuthAcceptance;
use CajeerEngine\Integrations\IntegrationDiagnostics;
use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;

/** Read-only support snapshot. No credentials, paths, identities, request headers or raw log content. */
final class SupportDiagnostics
{
    public const CATEGORIES = [
        'general'=>'Общее состояние CMS', 'auth'=>'Вход и SkiF4er ID',
        'storage'=>'JSON, миграции и резервные копии', 'analytics'=>'Аналитика и Cookie Consent',
        'ai'=>'OpenAI и AI-функции', 'deploy'=>'HTTPS и развёртывание',
    ];

    public static function category(string $value): string
    {
        if (!array_key_exists($value,self::CATEGORIES)) throw new \InvalidArgumentException('Invalid support category');
        return $value;
    }

    public static function collect(string $root,array $site): array
    {
        // Only allowlist fields flow into the snapshot, model and downloadable report.
        $checks=[];
        $add=static function(string $id,string $status)use(&$checks):void {
            if (!preg_match('/^[a-z0-9._-]{1,80}$/D',$id) ||
                !in_array($status,['pass','warn','fail'],true)) throw new \RuntimeException('Unsafe diagnostic status');
            $checks[$id]=$status;
        };
        try { SiteState::validate($site); $add('cms.state','pass'); }
        catch (\Throwable) { $add('cms.state','fail'); }
        $add('cms.schema',($site['schema']??null)===SiteState::SCHEMA?'pass':'fail');
        $add('runtime.php',version_compare(PHP_VERSION,'8.4.1','>=')?'pass':'fail');
        $add('runtime.openssl',extension_loaded('openssl')?'pass':'fail');
        $add('runtime.json',extension_loaded('json')?'pass':'fail');
        $add('runtime.https',str_starts_with((string)($site['settings']['url']??''),'https://')?'pass':'warn');
        $add('ai.integration',!empty($site['ai']['enabled'])?'pass':'warn');
        $add('ai.credentials',OpenAIClient::configured()?'pass':'warn');
        try {
            $policy=CostPolicy::load($root);
            $add('ai.cost_budget',$policy['enabled']?'pass':'warn');
            $usage=(new UsageLimiter($root))->status();
            $add('ai.concurrent',($usage['active_requests']<=$policy['max_concurrent'])?'pass':'fail');
        } catch (\Throwable) { $add('ai.cost_storage','fail'); }
        foreach (['auth'=>AuthAcceptance::class,'integration'=>IntegrationDiagnostics::class] as $prefix=>$diagnostic) {
            try {
                $data=$diagnostic::run($root,$site);
                if (!is_array($data['checks']??null)) throw new \RuntimeException('No checks');
                foreach ($data['checks'] as $check) {
                    if (!is_array($check)) continue;
                    $id=$check['id']??null; $status=$check['status']??null;
                    if (is_string($id) && is_string($status)) $add($prefix.'.'.$id,$status);
                }
            } catch (\Throwable) { $add($prefix.'.unavailable','fail'); }
        }
        ksort($checks,SORT_STRING);
        if (count($checks)>90) throw new \RuntimeException('Too many diagnostic checks');
        return ['version'=>Lite::VERSION,'schema'=>is_int($site['schema']??null)?$site['schema']:0,
            'checks'=>$checks, 'counts'=>['pass'=>count(array_filter($checks,static fn($v)=>$v==='pass')),
                'warn'=>count(array_filter($checks,static fn($v)=>$v==='warn')),
                'fail'=>count(array_filter($checks,static fn($v)=>$v==='fail'))]];
    }

    /** Stable digest excludes timestamps so a report cannot be silently attached to different findings. */
    public static function fingerprint(array $snapshot): string
    {
        self::validate($snapshot);
        return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    public static function validate(array $snapshot): void
    {
        if (!is_string($snapshot['version']??null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$snapshot['version']) ||
            !is_int($snapshot['schema']??null) || $snapshot['schema']<0 ||
            !is_array($snapshot['checks']??null) || count($snapshot['checks'])>90 ||
            !is_array($snapshot['counts']??null)) throw new \InvalidArgumentException('Invalid diagnostic snapshot');
        foreach ($snapshot['checks'] as $id=>$status) {
            if (!is_string($id) || !preg_match('/^[a-z0-9._-]{1,80}$/D',$id) ||
                !in_array($status,['pass','warn','fail'],true)) throw new \InvalidArgumentException('Invalid diagnostic check');
        }
        foreach (['pass','warn','fail'] as $name) {
            if (!is_int($snapshot['counts'][$name]??null) ||
                $snapshot['counts'][$name]!==count(array_filter($snapshot['checks'],static fn($v)=>$v===$name))) {
                throw new \InvalidArgumentException('Invalid diagnostic count');
            }
        }
    }

    /** Deterministic first-aid steps work without an OpenAI API key. */
    public static function firstAid(string $category,array $snapshot): array
    {
        self::category($category);
        self::validate($snapshot);
        $steps=[
            'general'=>['Запустите php bin/cajeer doctor на сервере.', 'Проверьте обязательные файлы и права доступа перед обновлением.'],
            'auth'=>['Запустите php bin/cajeer auth check --json.', 'При утрате доступа используйте документированное CLI-восстановление, а не публичный сброс пароля.'],
            'storage'=>['Создайте резервную копию: php bin/cajeer backup create.', 'Проверьте целостность: php bin/cajeer doctor; миграцию выполняйте только после резервирования.'],
            'analytics'=>['Запустите php bin/cajeer integrations check --json.', 'Проверьте настройки Cookie Consent и реальные события в интерфейсе каждого провайдера.'],
            'ai'=>['Проверьте переменную окружения CAJEER_OPENAI_API_KEY без вывода её значения.', 'Посмотрите квоты через php bin/cajeer ai status.'],
            'deploy'=>['Запустите php bin/cajeer release check --json.', 'Проверьте TLS на каноническом домене и параметр HTTPS=on в PHP-FPM.'],
        ][$category];
        if ($snapshot['counts']['fail']>0) array_unshift($steps,'Исправьте проверки со статусом FAIL до переключения конфигурации или обновления CMS.');
        return $steps;
    }
}
