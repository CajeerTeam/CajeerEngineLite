<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;

final class SupportView
{
    private static function e(string $value): string {return Lite::esc($value);}

    public static function render(string $category,array $snapshot,?array $insight,
        string $csrf,bool $aiReady,string $error=''): string
    {
        SupportDiagnostics::category($category);SupportDiagnostics::validate($snapshot);
        $html='<p><a href="/admin">Админ-панель</a> · <a href="/admin/ai">AI Chat</a> · <a href="/admin/ai/analytics">AI Analytics</a></p>'.
            '<section id="ai-support"><h2>AI Technical Support — диагностика и поддержка CMS</h2>'.
            '<p>Реальная диагностика выполняется локально. Секреты, логи, IP, содержимое страниц и идентификаторы пользователей не отправляются модели. ' .
            'AI не запускает команды и не меняет конфигурацию.</p>';
        if ($error!=='') $html.='<p class="error" role="alert">'.self::e($error).'</p>';
        $html.='<form method="get" action="/admin/ai/support"><label>Область диагностики <select name="category">';
        foreach (SupportDiagnostics::CATEGORIES as $id=>$label) {
            $html.='<option value="'.$id.'"'.($category===$id?' selected':'').'>'.self::e($label).'</option>';
        }
        $html.='</select></label><button type="submit">Проверить состояние</button></form>';
        $html.='<p><a href="/admin/ai/support?category='.$category.'&amp;format=json">Скачать обезличенный отчёт JSON</a></p>';
        $counts=$snapshot['counts'];
        $html.='<p>Версия Lite: '.self::e($snapshot['version']).'; JSON schema: '.(int)$snapshot['schema'].'. ' .
            'Проверки: PASS '.(int)$counts['pass'].', WARN '.(int)$counts['warn'].', FAIL '.(int)$counts['fail'].'.</p>';
        $html.='<h3>Результаты реальных проверок</h3><table><thead><tr><th>Проверка</th><th>Статус</th></tr></thead><tbody>';
        foreach ($snapshot['checks'] as $id=>$status) {
            $html.='<tr><th>'.self::e($id).'</th><td>'.strtoupper($status).'</td></tr>';
        }
        $html.='</tbody></table><h3>Что проверить без AI</h3><ol>';
        foreach (SupportDiagnostics::firstAid($category,$snapshot) as $step) $html.='<li>'.self::e($step).'</li>';
        $html.='</ol>';
        $hidden='<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
            '<input type="hidden" name="category" value="'.$category.'">';
        $html.='<form method="post" action="/admin/ai/support">'.$hidden.
            '<input type="hidden" name="action" value="generate"><button'.(!$aiReady?' disabled':'').'>'.
            ($insight===null?'Получить AI-рекомендации':'Повторить AI-анализ').'</button></form>';
        if (!$aiReady) $html.='<p class="notice">OpenAI отключён или API-ключ не настроен. Локальная диагностика доступна без него.</p>';
        if ($insight!==null) {
            $html.='<section id="ai-support-result"><h3>AI-рекомендации (требуют проверки)</h3>'.
                '<p>Сгенерировано '.gmdate('Y-m-d H:i:s',$insight['created']).' UTC; модель: '.self::e($insight['model']).
                '; токены: '.(int)$insight['input_tokens'].' + '.(int)$insight['output_tokens'].'.</p>';
            foreach (['summary'=>'Сводка','causes'=>'Возможные причины','steps'=>'Безопасные шаги',
                        'cautions'=>'Ограничения и предостережения'] as $key=>$label) {
                $html.='<h4>'.$label.'</h4><p>'.nl2br(self::e($insight['fields'][$key])).'</p>';
            }
            $html.='<form method="post" action="/admin/ai/support">'.$hidden.
                '<input type="hidden" name="action" value="delete"><button>Удалить AI-рекомендации</button></form></section>';
        }
        return $html.'</section>';
    }
}
