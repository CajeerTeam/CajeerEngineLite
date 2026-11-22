<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;

/** Accessible, script-free admin forms: generation never writes until explicit approval. */
final class ContentView
{
    public static function render(array $site, string $csrf, ?array $proposal, bool $configured, string $error = ''): string
    {
        $e = static fn(string $value): string => Lite::esc($value);
        $csrfInput = '<input type="hidden" name="csrf" value="'.$e($csrf).'">';
        $html = '<p><a href="/admin">Админ-панель</a> · <a href="/admin/ai">AI-чат</a></p>';
        if ($error !== '') $html .= '<p role="alert" class="error">'.$e($error).'</p>';
        if (!$site['ai']['enabled'] || !$configured) {
            $html .= '<p class="error">OpenAI отключён или API-ключ отсутствует. Включите интеграцию в настройках администратора.</p>';
        }
        $html .= '<section><h2>Новая генерация</h2><p>Результат использует платные API-токены. Ничего не сохраняется в сайт без подтверждения.</p>';
        $html .= '<form method="post" action="/admin/ai/content">'.$csrfInput.'<input type="hidden" name="action" value="generate">'.
            '<label>Что создать<select name="mode" required><option value="page">Новую страницу (черновик)</option>'.
            '<option value="title">Заголовок существующей страницы</option>'.
            '<option value="description">SEO-описание существующей страницы</option></select></label>'.
            '<label>Путь новой страницы (режим «Новая страница»)<input name="path" value="/new-page" maxlength="1024" required></label>'.
            '<label>Существующая страница (для заголовка или описания)<select name="target">';
        foreach ($site['pages'] as $page) {
            $html .= '<option value="'.$e($page['path']).'">'.$e($page['title'].' — '.$page['path']).'</option>';
        }
        $html .= '</select></label><label>Тема и требования<textarea name="topic" maxlength="1100" required></textarea></label>'.
            '<label>Для кого материал<input name="audience" maxlength="200"></label>'.
            '<label>Ключевые слова (необязательно)<input name="keywords" maxlength="250"></label>'.
            '<label>Стиль<select name="style"><option value="neutral">Нейтральный</option><option value="professional">Деловой</option>'.
            '<option value="friendly">Дружелюбный</option></select></label>'.
            '<label>Объём<select name="length"><option value="short">Краткий</option><option value="normal" selected>Обычный</option>'.
            '<option value="detailed">Подробный</option></select></label>'.
            '<button'.(!$site['ai']['enabled'] || !$configured ? ' disabled' : '').'>Сгенерировать предложение</button>'.
            '</form></section>';
        if ($proposal !== null) {
            $mode = $proposal['mode'];
            $html .= '<section id="ai-content-preview"><h2>Предварительный просмотр</h2>'.
                '<p>Модель: '.$e($proposal['model']).' · входящие токены: '.(int)$proposal['input_tokens'].
                ' · исходящие токены: '.(int)$proposal['output_tokens'].'. Предложение действует 30 минут.</p>'.
                '<form method="post" action="/admin/ai/content">'.$csrfInput.
                '<input type="hidden" name="action" value="apply">'.
                '<input type="hidden" name="id" value="'.$e($proposal['id']).'">';
            if ($mode === 'page') {
                $html .= '<p>Будет создана <strong>неопубликованная</strong> страница, не включённая в меню.</p>'.
                    '<label>Путь<input name="path" maxlength="1024" required value="'.$e($proposal['path']).'"></label>';
            } else {
                $html .= '<p>Будет изменено только одно поле страницы <strong>'.$e($proposal['target']).'</strong>. '.
                    'Остальное содержимое и статус публикации сохранятся.</p>';
            }
            if ($mode === 'page' || $mode === 'title') {
                $html .= '<label>Заголовок<input name="title" maxlength="200" required value="'.$e($proposal['title']).'"></label>';
            }
            if ($mode === 'page' || $mode === 'description') {
                $html .= '<label>Описание<textarea name="description" maxlength="500">'.$e($proposal['description']).'</textarea></label>';
            }
            if ($mode === 'page') {
                $html .= '<label>Содержимое<textarea name="body" maxlength="60000" required rows="15">'.$e($proposal['body']).'</textarea></label>';
            }
            $html .= '<button>Подтвердить и сохранить'.($mode === 'page' ? ' черновик' : ' изменение').'</button></form>'.
                '<form method="post" action="/admin/ai/content">'.$csrfInput.
                '<input type="hidden" name="action" value="discard"><button class="ai-danger">Отклонить предложение</button></form></section>';
        }
        return $html;
    }
}
