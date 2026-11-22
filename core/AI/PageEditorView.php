<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;

/** Server-rendered editor: original versus proposed text, explicit approval and revision restore. */
final class PageEditorView
{
    public static function render(array $site,string $csrf,?array $proposal,array $history,bool $configured,
                                  string $path,string $error=''): string
    {
        $e=static fn(string $value):string=>Lite::esc($value);
        $hidden='<input type="hidden" name="csrf" value="'.$e($csrf).'">';
        $html='<p><a href="/admin">Админ-панель</a> · <a href="/admin/ai">AI-чат</a> · '.
            '<a href="/admin/ai/content">Генерация контента</a> · <a href="/admin/ai/seo">SEO-аудит</a></p>';
        if ($error!=='') $html.='<p role="alert" class="error">'.$e($error).'</p>';
        if (!$site['ai']['enabled'] || !$configured) {
            $html.='<p class="error">AI-редактор недоступен: включите OpenAI и настройте серверный API-ключ.</p>';
        }
        $html.='<section><h2>Редактура существующей страницы</h2><p>AI создаёт только предложение. '.
            'Текст страницы меняется после вашего подтверждения; перед сохранением создаётся версия для отката.</p>'.
            '<form method="get" action="/admin/ai/editor"><label>Страница<select name="path">';
        foreach ($site['pages'] as $page) {
            $html.='<option value="'.$e($page['path']).'"'.($page['path']===$path?' selected':'').'>' .
                $e($page['title'].' — '.$page['path']).'</option>';
        }
        $html.='</select></label><button>Выбрать страницу</button></form>';
        $page=$site['pages'][$path]??null;
        if (!is_array($page)) return $html.'</section>';
        $html.='<p>Текущая страница: <strong>'.$e($page['title']).'</strong> ('.$e($path).') '.
            ($page['published']?'· опубликована':'· черновик').'</p>'.
            '<form method="post" action="/admin/ai/editor">'.$hidden.
            '<input type="hidden" name="action" value="generate">'.
            '<input type="hidden" name="path" value="'.$e($path).'">'.
            '<label>Область правки<select name="scope">'.
            '<option value="body">Основной текст</option><option value="title">Заголовок</option>'.
            '<option value="description">Описание</option><option value="all">Заголовок, описание и текст</option>'.
            '</select></label><label>Тип редакции<select name="operation">'.
            '<option value="improve">Улучшить структуру и ясность</option>'.
            '<option value="proofread">Исправить ошибки</option>'.
            '<option value="shorten">Сократить</option><option value="expand">Расширить</option>'.
            '<option value="tone">Изменить стиль</option><option value="rewrite">Переписать</option>'.
            '<option value="custom">По моей инструкции</option></select></label>'.
            '<label>Дополнительные требования<textarea name="instruction" maxlength="1000" rows="4"></textarea></label>'.
            '<p>Будет отправлен текст страницы в OpenAI; операция использует API-токены. '.
            'Для длинных страниц настройте max_input_chars или сократите содержимое.</p>'.
            '<button'.(!$site['ai']['enabled'] || !$configured?' disabled':'').'>Подготовить AI-правку</button></form></section>';
        if ($proposal!==null && $proposal['path']===$path) {
            $html.='<section id="ai-editor-preview"><h2>Сравнение и предварительный просмотр</h2>'.
                '<p>Модель: '.$e($proposal['model']).' · вход: '.(int)$proposal['input_tokens']. 
                ' токенов · выход: '.(int)$proposal['output_tokens'].' токенов. '.
                'Предложение действует 30 минут. Путь и публикация не изменяются.</p>';
            $scope=$proposal['scope'];
            $fields=$scope==='all'?['title','description','body']:[$scope];
            $html.='<div class="ai-edit-comparison">';
            foreach ($fields as $field) {
                $label=match($field){'title'=>'Заголовок','description'=>'Описание',default=>'Текст'};
                $html.='<section><h3>'.$label.'</h3><div class="ai-edit-columns">'.
                    '<div><h4>Исходная версия</h4><pre>'.$e($proposal['original'][$field]).'</pre></div>'.
                    '<div><h4>AI-предложение</h4><pre>'.$e($proposal['suggested'][$field]).'</pre></div>'.
                    '</div></section>';
            }
            $html.='</div><form method="post" action="/admin/ai/editor">'.$hidden.
                '<input type="hidden" name="action" value="apply">'.
                '<input type="hidden" name="id" value="'.$e($proposal['id']).'">'.
                '<input type="hidden" name="path" value="'.$e($path).'">';
            foreach ($fields as $field) {
                $label=match($field){'title'=>'Заголовок','description'=>'Описание',default=>'Текст'};
                if ($field==='title') {
                    $html.='<label>'.$label.'<input name="title" maxlength="200" required value="'.
                        $e($proposal['suggested'][$field]).'"></label>';
                } else {
                    $html.='<label>'.$label.'<textarea name="'.$field.'" maxlength="'.
                        ($field==='body'?'60000':'500').'" rows="'.($field==='body'?'14':'4').'">'.
                        $e($proposal['suggested'][$field]).'</textarea></label>';
                }
            }
            $html.='<label><input type="checkbox" name="confirm" value="1" required> '.
                'Подтверждаю изменение'.($page['published']?' опубликованной':'').' страницы и создание версии для отката</label>'.
                '<button>Применить проверенные изменения</button></form>'.
                '<form method="post" action="/admin/ai/editor">'.$hidden.
                '<input type="hidden" name="path" value="'.$e($path).'">'.
                '<input type="hidden" name="action" value="discard"><button class="ai-danger">Отменить предложение</button>'.
                '</form></section>';
        }
        $html.='<section id="ai-editor-history"><h2>История изменений AI-редактора</h2>'.
            '<p>Сохранены предыдущие версии страницы, максимум 20. Восстановление заменяет '.
            'текущую версию только если страница не изменялась с момента открытия этой формы.</p>';
        if ($history===[]) $html.='<p>Предыдущих версий пока нет.</p>';
        foreach ($history as $snapshot) {
            $saved=$snapshot['page'];
            $html.='<details><summary>'. $e(gmdate('Y-m-d H:i:s',$snapshot['created']).' UTC · '. $saved['title']).'</summary>'.
                '<p>Путь: '.$e($saved['path']).' · '.($saved['published']?'опубликована':'черновик').'</p>'.
                '<pre>'.$e(substr($saved['body'],0,3000)).'</pre>'.
                '<form method="post" action="/admin/ai/editor">'.$hidden.
                '<input type="hidden" name="action" value="restore">'.
                '<input type="hidden" name="path" value="'.$e($path).'">'.
                '<input type="hidden" name="snapshot_id" value="'.$e($snapshot['id']).'">'.
                '<input type="hidden" name="revision" value="'.$e(PageEditor::revision($page)).'">'.
                '<label><input type="checkbox" name="confirm_restore" value="1" required> '.
                'Восстановить именно эту версию страницы</label>'.
                '<button>Восстановить версию</button></form></details>';
        }
        return $html.'</section>';
    }
}
