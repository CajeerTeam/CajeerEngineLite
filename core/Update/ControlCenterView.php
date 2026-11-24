<?php
declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Lite;

final class ControlCenterView
{
    private static function h(string $s): string {return Lite::esc($s);}
    private static function form(string $csrf,string $rev,string $action,string $body): string
    {
        return '<form method="post" action="/admin/updates"><input type="hidden" name="csrf" value="'.self::h($csrf).'">'.
            '<input type="hidden" name="revision" value="'.self::h($rev).'"><input type="hidden" name="action" value="'.self::h($action).'">'.$body.'</form>';
    }
    public static function render(array $s,string $csrf,string $error='',string $notice=''): string
    {
        $p=$s['settings'];$c=$s['check'];$v=$c['verified']??null;$j=$s['job'];$rev=$s['revision'];
        $html='<div class="cms-operations"><p>Обновления из GitHub Releases. Панель создаёт задания; отдельный CLI-worker проверяет и устанавливает подписанные пакеты. PHP-FPM не выполняет команды установки.</p>';
        if($error!=='')$html.='<p class="error" role="alert">'.self::h($error).'</p>';
        if($notice!=='')$html.='<p class="admin-feedback" role="status">'.self::h($notice).'</p>';
        $labels=['recovery_required'=>'Требуется восстановление обновления.','scheduler_stale'=>'Планировщик не отвечает.','scheduler_failure'=>'Последний запуск планировщика завершился с ошибкой.','check_failure'=>'Последняя проверка завершилась неудачно.','install_failure'=>'Последняя установка завершилась неудачно.','job_expired'=>'Задание просрочено.','update_available'=>'Доступно проверенное обновление.','check_overdue'=>'Периодическая проверка просрочена.','supervisor_hold'=>'Автообновление остановлено системным оркестратором. Требуется оператор.','supervisor_maintenance'=>'Системное обслуживание ещё не завершено.'];
        foreach($s['monitoring']['alerts']??[] as $alert)$html.='<p class="'.($alert['severity']==='error'?'error':'cms-ops-note').'" role="'.($alert['severity']==='error'?'alert':'status').'">'.self::h($labels[$alert['id']]).'</p>';
        $html.='<div class="cms-ops-stats">';
        foreach(['Установленная версия'=>$s['installed'],'Канал'=>$p['channel'],'Проверенный переход'=>$s['fresh']&&$v!==null?$v['to']:'—','Последняя доступная'=>$s['fresh']?($c['latest']??'—'):'—'] as $k=>$value)
            $html.='<div><small>'.self::h($k).'</small><strong>'.self::h($value).'</strong></div>';
        $html.='</div><section><h2>Проверка и установка</h2>';
        $heartbeat=$s['scheduler']??null;
        $html.='<p>Планировщик: '.($heartbeat===null?'нет запусков':self::h(gmdate('Y-m-d H:i:s',$heartbeat['finished_at']??$heartbeat['started_at'])).' UTC — '.self::h($heartbeat['action'])).'.</p>';
        if($heartbeat!==null&&(time()-($heartbeat['finished_at']??$heartbeat['started_at'])>900||($heartbeat['finished_at']??$heartbeat['started_at'])>time()))$html.='<p class="error">Нет свежего сигнала планировщика.</p>';
        if($s['pending'])$html.='<p class="error">Незавершённое обновление. Оператор должен выполнить update recover.</p>';
        if($j!==null){
            $html.='<p role="status">Задание: '.self::h($j['type']==='check'?'проверка':'установка').' — '.self::h($j['status']==='queued'?'в очереди':'выполняется').'. ID: <code>'.self::h($j['id']).'</code></p>';
            if(($j['not_before']??0)>time())$html.='<p>Следующая попытка: '.self::h(gmdate('Y-m-d H:i:s',$j['not_before'])).' UTC.</p>';
            if($j['status']==='queued')$html.=self::form($csrf,$rev,'cancel','<button type="submit">Отменить задание</button>');
            $html.='<p><a href="/admin/updates">Обновить состояние</a></p>';
        } elseif(!$s['pending']) $html.=self::form($csrf,$rev,'check','<button type="submit">Проверить обновления</button>');
        if($c!==null){
            $html.='<p>Последняя проверка: '.self::h(gmdate('Y-m-d H:i:s',$c['at'])).' UTC. '.($s['fresh']?'Актуальна (до 24 часов).':'Устарела: выполните проверку повторно.').'</p>';
            if($s['fresh']&&$v===null)$html.='<p>Совместимых обновлений не обнаружено.</p>';
            if($s['fresh']&&$v!==null){
                $html.='<p>Подпись пакета и совместимость исходной версии подтверждены. Изменяемых файлов: '.(int)$v['files'].'. SHA-256: <code class="update-digest">'.self::h($v['sha256']).'</code>.</p>';
                if($c['plan'])$html.='<p>Маршрут: '.self::h(implode(' → ',$c['plan'])).'</p>';
                if($v['preview'])$html.='<p class="cms-ops-note">Предварительный релиз: требуется отдельное разрешение оператора.</p>';
                if($c['notes']!=='')$html.='<details><summary>Описание релиза GitHub (неподписанный текст)</summary><pre class="update-notes">'.self::h($c['notes']).'</pre></details>';
                if($j===null&&!$s['pending'])$html.=self::form($csrf,$rev,'install','<label>Введите версию '.self::h($v['to']).' для подтверждения <input name="confirm" required autocomplete="off"></label><button type="submit">Поставить установку в очередь</button>');
            }
        } else $html.='<p>Нет актуального результата проверки пакета.</p>';
        $html.='<p class="cms-ops-note">Установка ожидает системного разрешения, остановки рабочих процессов и обоих окон обслуживания (CMS и окружение). Следующий шаг цепочки требует новой проверки.</p></section>';
        $html.='<section><h2>Настройки</h2>';
        if($j!==null)$html.='<p>Настройки доступны после завершения или отмены задания.</p>';
        else $html.=self::form($csrf,$rev,'settings','<label>Канал <select name="channel"><option value="stable"'.($p['channel']==='stable'?' selected':'').'>Stable</option><option value="preview"'.($p['channel']==='preview'?' selected':'').'>Preview</option></select></label>'.
            '<label><input type="checkbox" name="checks" value="1"'.($p['checks']?' checked':'').'> Периодическая проверка при запуске worker</label>'.
            '<label><input type="checkbox" name="install" value="1"'.($p['install']?' checked':'').'> Автоматическая установка проверенных пакетов (opt-in)</label>'.
            '<label>Интервал проверки, часов <input type="number" name="interval" min="1" max="168" required value="'.$p['interval'].'"></label>'.
            '<label>Окно обслуживания UTC <input name="window" required value="'.self::h($p['window']).'" placeholder="02:00-04:00"></label><button type="submit">Сохранить настройки</button>');
        $html.='<p class="cms-ops-note">Источник, доверенный ключ и разрешения задаются оператором в окружении worker; панель не может изменить их. Планировщик systemd/cron настраивается оператором. При подключённом системном оркестраторе сервисы останавливаются и запускаются автоматически; иначе оператор выполняет обслуживание вручную.</p></section>';
        $html.='<section><h2>История заданий</h2><div class="dev-table-wrap"><table><thead><tr><th>UTC</th><th>Действие</th><th>Версия</th><th>Результат</th></tr></thead><tbody>';
        $labels=['checked'=>'Проверено','installed'=>'Установлено','failed'=>'Ошибка (см. CLI)','cancelled'=>'Отменено','interrupted'=>'Прервано','expired'=>'Истекло'];
        foreach(array_reverse($s['history']) as $row)$html.='<tr><td>'.self::h(gmdate('Y-m-d H:i:s',$row['at'])).'</td><td>'.($row['type']==='check'?'Проверка':'Установка').'</td><td>'.self::h($row['to']??'—').'</td><td>'.self::h($labels[$row['result']]).'</td></tr>';
        if(!$s['history'])$html.='<tr><td colspan="4">История пуста.</td></tr>';
        return $html.'</tbody></table></div><p>Хранятся последние 40 завершённых заданий. История прямых CLI-установок не входит в этот журнал.</p></section></div>';
    }
}
