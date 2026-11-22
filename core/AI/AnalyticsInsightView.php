<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Lite;

/** No JS and no direct provider calls: source numbers are separate from AI interpretation. */
final class AnalyticsInsightView
{
    private const NAMES = ['ga4'=>'Google Analytics 4','metrica'=>'Яндекс Метрика',
        'matomo'=>'Matomo','clarity'=>'Microsoft Clarity','gsc'=>'Google Search Console',
        'yandex_webmaster'=>'Яндекс Вебмастер'];
    private const METRICS = ['sessions'=>'Сессии','visits'=>'Визиты','users'=>'Пользователи',
        'pageviews'=>'Просмотры','conversions'=>'Конверсии','goal_reaches'=>'Достижения цели',
        'goal_conversion_rate_percent'=>'Конверсия цели, %','conversion_rate_percent'=>'Конверсия, %',
        'converted_visits'=>'Визиты с конверсией','actions'=>'Действия','clicks'=>'Клики',
        'impressions'=>'Показы','ctr_percent'=>'CTR, %','avg_position'=>'Средняя позиция'];
    private static function e(string $s):string {return Lite::esc($s);}
    private static function amount(int|float $v):string {return number_format((float)$v,(floor((float)$v)===(float)$v?0:2),',',' ');}
    public static function render(?array $report,?array $insights,string $csrf,int $days,bool $configured,string $error=''):string
    {
        $html='<p><a href="/admin">Админ-панель</a> · <a href="/admin?analytics=1">Analytics Dashboard</a> · <a href="/admin/ai">AI-чат</a> · <a href="/admin/ai/seo">AI SEO</a></p>'.
            '<section id="ai-insights"><h2>AI Analytics Insights — анализ отчётов и конверсий</h2>'.
            '<p>Анализируется только уже полученный сервером отчёт. Внешние API аналитики не вызываются при генерации AI-выводов. ' .
            'Сырые данные посетителей и секреты не передаются OpenAI.</p>';
        if ($error!=='') $html.='<p class="error" role="alert">'.self::e($error).'</p>';
        $html.='<form method="get" action="/admin/ai/analytics"><label>Период <select name="days">';
        foreach ([3,7,14,30,90] as $window) $html.='<option value="'.$window.'"'.($days===$window?' selected':'').'>'.$window.' дней</option>';
        $html.='</select></label><button>Показать сохранённый отчёт</button></form>';
        if ($report===null) return $html.'<p class="notice">Отчёт за выбранный период отсутствует. ' .
            '<a href="/admin?analytics=1&days='.$days.'">Получите данные из API в Analytics Dashboard</a>.</p></section>';
        $facts=AnalyticsInsights::facts($report);
        $html.='<p>Период UTC: <strong>'.self::e($facts['from']).' — '.self::e($facts['to']).'</strong>. ' .
            'Последнее получение: '.self::e((string)($report['generated_at']??'неизвестно')).'.</p>';
        if ($facts['stale']) $html.='<p class="notice">Кэш старше 15 минут. Выводы могут быть устаревшими: обновите отчёт в Analytics Dashboard.</p>';
        $html.='<h3>Подтверждённые показатели источников</h3>'.
            '<p>Метрики и конверсии разных провайдеров нельзя суммировать или напрямую сравнивать. ' .
            'Без предыдущего сопоставимого периода тенденции не определяются.</p>';
        $ok=0;
        foreach ($facts['providers'] as $name=>$data) {
            $html.='<article><h4>'.self::e(self::NAMES[$name]).'</h4>';
            if ($data['status']!=='ok') {
                $status=match($data['status']) {'disabled'=>'отключён','unconfigured'=>'не настроен',default=>'данных нет или ошибка API'};
                $html.='<p>Нет достоверных показателей: '.$status.'.</p></article>';continue;
            }
            $ok++;
            $html.='<table><tbody>';
            foreach ($data['metrics'] as $key=>$v) $html.='<tr><th>'.self::e(self::METRICS[$key]??$key).'</th><td>'.self::amount($v).'</td></tr>';
            $html.='</tbody></table>';
            if ($data['goals']!==[]) {
                $html.='<p>Цели и события (идентификаторы обезличены):</p><table><tbody>';
                foreach ($data['goals'] as $goal) {
                    $html.='<tr><th>'.self::e($goal['id']).'</th><td>';
                    foreach (['events','conversions'] as $key) if (isset($goal[$key])) $html.=($key==='events'?'События: ':'Конверсии: ').self::amount($goal[$key]).' ';
                    $html.='</td></tr>';
                }
                $html.='</tbody></table>';
            }
            if ($name==='clarity') $html.='<p class="notice">Clarity может охватывать только последние 3 суток, независимо от выбранного периода.</p>';
            $html.='</article>';
        }
        if ($ok===0) return $html.'<p class="notice">Нет успешных отчётов для AI-анализа. Получите доступ к API провайдеров.</p></section>';
        $csrfField='<input type="hidden" name="csrf" value="'.self::e($csrf).'">';
        $html.='<form method="post" action="/admin/ai/analytics">'.$csrfField.
            '<input type="hidden" name="days" value="'.$days.'"><input type="hidden" name="action" value="generate">'.
            '<button'.(!$configured?' disabled':'').'>'.($insights===null?'Создать AI-анализ':'Обновить AI-анализ').'</button></form>';
        if (!$configured) $html.='<p class="notice">OpenAI API не настроен или отключён. Таблицы исходных показателей доступны без AI.</p>';
        if ($insights!==null) {
            $html.='<section id="ai-insights-result"><h3>AI-интерпретация — не подтверждённые факты</h3>'.
                '<p>Сгенерировано '.gmdate('Y-m-d H:i:s',$insights['created']).' UTC. Модель: '.self::e((string)($insights['model']??'')).'. '.
                'Токены: '.(int)$insights['input_tokens'].' + '.(int)$insights['output_tokens'].'.</p>';
            foreach (['summary'=>'Объяснение','hypotheses'=>'Гипотезы','recommendations'=>'Действия для проверки',
                        'limitations'=>'Ограничения анализа'] as $key=>$title) {
                $html.='<h4>'.$title.'</h4><p>'.nl2br(self::e($insights['fields'][$key])).'</p>';
            }
            $html.='<form method="post" action="/admin/ai/analytics">'.$csrfField.
                '<input type="hidden" name="days" value="'.$days.'"><input type="hidden" name="action" value="clear">'.
                '<button>Удалить сохранённый AI-анализ</button></form></section>';
        }
        return $html.'</section>';
    }
}
