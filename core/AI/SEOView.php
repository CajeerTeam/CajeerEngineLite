<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;

/** Accessible no-JS SEO dashboard, review and save forms; escapes all remote text. */
final class SEOView
{
    public static function render(array $site, string $csrf, array $audit, ?array $proposal,
                                  string $path, bool $configured, string $error = ''): string
    {
        $e = static fn(string $v): string => Lite::esc($v);
        $hidden = '<input type="hidden" name="csrf" value="'.$e($csrf).'">';
        $html = '<p><a href="/admin">Админ-панель</a> · <a href="/admin/ai">AI-чат</a> · '.
            '<a href="/admin/ai/content">Генерация контента</a> · '.
            '<a href="/admin/ai/editor">AI-редактор</a> · '.
            '<a href="/admin/seo">SEO Control Center</a></p>';
        if ($error !== '') $html .= '<p role="alert" class="error">'.$e($error).'</p>';
        $html .= '<section id="seo-audit"><h2>SEO-аудит сайта</h2>'.
            '<p>Страниц опубликовано: '.(int)$audit['published_count'].'. Проверок, требующих внимания: '.
            (int)$audit['issue_count'].'. Аудит использует содержимое сайта, а не данные о позициях в поиске.</p>';
        foreach ($audit['global'] as $issue) $html .= '<p class="notice">'.$e($issue).'</p>';
        $html .= '<table><thead><tr><th>Страница</th><th>Оценка</th><th>Проблемы</th><th>Проверка</th></tr></thead><tbody>';
        foreach ($audit['pages'] as $row) {
            $issues = array_values(array_filter($row['issues'], static fn(array $issue):bool=>$issue['severity']!=='info'));
            $html .= '<tr><td>'.$e($row['path']).($row['published']?'':' (черновик)').'</td>'.
                '<td>'.(int)$row['score'].'/100</td><td>'.count($issues).'</td>'.
                '<td><a href="/admin/ai/seo?path='.rawurlencode($row['path']).'">Открыть аудит</a></td></tr>';
        }
        $html .= '</tbody></table></section><section><h2>Страница и исправления</h2>'.
            '<form method="get" action="/admin/ai/seo"><label>Страница<select name="path">';
        foreach ($site['pages'] as $page) {
            $html .= '<option value="'.$e($page['path']).'"'.($page['path']===$path?' selected':'').'>'.
                $e($page['title'].' — '.$page['path']).'</option>';
        }
        $html .= '</select></label><button>Проверить страницу</button></form>';
        $page = $site['pages'][$path] ?? null;
        if (!is_array($page)) return $html.'</section>';
        $report = $audit['pages'][$path];
        $html .= '<p>Canonical URL: <code>'.$e($report['canonical']).'</code></p>'.
            '<p>Заголовок: '.(int)$report['title_chars'].' символов, описание: '.
            (int)$report['description_chars'].' символов, текст: '.(int)$report['body_chars'].' символов.</p>';
        if ($report['issues']===[]) $html .= '<p>Встроенные проверки проблем не обнаружили.</p>';
        else {
            $html .= '<ul>';
            foreach ($report['issues'] as $issue) {
                $html .= '<li>'. $e(strtoupper($issue['severity']).': '.$issue['message']).'</li>';
            }
            $html .= '</ul>';
        }
        if (!$site['ai']['enabled'] || !$configured) {
            $html .= '<p class="notice">Для AI-рекомендаций включите OpenAI и настройте серверный API-ключ. ' .
                'Локальный SEO-аудит работает без API.</p>';
        }
        $html .= '<form method="post" action="/admin/ai/seo">'.$hidden.
            '<input type="hidden" name="action" value="generate">'.
            '<input type="hidden" name="path" value="'.$e($path).'">'.
            '<label>Режим рекомендаций<select name="scope">'.
            '<option value="metadata">Заголовок и SEO-описание</option>'.
            '<option value="all">Заголовок, SEO-описание и основной текст</option>'.
            '</select></label><label>Дополнительные требования<textarea name="instruction" rows="3" maxlength="800"></textarea></label>'.
            '<p>Текст выбранной страницы отправляется в OpenAI. Запрос расходует API-токены. ' .
            'Публикация, адрес и навигация не изменяются.</p>'.
            '<button'.(!$site['ai']['enabled'] || !$configured?' disabled':'').'>Получить SEO-предложение</button></form>';
        if ($proposal !== null && ($proposal['path']??null) === $path) {
            $fields = $proposal['scope']==='all' ? ['title','description','body'] : ['title','description'];
            $html .= '<section id="seo-preview"><h3>SEO-предложение — предпросмотр</h3>'.
                '<p>Модель: '.$e($proposal['model']).'; вход: '.(int)$proposal['input_tokens'].
                ', выход: '.(int)$proposal['output_tokens'].' токенов. Срок действия — 30 минут.</p>';
            foreach ($fields as $field) {
                $label = match ($field) {'title'=>'Заголовок','description'=>'SEO-описание',default=>'Текст страницы'};
                $html .= '<h4>'.$label.'</h4><div class="ai-edit-columns"><div><strong>Было</strong><pre>'.
                    $e($proposal['original'][$field]).'</pre></div><div><strong>Предложено</strong><pre>'.
                    $e($proposal['suggested'][$field]).'</pre></div></div>';
            }
            $html .= '<form method="post" action="/admin/ai/seo">'.$hidden.
                '<input type="hidden" name="action" value="apply">'.
                '<input type="hidden" name="path" value="'.$e($path).'">'.
                '<input type="hidden" name="id" value="'.$e($proposal['id']).'">'.
                '<label>Заголовок<input name="title" maxlength="200" required value="'.
                $e($proposal['suggested']['title']).'"></label>'.
                '<label>SEO-описание<textarea name="description" maxlength="500" rows="4">'.
                $e($proposal['suggested']['description']).'</textarea></label>';
            if ($proposal['scope']==='all') {
                $html .= '<label>Основной текст<textarea name="body" maxlength="60000" rows="14">'.
                    $e($proposal['suggested']['body']).'</textarea></label>';
            }
            $html .= '<label><input type="checkbox" name="confirm" value="1" required>'.
                ' Подтверждаю сохранение выбранных изменений и резервирование предыдущей версии</label>'.
                '<button>Применить SEO-исправления</button></form>'.
                '<form method="post" action="/admin/ai/seo">'.$hidden.
                '<input type="hidden" name="action" value="discard">'.
                '<input type="hidden" name="path" value="'.$e($path).'">'.
                '<button class="ai-danger">Отменить предложение</button></form></section>';
        }
        $html .= '<p>Предыдущая версия сохраняется в истории AI-редактора. '.
            '<a href="/admin/ai/editor?path='.rawurlencode($path).'">Открыть историю и восстановить страницу</a></p>';
        return $html.'</section>';
    }
}
