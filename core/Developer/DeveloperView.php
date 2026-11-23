<?php
declare(strict_types=1);
namespace CajeerEngine\Developer;

use CajeerEngine\Lite;

/** Server-rendered developer console; no client-side code or external dependencies. */
final class DeveloperView
{
    private static function h(string $v): string { return Lite::esc($v); }

    public static function render(string $root,array $site,string $csrf,string $tab,string $selected,string $error='',string $notice='',?string $draft=null): string
    {
        if (!in_array($tab,['diagnostics','templates','events'],true)) throw new \InvalidArgumentException('Invalid developer tab');
        $html='<div class="dev-tools"><p>Проверки выполняются локально. Секреты, идентификаторы владельцев, абсолютные пути и содержимое PHP-логов не выводятся.</p>';
        $html.='<nav class="dev-tabs" aria-label="Инструменты разработчика">';
        foreach (['diagnostics'=>'Диагностика','templates'=>'Шаблоны','events'=>'Отладка и события'] as $key=>$title) {
            $html.='<a href="/admin/developer?tab='.$key.'"'.($key===$tab?' aria-current="page"':'').'>'.self::h($title).'</a>';
        }
        $html.='</nav>';
        if ($error!=='') $html.='<p class="error" role="alert">'.self::h($error).'</p>';
        if ($notice!=='') $html.='<p class="admin-feedback" role="status">'.self::h($notice).'</p>';
        if ($tab==='diagnostics') {
            $report=DeveloperDiagnostics::run($root,$site);
            $html.='<section><h2>Диагностика окружения и рендеринга</h2><p>Успешно: '.(int)$report['counts']['pass'].
                ' · Предупреждения: '.(int)$report['counts']['warn'].' · Ошибки: '.(int)$report['counts']['fail'].'</p>'.
                '<p><a href="/admin/developer?tab=diagnostics&amp;format=json">Скачать безопасный отчёт JSON</a></p>';
            $html.='<div class="dev-table-wrap"><table><thead><tr><th>Проверка</th><th>Статус</th><th>Результат</th></tr></thead><tbody>';
            foreach ($report['checks'] as $check) {
                $html.='<tr><th scope="row"><code>'.self::h($check['id']).'</code></th><td><span class="dev-status dev-'.$check['status'].'">'.
                    self::h(strtoupper($check['status'])).'</span></td><td>'.self::h($check['detail']).
                    ($check['status']==='pass'?'':'<small>'.self::h($check['remedy']).'</small>').'</td></tr>';
            }
            return $html.'</tbody></table></div><p class="dev-muted">Внешняя доставка аналитики, OIDC и интеграции не проверяются этим локальным отчётом.</p></section></div>';
        }
        if ($tab==='events') {
            $html.='<section><h2>Структурированные события</h2><p>Здесь отображаются только коды операций, время UTC и случайные ID. Тексты исключений и пользовательские данные не записываются.</p>';
            $events=DeveloperEvents::recent($root,50);
            if ($events===[]) return $html.'<p>Событий пока нет.</p></section></div>';
            $html.='<div class="dev-table-wrap"><table><thead><tr><th>UTC</th><th>Событие</th><th>Уровень</th><th>ID</th></tr></thead><tbody>';
            foreach ($events as $event) {
                $html.='<tr><td>'.self::h($event['at']).'</td><td><code>'.self::h($event['event']).'</code></td>'.
                    '<td>'.self::h($event['status']).'</td><td><code>'.self::h($event['id']).'</code></td></tr>';
            }
            return $html.'</tbody></table></div></section></div>';
        }
        $manager=new TemplateManager($root);
        $names=$manager->names();
        $selected=$selected!==''?$selected:($names[0]??'');
        $html.='<section><h2>Шаблоны Cajeer Template Engine (.cjr)</h2><p>Изменения влияют на опубликованные страницы. Перед каждым сохранением создаётся приватная версия для восстановления. Предпросмотр не изменяет файлы.</p>';
        if (!$manager->writable()) $html.='<p class="notice">Режим просмотра: запись шаблонов запрещена. Для разрешения установите CAJEER_DEVELOPER_TEMPLATE_WRITE=1 и выдайте PHP-FPM права на запись шаблонов. После редактирования отключите режим.</p>';
        $html.='<div class="dev-template-grid"><aside><h3>Файлы</h3><ul>';
        foreach ($names as $name) $html.='<li><a href="/admin/developer?tab=templates&amp;template='.rawurlencode($name).'"'.
            ($selected===$name?' aria-current="page"':'').'>'.self::h($name).'</a></li>';
        $html.='</ul></aside><div>';
        if ($selected==='') return $html.'<p>Шаблоны отсутствуют.</p></div></div></section></div>';
        $template=$manager->read($selected);
        $source=$draft??$template['source'];
        $html.='<h3>'.self::h($selected).'</h3><p class="dev-muted">Текущая ревизия: <code>'.self::h(substr($template['revision'],0,16)).'</code> · '.(int)$template['size'].' байт</p>';
        $html.='<form method="post" action="/admin/developer" class="dev-template-form">'.
            '<input type="hidden" name="csrf" value="'.self::h($csrf).'">'.
            '<input type="hidden" name="template" value="'.self::h($selected).'">'.
            '<input type="hidden" name="revision" value="'.self::h($template['revision']).'">'.
            '<label for="dev-source">Исходный код шаблона</label>'.
            '<textarea id="dev-source" name="source" required maxlength="131072" rows="22" spellcheck="false">'.self::h($source).'</textarea>'.
            '<label for="dev-page">Страница для просмотра</label><select id="dev-page" name="page">';
        foreach ($site['pages'] as $page) if ($page['published']) {
            $html.='<option value="'.self::h($page['path']).'">'.self::h($page['title'].' ('.$page['path'].')').'</option>';
        }
        $html.='</select><div class="dev-actions"><button type="submit" name="action" value="preview" formmethod="post" formaction="/admin/developer/preview" formtarget="_blank">Предпросмотр без сохранения</button>';
        if ($manager->writable()) $html.='<button type="submit" name="action" value="save">Сохранить шаблон</button>';
        $html.='</div></form><h3>История версий</h3><p class="dev-muted">Последние 20 версий перед изменениями. Восстановление также создаёт резервную копию текущей версии.</p>';
        $history=$manager->history($selected);
        if ($history===[]) $html.='<p>Сохранённых версий пока нет.</p>';
        foreach ($history as $backup) {
            $html.='<form method="post" action="/admin/developer" class="dev-restore">'.
                '<input type="hidden" name="csrf" value="'.self::h($csrf).'">'.
                '<input type="hidden" name="template" value="'.self::h($selected).'">'.
                '<input type="hidden" name="revision" value="'.self::h($template['revision']).'">'.
                '<input type="hidden" name="backup" value="'.self::h($backup).'">'.
                '<code>'.self::h($backup).'</code>'.
                ($manager->writable()?'<button type="submit" name="action" value="restore">Восстановить</button>':'').'</form>';
        }
        return $html.'</div></div></section></div>';
    }
}
