<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

final class ChatView
{
    private static function e(string $str):string {return htmlspecialchars($str,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(array $all,?array $chat,string $csrf,array $config,bool $key):string
    {
        $html='<p><a href="/admin">← Вернуться к настройкам</a></p><section class="ai-chat"><h2>Cajeer AI Assistant</h2>';
        $html.='<p>Диалог с OpenAI Responses API. История хранится на сервере отдельно от сайта. ' .
            'Сообщения передаются выбранной модели OpenAI. AI не выполняет изменения сайта.</p>';
        if (!$config['enabled'] || !$key) $html.='<p role="alert" class="error">Включите OpenAI и настройте серверный API-ключ в административных настройках.</p>';
        $html.='<div class="ai-chat-layout"><aside class="ai-chat-sidebar"><h3>Диалоги</h3>';
        $html.='<form action="/admin/ai" method="post"><input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
            '<input type="hidden" name="action" value="create"><button type="submit">Новый диалог</button></form><ul>';
        foreach ($all as $entry) {
            $active=($chat['id']??null)===$entry['id'];
            $html.='<li><a '.($active?'aria-current="page" ':'').'href="/admin/ai?id='.self::e($entry['id']).'">'.
                self::e($entry['title']).'</a></li>';
        }
        $html.='</ul>';
        if ($all) $html.='<form action="/admin/ai" method="post" data-confirm="Удалить все диалоги?">'.
            '<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
            '<input type="hidden" name="action" value="clear"><button type="submit" class="ai-danger">Очистить всю историю</button></form>';
        $html.='</aside><div class="ai-chat-main">';
        if ($chat!==null) {
            $html.='<h3>'.self::e($chat['title']).'</h3><div id="ai-chat-messages" class="ai-chat-messages" role="log" aria-live="polite">';
            foreach ($chat['messages'] as $m) {
                $html.='<article class="ai-message ai-message-'.self::e($m['role']).'"><strong>'.
                    ($m['role']==='user'?'Вы':'Ассистент').'</strong><p>'.nl2br(self::e($m['content'])).'</p></article>';
            }
            $html.='</div><p id="ai-chat-status" role="status">'.($chat['pending']===null?'Готов к запросу.':'Предыдущий ответ ещё обрабатывается.').'</p>';
            $html.='<form method="post" action="/admin/ai" id="ai-chat-send">'.
                '<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
                '<input type="hidden" name="action" value="send">'.
                '<input type="hidden" name="id" value="'.self::e($chat['id']).'">'.
                '<label for="ai-chat-message">Ваше сообщение</label><textarea id="ai-chat-message" name="message" rows="4" maxlength="'.
                (int)$config['max_input_chars'].'" required '.(!$config['enabled']||!$key?'disabled':'').'></textarea>'.
                '<div class="ai-chat-actions"><button type="submit" id="ai-chat-submit" '.(!$config['enabled']||!$key||$chat['pending']!==null?'disabled':'').'>Отправить</button>'.
                '<button type="button" id="ai-chat-stop" '.($chat['pending']===null?'hidden':'').'>Остановить</button></div></form>';
            $html.='<form method="post" action="/admin/ai" data-confirm="Удалить этот диалог?">'.
                '<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
                '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.self::e($chat['id']).'">'.
                '<button type="submit" class="ai-danger">Удалить диалог</button></form>';
        } else {
            $html.='<p>Создайте новый диалог, чтобы отправлять сообщения.</p>';
        }
        $html.='</div></div><p>Модель: '.self::e($config['model']).'. Ограничение контекста: '.
            (int)$config['max_input_chars'].' байт. Текст ответов отображается без выполнения HTML.</p></section>';
        return $html;
    }
}
