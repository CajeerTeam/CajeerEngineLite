<?php
declare(strict_types=1);
namespace CajeerEngine\Developer;

use CajeerEngine\Lite;
use CajeerEngine\Storage\SiteState;
use CajeerEngine\Update\OperationLock;

/** Actual local probes; no HTTP calls, shell commands, environment values or private paths. */
final class DeveloperDiagnostics
{
    public static function run(string $root,array $site): array
    {
        $checks=[];
        $add=static function(string $id,string $status,string $detail,string $remedy='')use(&$checks):void {
            if (!in_array($status,['pass','warn','fail'],true)) throw new \LogicException('Invalid probe status');
            $checks[]=['id'=>$id,'status'=>$status,'detail'=>$detail,'remedy'=>$remedy];
        };
        $add('runtime.php',version_compare(PHP_VERSION,'8.4.1','>=')?'pass':'fail',
            'Версия PHP: '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
            'Требуется PHP 8.4.1+ на CLI и веб-сервере');
        foreach (['json','filter','hash','session'] as $ext) {
            $add('runtime.'.$ext,extension_loaded($ext)?'pass':'fail',
                'Расширение '.strtoupper($ext).(extension_loaded($ext)?' загружено':' отсутствует'),
                'Установите расширение и перезапустите PHP-FPM');
        }
        $needSsl=str_starts_with((string)($site['settings']['url']??''),'https://') || !empty($site['ai']['enabled']);
        $add('runtime.openssl',extension_loaded('openssl')?'pass':($needSsl?'fail':'warn'),
            extension_loaded('openssl')?'OpenSSL доступен':'OpenSSL отсутствует','Включите OpenSSL');
        try { SiteState::validate($site); $add('site.state','pass','JSON-состояние прошло проверку схемы'); }
        catch (\Throwable) { $add('site.state','fail','JSON-состояние не прошло проверку','Восстановите проверенную резервную копию'); }
        $add('site.schema',($site['schema']??null)===SiteState::SCHEMA?'pass':'fail',
            'Схема: '.(is_int($site['schema']??null)?(string)$site['schema']:'не определена'),
            'Запустите php bin/cajeer migrate');
        $storage=$root.'/storage';
        $add('storage.writable',is_dir($storage) && is_writable($storage)?'pass':'fail',
            is_dir($storage) && is_writable($storage)?'Хранилище доступно для записи':'Хранилище не доступно для записи',
            'Настройте владельца и права storage/');
        $mode=is_dir($storage)?fileperms($storage):false;
        $add('storage.private',is_int($mode) && ($mode & 0077)===0?'pass':'fail',
            is_int($mode) && ($mode & 0077)===0?'Каталог storage закрыт для сторонних пользователей':'Проверьте права storage/ (0700)',
            'Ограничьте доступ к storage/');
        $documentRoot=realpath($root.'/public');$dataRoot=realpath($storage);
        $add('storage.outside_webroot',is_string($documentRoot) && is_string($dataRoot) &&
            !str_starts_with($dataRoot,$documentRoot.DIRECTORY_SEPARATOR) && $dataRoot!==$documentRoot ? 'pass':'fail',
            'Проверено размещение storage относительно public/','Разместите storage вне document root');
        $free=@disk_free_space($storage);
        $add('storage.disk',is_float($free) || is_int($free) ? ($free<10_000_000?'fail':($free<50_000_000?'warn':'pass')):'warn',
            $free===false?'Свободное место не удалось проверить':($free<50_000_000?'На разделе мало свободного места':'Свободного места не менее 50 МБ'),
            'Освободите дисковое пространство');
        $add('site.https',str_starts_with((string)($site['settings']['url']??''),'https://')?'pass':'warn',
            str_starts_with((string)($site['settings']['url']??''),'https://')?'Основной URL использует HTTPS':'Основной URL использует HTTP',
            'Настройте HTTPS и обновите основной URL сайта');
        $add('operations.maintenance',OperationLock::maintenance($root)?'warn':'pass',
            OperationLock::maintenance($root)?'Обнаружена операция обслуживания':'Обслуживание не активно',
            'Проверьте незавершённые операции обновления/восстановления');
        $templates=new TemplateManager($root);
        try {
            $names=$templates->names();
            if (!in_array('default/page.cjr',$names,true)) throw new \RuntimeException('Missing default page template');
            foreach ($names as $name) $templates->validateSource($templates->read($name)['source']);
            $add('templates.syntax','pass','Все '.count($names).' шаблонов успешно скомпилированы и проверены');
        } catch (\Throwable) {
            $add('templates.syntax','fail','Есть шаблоны с ошибками или недоступные файлы','Проверьте шаблоны в Developer Tools');
        }
        try {
            if (!is_array($site['pages']['/']??null)) throw new \RuntimeException('Missing home page');
            $html=(new Lite($root))->render($site,$site['pages']['/']);
            if (trim($html)==='') throw new \RuntimeException('Empty page');
            $add('templates.render','pass','Реальный рендеринг главной страницы выполнен');
        } catch (\Throwable) {
            $add('templates.render','fail','Главная страница не рендерится','Проверьте шаблон и опубликованную главную');
        }
        $add('templates.write_policy',$templates->writable()?'warn':'pass',
            $templates->writable()?'Изменение шаблонов из админ-панели разрешено':'Запись шаблонов через админ-панель отключена',
            'В production оставляйте CAJEER_DEVELOPER_TEMPLATE_WRITE выключенным вне окна обслуживания');
        $summary=['pass'=>0,'warn'=>0,'fail'=>0];
        foreach ($checks as $check) ++$summary[$check['status']];
        return ['version'=>Lite::VERSION,'schema'=>$site['schema']??null,
            'collected_at'=>gmdate('Y-m-d\TH:i:s\Z'), 'ok'=>$summary['fail']===0,
            'counts'=>$summary,'checks'=>$checks];
    }
}
