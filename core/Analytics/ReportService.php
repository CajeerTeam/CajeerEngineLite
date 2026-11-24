<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics;

use CajeerEngine\Integrations\MatomoConfig;

/** Real read-only reports. Each provider is independently actionable and never synthesized from tracking IDs. */
final class ReportService
{
    private GoogleAuth $google;
    public function __construct(private readonly HttpClient $http, private readonly string $root)
    {
        $this->google=new GoogleAuth($http,$root);
    }

    public static function days(int $days): int
    {
        if (!in_array($days,[3,7,14,30,90],true)) throw new \InvalidArgumentException('Allowed reporting windows: 3, 7, 14, 30, 90 days');
        return $days;
    }
    public static function configured(string $var): bool
    {
        $env=getenv($var);
        return is_string($env) && $env!=='';
    }
    private static function number(mixed $v): float
    {
        if ((!is_string($v) && !is_numeric($v)) || !is_numeric($v)) throw new \RuntimeException('Report API returned invalid number');
        $number=(float)$v;
        if (!is_finite($number) || $number<0 || $number>1.0e15) throw new \RuntimeException('Report API returned out-of-range number');
        return $number;
    }
    private static function string(mixed $v): string
    {
        if (!is_string($v) || strlen($v)>255 || !preg_match('//u',$v)) throw new \RuntimeException('Report API returned invalid label');
        return $v;
    }
    private static function rowMetrics(array $data, array $names): array
    {
        $result=[];
        foreach ($names as $key=>$index) {
            $v=$data['metricValues'][$index]['value']??null;
            if ($v===null) throw new \RuntimeException('Missing GA4 metric: '.$key);
            $result[$key]=self::number($v);
        }
        return $result;
    }
    public function collect(array $site, int $days): array
    {
        self::days($days);
        ReportConfig::validate($site['reporting']);
        $end=(new \DateTimeImmutable('yesterday',new \DateTimeZone('UTC')))->format('Y-m-d');
        $start=(new \DateTimeImmutable($end))->modify('-'.($days-1).' days')->format('Y-m-d');
        $config=$site['reporting'];
        $output=['from'=>$start,'to'=>$end,'days'=>$days,'generated_at'=>gmdate('c'),'providers'=>[]];
        foreach (['ga4','metrica','matomo','clarity','gsc','yandex_webmaster'] as $name) {
            try {
                $output['providers'][$name]=$this->fetch($name,$site,$config,$start,$end,$days);
            } catch (\Throwable $e) {
                // Provider failures cannot break another report. Error messages contain no tokens.
                $output['providers'][$name]=['status'=>'error','message'=>$e->getMessage()];
            }
        }
        $output['providers']['gtm']=['status'=>'not_applicable','message'=>'GTM управляет тегами, отчёты по событиям доступны через GA4'];
        return $output;
    }
    /** One real read-only provider probe, without refreshing unrelated providers or the report cache. */
    public function probe(array $site,string $provider): array
    {
        if (!in_array($provider,['ga4','metrica','matomo','clarity','gsc','yandex_webmaster'],true)) {
            throw new \InvalidArgumentException('Unknown reporting provider');
        }
        ReportConfig::validate($site['reporting']);
        $end=new \DateTimeImmutable('yesterday',new \DateTimeZone('UTC'));
        return $this->fetch($provider,$site,$site['reporting'],$end->modify('-6 days')->format('Y-m-d'),$end->format('Y-m-d'),7);
    }

    private function fetch(string $name, array $site, array $c, string $from, string $to, int $days): array
    {
        return match($name) {
            'ga4'=>$this->ga4($site,$c,$from,$to),
            'metrica'=>$this->metrica($site,$c,$from,$to),
            'matomo'=>$this->matomo($site,$from,$to),
            'clarity'=>$this->clarity($site,$days),
            'gsc'=>$this->gsc($c,$from,$to),
            'yandex_webmaster'=>$this->yandexWebmaster($c,$from,$to),
            default=>throw new \LogicException('Unknown provider'),
        };
    }
    private function absent(bool $enabled, bool $identity, bool $credential, string $credentialName): ?array
    {
        if (!$enabled) return ['status'=>'disabled'];
        if (!$identity) return ['status'=>'unconfigured','message'=>'Не задан идентификатор отчётного ресурса'];
        if (!$credential) return ['status'=>'unconfigured','message'=>'Требуется серверный секрет '.$credentialName];
        return null;
    }
    private function googleReady(): bool
    {
        return self::configured('CAJEER_REPORT_GOOGLE_TOKEN') || self::configured('CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
    }
    private function ga4(array $site,array $c,string $from,string $to): array
    {
        $reason=$this->absent(true,$c['ga4_property_id']!=='',
            $this->googleReady(),'CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
        if ($reason!==null) return $reason;
        $url='https://analyticsdata.googleapis.com/v1beta/properties/'.$c['ga4_property_id'].':runReport';
        $headers=['Authorization: Bearer '.$this->google->token()];
        $dates=[['startDate'=>$from,'endDate'=>$to]];
        $result=$this->http->json($url,'POST',$headers,[
            'dateRanges'=>$dates,'metrics'=>array_map(static fn($name)=>['name'=>$name],
                ['sessions','totalUsers','screenPageViews','keyEvents']),'limit'=>'1']);
        $rows=$result['rows']??[];
        $summary=isset($rows[0]) ? self::rowMetrics($rows[0],['sessions'=>0,'users'=>1,'pageviews'=>2,'conversions'=>3]) :
            ['sessions'=>0,'users'=>0,'pageviews'=>0,'conversions'=>0];
        $events=$this->http->json($url,'POST',$headers,['dateRanges'=>$dates,
            'dimensions'=>[['name'=>'eventName']], 'metrics'=>[['name'=>'eventCount'],['name'=>'keyEvents']],
            'orderBys'=>[['metric'=>['metricName'=>'eventCount'],'desc'=>true]],'limit'=>'20']);
        $goals=[];
        foreach ($events['rows']??[] as $row) {
            $name=self::string($row['dimensionValues'][0]['value']??'');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$name)) continue;
            $counts=self::rowMetrics($row,['events'=>0,'conversions'=>1]);
            $goals[]=['name'=>$name]+$counts;
        }
        return ['status'=>'ok','summary'=>$summary,'goals'=>$goals,'note'=>'Конверсии GA4 = keyEvents, не сумма событий'];
    }
    private function metrica(array $site,array $c,string $from,string $to): array
    {
        $reason=$this->absent(true,
            $site['integrations']['metrica']['counter_id']!=='',self::configured('CAJEER_REPORT_YANDEX_TOKEN'),
            'CAJEER_REPORT_YANDEX_TOKEN');
        if ($reason!==null) return $reason;
        $metrics=['ym:s:visits','ym:s:users','ym:s:pageviews'];
        if ($c['metrica_goal_id']!=='') {
            $metrics[]='ym:s:goal'.$c['metrica_goal_id'].'reaches';
            $metrics[]='ym:s:goal'.$c['metrica_goal_id'].'conversionRate';
        }
        $params=['ids'=>$site['integrations']['metrica']['counter_id'],'date1'=>$from,'date2'=>$to,
            'metrics'=>implode(',',$metrics),'accuracy'=>'full','limit'=>'1'];
        $json=$this->http->json('https://api-metrika.yandex.net/stat/v1/data?'.http_build_query($params),
            'GET',['Authorization: OAuth '.GoogleAuth::safeToken(getenv('CAJEER_REPORT_YANDEX_TOKEN'))]);
        if (!isset($json['totals']) || count($json['totals'])<count($metrics)) throw new \RuntimeException('Incomplete Metrica totals');
        $summary=['visits'=>self::number($json['totals'][0]),'users'=>self::number($json['totals'][1]),
            'pageviews'=>self::number($json['totals'][2])];
        if ($c['metrica_goal_id']!=='') {
            $summary['goal_reaches']=self::number($json['totals'][3]);
            $summary['goal_conversion_rate_percent']=self::number($json['totals'][4]);
        }
        return ['status'=>'ok','summary'=>$summary,'goals'=>$c['metrica_goal_id']!==''?[
            ['name'=>'Цель '.$c['metrica_goal_id'],'conversions'=>$summary['goal_reaches']]]:[],
            'note'=>!empty($json['sampled']) ? 'Внимание: отчёт Метрики содержит сэмплирование' : 'Без сэмплирования (по ответу API)'];
    }
    private function matomo(array $site,string $from,string $to): array
    {
        $config=$site['integrations']['matomo'];
        $reason=$this->absent(true,$config['site_id']!=='' && $config['server_url']!=='',
            self::configured('CAJEER_REPORT_MATOMO_TOKEN'),'CAJEER_REPORT_MATOMO_TOKEN');
        if ($reason!==null) return $reason;
        $url=$config['server_url'].'index.php';
        $host=parse_url($config['server_url'],PHP_URL_HOST);
        if (!is_string($host)) throw new \RuntimeException('Invalid Matomo URL');
        $base=['module'=>'API','format'=>'JSON','idSite'=>$config['site_id'],'period'=>'range',
            'date'=>$from.','.$to,'token_auth'=>GoogleAuth::safeToken(getenv('CAJEER_REPORT_MATOMO_TOKEN'))];
        $result=$this->http->form($url,['method'=>'VisitsSummary.get']+$base,strtolower($host));
        if (($result['result']??'')==='error') throw new \RuntimeException('Matomo API denied report');
        $summary=[];
        foreach (['nb_visits'=>'visits','nb_uniq_visitors'=>'users','nb_actions'=>'actions','nb_visits_converted'=>'converted_visits'] as $key=>$label) {
            if (isset($result[$key])) $summary[$label]=self::number($result[$key]);
        }
        if ($summary===[]) throw new \RuntimeException('Missing Matomo visit metrics');
        $goals=[];
        $goalResult=$this->http->form($url,['method'=>'Goals.get']+$base,strtolower($host));
        if (($goalResult['result']??'')==='error') throw new \RuntimeException('Matomo goals report unavailable');
        if (isset($goalResult['nb_conversions'])) $summary['conversions']=self::number($goalResult['nb_conversions']);
        if (isset($goalResult['conversion_rate']) && is_string($goalResult['conversion_rate']) &&
            preg_match('/^([0-9]+(?:\.[0-9]+)?)%$/D',$goalResult['conversion_rate'],$match)) {
            $summary['conversion_rate_percent']=self::number($match[1]);
        }
        elseif (isset($goalResult['nb_visits_converted'])) $summary['converted_visits']=self::number($goalResult['nb_visits_converted']);
        foreach ($goalResult as $key=>$value) {
            if (is_string($key) && preg_match('/^goal_([1-9][0-9]*)_nb_conversions$/D',$key,$match)) {
                $goals[]=['name'=>'Цель '.$match[1],'conversions'=>self::number($value)];
            }
        }
        return ['status'=>'ok','summary'=>$summary,'goals'=>array_slice($goals,0,20)];
    }
    private function clarity(array $site,int $days): array
    {
        $reason=$this->absent(true,
            $site['integrations']['clarity']['project_id']!=='',self::configured('CAJEER_REPORT_CLARITY_TOKEN'),
            'CAJEER_REPORT_CLARITY_TOKEN');
        if ($reason!==null) return $reason;
        $window=min(3,$days);
        ClarityQuota::charge($this->root,$site['integrations']['clarity']['project_id']);
        $json=$this->http->json('https://www.clarity.ms/export-data/api/v1/project-live-insights?numOfDays='.$window,
            'GET',['Authorization: Bearer '.GoogleAuth::safeToken(getenv('CAJEER_REPORT_CLARITY_TOKEN'))]);
        $summary=[];
        foreach ($json as $metric) {
            if (!is_array($metric)) continue;
            $name=$metric['metricName']??null;
            if (!is_string($name) || !in_array($name,['Traffic','Rage Click Count','Dead Click Count','Quickback Click','Script Error Count','Scroll Depth','Engagement Time'],true)) continue;
            $info=$metric['information']??[];
            // Only numeric aggregates; never expose sampled URL, page titles, or user identifiers.
            if ($name==='Traffic' && is_array($info)) {
                $total=0;
                foreach ($info as $row) if (isset($row['totalSessionCount'])) $total+=self::number($row['totalSessionCount']);
                $summary['sessions']=$total;
            }
        }
        return ['status'=>'ok','summary'=>$summary,'note'=>'Clarity: скользящее окно '.$window.' суток; API не поддерживает диапазон '.$days.' суток'];
    }
    private function gsc(array $c,string $from,string $to): array
    {
        $reason=$this->absent(true,$c['gsc_site_url']!=='',$this->googleReady(),'CAJEER_GOOGLE_SERVICE_ACCOUNT_FILE');
        if ($reason!==null) return $reason;
        $url='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($c['gsc_site_url']).'/searchAnalytics/query';
        $json=$this->http->json($url,'POST',['Authorization: Bearer '.$this->google->token()],
            ['startDate'=>$from,'endDate'=>$to,'dimensions'=>['date'],'rowLimit'=>100]);
        $clicks=0.; $impressions=0.; $weighted=0.;
        foreach ($json['rows']??[] as $row) {
            $c1=self::number($row['clicks']??0); $i=self::number($row['impressions']??0);
            $clicks+=$c1; $impressions+=$i; $weighted+=self::number($row['position']??0)*$i;
        }
        $summary=['clicks'=>$clicks,'impressions'=>$impressions,'ctr_percent'=>$impressions>0?round($clicks/$impressions*100,2):0,
            'avg_position'=>$impressions>0?round($weighted/$impressions,2):0];
        return ['status'=>'ok','summary'=>$summary,'note'=>'Search Console: показатели поиска, не веб-аналитика'];
    }
    private function yandexWebmaster(array $c,string $from,string $to): array
    {
        $ready=$c['yandex_webmaster_user_id']!=='' && $c['yandex_webmaster_host_id']!=='';
        $reason=$this->absent(true,$ready,self::configured('CAJEER_REPORT_YANDEX_TOKEN'),'CAJEER_REPORT_YANDEX_TOKEN');
        if ($reason!==null) return $reason;
        $url='https://api.webmaster.yandex.net/v4/user/'.$c['yandex_webmaster_user_id'].'/hosts/'.
            rawurlencode($c['yandex_webmaster_host_id']).'/search-queries/all/history?'.
            http_build_query(['date_from'=>$from.'T00:00:00Z','date_to'=>$to.'T23:59:59Z']);
        $url.='&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS';
        $json=$this->http->json($url,'GET',['Authorization: OAuth '.GoogleAuth::safeToken(getenv('CAJEER_REPORT_YANDEX_TOKEN'))]);
        $summary=[];
        foreach (['TOTAL_SHOWS'=>'impressions','TOTAL_CLICKS'=>'clicks'] as $metric=>$label) {
            $values=$json['indicators'][$metric]??null;
            if (!is_array($values)) throw new \RuntimeException('Missing Yandex Webmaster indicators');
            $total=0.;
            foreach ($values as $entry) $total+=self::number($entry['value']??null);
            $summary[$label]=$total;
        }
        $summary['ctr_percent']=$summary['impressions']>0?round($summary['clicks']/$summary['impressions']*100,2):0;
        return ['status'=>'ok','summary'=>$summary,'note'=>'Яндекс Вебмастер: клики и показы из поиска'];
    }
}
