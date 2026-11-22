<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;

/** Server-rendered admin-only report page; no third-party JS or credentials in HTML. */
final class DashboardView
{
    private static function e(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

    public static function render(array $site, ?array $report, string $csrf, int $days): string
    {
        $html='<section><h2>Analytics Dashboard 1.1.1</h2>'.
          '<p>Отчёты извлекаются сервером из API провайдеров; они не вычисляются из браузерных счётчиков. ' .
          'Секреты задаются в окружении, не записываются в site.json и не отображаются в браузере.</p>';
        $html.='<form method="get" action="/admin"><input type="hidden" name="analytics" value="1"><label>Период ' .
            '<select name="days">';
        foreach ([3,7,14,30,90] as $choice) {
            $html.='<option value="'.$choice.'"'.($choice===$days?' selected':'').'>'.$choice.' дней</option>';
        }
        $html.='</select></label><button>Показать сохранённый отчёт</button></form>';
        $html.='<form method="post" action="/admin"><input type="hidden" name="action" value="analytics_refresh">'.
            '<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
            '<input type="hidden" name="days" value="'.$days.'"><button>Получить данные из API</button></form>';
        if ($report===null) $html.='<p>Нет сохранённого отчёта. Нажмите «Получить данные из API». Запросы выполняются только по явному действию администратора.</p>';
        else {
            $html.='<p>Отчёт за '.self::e($report['from']??''). ' — '.self::e($report['to']??'').
                '. Получен '.self::e($report['generated_at']??'').
                (!empty($report['stale'])?' (кэш старше 15 минут).':' (закрытый кэш).').'</p>';
            $titles=['ga4'=>'Google Analytics 4','metrica'=>'Яндекс Метрика','matomo'=>'Matomo',
                'clarity'=>'Microsoft Clarity','gsc'=>'Google Search Console',
                'yandex_webmaster'=>'Яндекс Вебмастер','gtm'=>'Google Tag Manager'];
            $captions=['sessions'=>'Сессии','visits'=>'Визиты','users'=>'Пользователи','pageviews'=>'Просмотры',
                'conversions'=>'Конверсии','key_events'=>'Ключевые события','goal_reaches'=>'Достижения цели',
                'actions'=>'Действия','converted_visits'=>'Визиты с конверсией',
                'conversion_rate_percent'=>'Конверсия, %','goal_conversion_rate_percent'=>'Конверсия цели, %','clicks'=>'Клики из поиска',
                'impressions'=>'Показы в поиске','ctr_percent'=>'CTR, %','avg_position'=>'Средняя позиция'];
            foreach ($titles as $provider=>$title) {
                $data=$report['providers'][$provider]??['status'=>'error','message'=>'Отчёт отсутствует'];
                $status=$data['status']??'error';
                $html.='<article><h3>'.self::e($title).'</h3>';
                if ($status!=='ok') {
                    $html.='<p>'.self::e(match($status) {
                        'disabled'=>'Трекер выключен', 'unconfigured'=>'Не настроен доступ к API',
                        'not_applicable'=>'У GTM нет собственного отчёта о конверсиях',
                        default=>'Ошибка получения отчёта',
                    }).'. '.self::e($data['message']??'').'</p></article>';
                    continue;
                }
                $html.='<table><tbody>';
                foreach ($data['summary']??[] as $key=>$value) {
                    $html.='<tr><th>'.self::e($captions[$key]??$key).'</th><td>'.
                        self::e(is_numeric($value)?number_format((float)$value,2,'.',' '):(string)$value).'</td></tr>';
                }
                $html.='</tbody></table>';
                if (!empty($data['goals'])) {
                    $html.='<h4>Цели и конверсии</h4><table><thead><tr><th>Цель / событие</th><th>События</th><th>Конверсии</th></tr></thead><tbody>';
                    foreach (array_slice($data['goals'],0,20) as $goal) {
                        $html.='<tr><td>'.self::e((string)$goal['name']).'</td><td>'.
                            self::e((string)($goal['events']??'—')).'</td><td>'.self::e((string)($goal['conversions']??'—')).'</td></tr>';
                    }
                    $html.='</tbody></table>';
                }
                if (!empty($data['note'])) $html.='<p>'.self::e($data['note']).'</p>';
                $html.='</article>';
            }
        }
        return $html.'</section>';
    }

    public static function settings(array $site, string $csrf): string
    {
        $cfg=$site['reporting'];
        $items=['ga4_property_id'=>'GA4 Property ID (число; отличается от G-Measurement ID)',
            'metrica_goal_id'=>'Яндекс Метрика: числовой ID цели для отчёта',
            'gsc_site_url'=>'Google Search Console property (https://example.org/ или sc-domain:example.org)',
            'yandex_webmaster_user_id'=>'Яндекс Вебмастер: user-id',
            'yandex_webmaster_host_id'=>'Яндекс Вебмастер: host-id'];
        $html='<section><h2>Настройки отчётов и вебмастеров</h2><form method="post" action="/admin">'.
            '<input type="hidden" name="csrf" value="'.self::e($csrf).'">'.
            '<input type="hidden" name="action" value="analytics_settings">';
        foreach ($items as $field=>$caption) {
            $html.='<label>'.self::e($caption).'<input type="text" name="'.self::e($field).
                '" maxlength="255" value="'.self::e($cfg[$field]).'"></label>';
        }
        $html.='<button>Сохранить настройки отчётов</button></form>';
        $html.='<p>Серверный доступ к API:</p><ul>';
        $checks=[
            'Google (GA4 / Search Console)' => ReportService::configured('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE') || ReportService::configured('CAJEER_REPORT_GOOGLE_TOKEN'),
            'Яндекс (Метрика / Вебмастер)' => ReportService::configured('CAJEER_REPORT_YANDEX_TOKEN'),
            'Matomo' => ReportService::configured('CAJEER_REPORT_MATOMO_TOKEN'),
            'Clarity' => ReportService::configured('CAJEER_REPORT_CLARITY_TOKEN'),
        ];
        foreach ($checks as $provider=>$connected) {
            $html.='<li>'.self::e($provider).': '.($connected?'секрет задан в окружении (права API не проверены)':'секрет отсутствует').'</li>';
        }
        $html.='</ul><p>Токены API задаются на сервере переменными '.
            '<code>CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE</code>, <code>CAJEER_REPORT_YANDEX_TOKEN</code>, '.
            '<code>CAJEER_REPORT_MATOMO_TOKEN</code>, <code>CAJEER_REPORT_CLARITY_TOKEN</code>. '.
            'Для тестирования возможен краткоживущий <code>CAJEER_REPORT_GOOGLE_TOKEN</code>. '.
            'Нужен доступ только на чтение; аккаунтам Google разрешите GA4 и Search Console.</p>'.
            '<p><a href="/admin?analytics=1">Открыть Analytics Dashboard</a></p></section>';
        return $html;
    }
}
