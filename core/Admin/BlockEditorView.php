<?php
declare(strict_types=1);
namespace CajeerEngine\Admin;

use CajeerEngine\Content\Blocks;
use CajeerEngine\Lite;

/** Real block editing form. All persistence and preview operations are server-validated. */
final class BlockEditorView
{
    public static function render(array $page,string $csrf,bool $existing,string $error=''): string
    {
        $e=static fn(string $s):string=>Lite::esc($s);
        $path=$existing ? $page['path'] : '';
        $revision=$existing ? PagesManagement::revision($page) : '';
        $blocks=$page['blocks']??Blocks::fromLegacy($page['body']);
        $json=json_encode($blocks,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $html='<section class="cms-editor-shell" id="cms-block-editor">'.
            '<div class="cms-editor-header"><div><h2>Блочный редактор</h2>'.
            '<p>Безопасные текстовые блоки. HTML и JavaScript не выполняются. Предпросмотр откроется справа.</p></div>'.
            '<a href="/admin?view=pages">Все страницы</a></div>'.
            ($error!==''?'<p role="alert" class="error">'.$e($error).'</p>':'').
            '<form method="post" action="/admin/editor" id="cms-editor-form" data-block-editor autocomplete="off">'.
            '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
            '<input type="hidden" name="action" value="save_blocks">'.
            '<input type="hidden" name="old" value="'.$e($path).'">'.
            '<input type="hidden" name="revision" value="'.$e($revision).'">'.
            '<div class="cms-editor-fields">'.
            '<label>URL страницы<input required maxlength="1024" name="path" value="'.$e($page['path']).'" placeholder="/about"></label>'.
            '<label>Заголовок<input required maxlength="200" name="title" value="'.$e($page['title']).'"></label>'.
            '<label>Описание<input maxlength="500" name="description" value="'.$e($page['description']).'"></label>'.
            '</div><div class="cms-editor-flags">'.
            '<label><input type="checkbox" name="published" value="1"'.($page['published']?' checked':'').'> Опубликовано</label>'.
            '<label><input type="checkbox" name="menu" value="1"'.($page['menu']?' checked':'').'> Показывать в меню</label>'.
            '</div><div class="cms-editor-workspace"><div class="cms-editor-compose">'.
            '<div class="cms-editor-toolbar"><label>Добавить блок <select id="cms-add-type">'.
            '<option value="paragraph">Абзац</option><option value="heading">Заголовок</option>'.
            '<option value="list">Список</option><option value="quote">Цитата</option>'.
            '<option value="link">Ссылка</option><option value="button">Кнопка</option>'.
            '<option value="divider">Разделитель</option></select></label>'.
            '<button type="button" id="cms-add-block" disabled>Добавить</button>'.
            '<button type="button" id="cms-undo" disabled>Отменить</button>'.
            '<button type="button" id="cms-redo" disabled>Повторить</button></div>'.
            '<div id="cms-blocks" aria-live="polite"></div>'.
            '<noscript><p role="alert">Для визуального редактора необходим JavaScript. ' .
            'Используйте <a href="/admin?view=pages&amp;edit='.$e(rawurlencode($path)).'">обычный текстовый редактор</a>.</p></noscript>'.
            '</div><div class="cms-editor-preview"><h3>Предпросмотр содержимого</h3>'.
            '<div id="cms-live-preview" aria-live="polite"></div>'.
            '<p>Предпросмотр ниже использует настоящий шаблон сайта и серверную проверку блоков. ' .
            'Он не запускает аналитику или скрипты.</p>'.
            '<button type="submit" id="cms-preview-button" formaction="/admin/editor/preview" ' .
            'formtarget="cms-server-preview" disabled>Проверить на сайте</button>'.
            '<iframe name="cms-server-preview" title="Безопасный предпросмотр страницы" ' .
            'class="cms-preview-frame" sandbox="" referrerpolicy="no-referrer"></iframe></div></div>'.
            '<textarea name="blocks_json" id="cms-block-json" hidden>'.$e($json).'</textarea>'.
            '<div class="cms-editor-footer"><span id="cms-draft-status" role="status">Редактор загружается…</span>'.
            '<button type="submit" id="cms-save-button" disabled>Сохранить страницу</button></div>'.
            '</form></section>';
        return $html;
    }
}
