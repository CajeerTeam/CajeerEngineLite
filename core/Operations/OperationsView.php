<?php
declare(strict_types=1);
namespace CajeerEngine\Operations;

use CajeerEngine\Lite;

/** Readable no-JavaScript operations console; all mutations require authenticated POST + CSRF. */
final class OperationsView
{
    private static function h(string $v): string { return Lite::esc($v); }
    private static function form(string $csrf,string $action,string $content,string $extra=''): string
    {
        return '<form method="post" action="/admin/operations" '.$extra.'><input type="hidden" name="csrf" value="'.self::h($csrf).'">'.
            '<input type="hidden" name="action" value="'.self::h($action).'">'.$content.'</form>';
    }
    public static function render(CmsOperations $service,string $csrf,string $error='',string $notice=''): string
    {
        $report=$service->health();
        $names=$service->names();
        $html='<div class="cms-operations"><p>Резервные копии сайта и медиатеки, переносимый экспорт, проверяемый импорт и обслуживание хранилища. ' .
            'Резервная копия содержит хэш пароля и конфигурацию: храните скачанные архивы в защищённом месте. Экспорт исключает данные входа и настройки AI.</p>';
        if ($error!=='') $html.='<p class="error" role="alert">'.self::h($error).'</p>';
        if ($notice!=='') $html.='<p class="admin-feedback" role="status">'.self::h($notice).'</p>';
        $html.='<div class="cms-ops-stats">';
        foreach ([
            'Схема JSON'=>(string)$report['schema'],
            'Страниц'=>(string)$report['pages'],
            'Медиафайлов'=>(string)$report['media_files'],
            'Бэкапов'=>(string)$report['backups'],
            'Свободно на диске'=>number_format((float)$report['disk_free_bytes']/1048576,0,'.',' ').' MiB',
        ] as $title=>$value) $html.='<div><small>'.self::h($title).'</small><strong>'.self::h($value).'</strong></div>';
        $html.='</div><section><h2>Резервные копии</h2><p>Полный архив включает JSON состояния и все зарегистрированные медиафайлы. ' .
            'Сохранение выполняется под блокировкой медиа и сайта; SHA-256 проверяет содержимое архива.</p>'.
            self::form($csrf,'create','<button type="submit">Создать резервную копию</button>').
            '<div class="dev-table-wrap"><table><thead><tr><th>Архив</th><th>Размер</th><th>Действия</th></tr></thead><tbody>';
        foreach ($names as $name) {
            $path=$service->backupPath($name);
            $html.='<tr><td><code>'.self::h($name).'</code></td><td>'.number_format((float)filesize($path)/1048576,2,'.',' ').' MiB</td><td><div class="cms-ops-row">';
            foreach (['verify'=>'Проверить','download'=>'Скачать'] as $action=>$label) {
                $html.=self::form($csrf,$action,'<input type="hidden" name="name" value="'.self::h($name).'"><button type="submit">'.$label.'</button>');
            }
            $html.=self::form($csrf,'restore','<input type="hidden" name="name" value="'.self::h($name).'">'.
                '<label>Для восстановления введите RESTORE <input name="confirm" required pattern="RESTORE" autocomplete="off" aria-label="Подтверждение восстановления"></label>'.
                '<button class="cms-ops-danger" type="submit">Восстановить</button>');
            $html.='</div></td></tr>';
        }
        if ($names===[]) $html.='<tr><td colspan="3">Резервных копий пока нет.</td></tr>';
        $html.='</tbody></table></div></section>';
        $html.='<section><h2>Переносимый экспорт и импорт</h2><p>Экспорт создаёт TAR-архив с контентом и медиатекой без пароля, привязки владельца и настроек AI. ' .
            'При импорте текущие пароли, SkiF4er ID, политика входа и настройки AI сохраняются. Новые медиа добавляются с прежними ID, существующие не удаляются.</p>'.
            self::form($csrf,'export','<button type="submit">Скачать экспорт сайта (.tar)</button>').
            '<p class="cms-ops-note">Для импорта требуется полный неархивированный TAR-файл до 115 МБ. ' .
            'Максимальный размер загрузки дополнительно ограничен параметрами PHP <code>upload_max_filesize</code> и <code>post_max_size</code> и веб-сервера. ' .
            'Для больших файлов используйте CLI.</p>'.
            self::form($csrf,'import','<label>Архив TAR <input type="file" name="archive" accept=".tar,application/x-tar" required></label>'.
                '<label>Введите IMPORT для подтверждения перезаписи контента <input name="confirm" required pattern="IMPORT" autocomplete="off"></label>'.
                '<button class="cms-ops-danger" type="submit">Импортировать контент и медиа</button>','enctype="multipart/form-data"').
            '</section>';
        $html.='<section><h2>Обслуживание</h2><p>Очистка затрагивает только отчёты аналитики. ' .
            'Удаление старых копий оставляет заданное количество последних архивов; шаблоны, сеансы, история и медиатека не удаляются.</p>'.
            self::form($csrf,'cache','<button type="submit">Очистить кэш аналитики</button>').
            self::form($csrf,'prune','<label>Сохранить последних архивов <input name="keep" type="number" min="1" max="100" value="10" required></label>'.
                '<label>Введите PRUNE для подтверждения <input name="confirm" required pattern="PRUNE" autocomplete="off"></label>'.
                '<button class="cms-ops-danger" type="submit">Удалить старые бэкапы</button>').
            '<p class="cms-ops-note">Для полной диагностики PHP, файлов и шаблонов используйте <a href="/admin/developer">Developer Tools</a>.</p></section></div>';
        return $html;
    }
}
