<?php
declare(strict_types=1);
namespace CajeerEngine\Admin;

use CajeerEngine\Lite;

/** First-party, server-rendered administration. No JS or external assets required. */
final class AdminExperience
{
    private const NAV = [
        'dashboard' => ['Обзор', 'layout-dashboard'],
        'pages' => ['Страницы', 'file-text'],
        'ai' => ['AI Assistant', 'sparkles'],
        'analytics' => ['Аналитика', 'chart'],
        'integrations' => ['Интеграции', 'plug'],
        'security' => ['Безопасность', 'shield'],
        'settings' => ['Настройки', 'settings'],
    ];

    public static function view(array $query): string
    {
        if (array_key_exists('view', $query)) {
            $view = $query['view'];
            if (!is_string($view) || !array_key_exists($view, self::NAV)) {
                throw new \InvalidArgumentException('Invalid administration view');
            }
            return $view;
        }
        if (array_key_exists('edit', $query) || array_key_exists('new', $query)) return 'pages';
        if (array_key_exists('ai', $query) || array_key_exists('ai_release', $query)) return 'ai';
        if (array_key_exists('analytics', $query)) return 'analytics';
        if (array_key_exists('auth_acceptance', $query)) return 'security';
        return 'dashboard';
    }

    private static function esc(string $value): string { return Lite::esc($value); }

    private static function link(string $url, string $text, bool $current = false): string
    {
        return '<a href="'.self::esc($url).'"'.($current?' aria-current="page"':'').'>'.self::esc($text).'</a>';
    }

    public static function navigation(string $path, string $view): string
    {
        $nav = '<nav aria-label="Разделы управления" class="admin-nav">';
        foreach (self::NAV as $id => [$label]) {
            $active = $path === '/admin' && $view === $id;
            $nav .= self::link('/admin?view='.$id, $label, $active);
        }
        $nav .= self::link('/admin/media', 'Медиатека', $path === '/admin/media');
        $nav .= self::link('/admin/editor?new=1', 'Блочный редактор', $path === '/admin/editor');
        $nav .= '<div class="admin-nav-divider" aria-hidden="true"></div><p class="admin-nav-label">AI-инструменты</p>';
        foreach ([
            '/admin/ai' => 'Чат',
            '/admin/ai/content' => 'Генерация',
            '/admin/ai/editor' => 'AI-редактор',
            '/admin/ai/seo' => 'SEO Assistant',
            '/admin/ai/analytics' => 'AI-аналитика',
            '/admin/ai/support' => 'Техподдержка',
        ] as $url => $label) $nav .= self::link($url, $label, $path === $url);
        return $nav.'</nav>';
    }

    /** Shared sidebar is also used by the existing independently routed AI pages. */
    public static function frame(string $title, string $content, string $path, string $view, string $csrf): string
    {
        $e = self::esc($title);
        $menu = self::navigation($path, $view);
        $logout = '<form method="post" action="/admin" class="admin-logout"><input type="hidden" name="csrf" value="'.self::esc($csrf).'">'.
            '<input type="hidden" name="action" value="logout"><button type="submit">Выйти</button></form>';
        return '<a class="admin-skip" href="#admin-content">Перейти к содержимому</a>'.
            '<div class="admin-frame"><aside class="admin-sidebar" aria-label="Навигация CMS">'.
            '<a class="admin-brand" href="/admin">CajeerEngine <span>Lite</span></a>'.
            '<p class="admin-product-version">CMS '.self::esc(Lite::VERSION).'</p>'.$menu.'</aside>'.
            '<div class="admin-workspace"><header class="admin-topbar"><div class="admin-topbar-start">'.
            '<details class="admin-mobile-menu"><summary>Меню разделов</summary>'.$menu.'</details>'.
            '<span class="admin-topbar-label">Панель управления</span></div><div class="admin-topbar-actions">'.
            self::link('/', 'Открыть сайт').$logout.'</div></header>'.
            '<main id="admin-content" class="admin-main" tabindex="-1"><div class="admin-heading">'.
            '<p class="admin-eyebrow">CajeerEngine Lite / Панель управления</p><h1>'.$e.'</h1></div>'.
            $content.'</main></div></div>';
    }

    /** Build the overview from state and actual runtime status, not fabricated metrics. */
    public static function dashboard(array $state, int $deviceCount, bool $integrationOk, bool $aiKeyReady): string
    {
        $pages = count($state['pages']);
        $published = count(array_filter($state['pages'], static fn(array $page):bool => $page['published']));
        $drafts = $pages - $published;
        $active = count(array_filter($state['integrations'], static fn(array $item):bool => $item['enabled']));
        $oidcLinked = $state['identity']['owner'] !== null;
        $aiEnabled = $state['ai']['enabled'] && $aiKeyReady;
        $stats = [
            ['Всего страниц', (string)$pages, 'Опубликовано: '.$published],
            ['Черновики', (string)$drafts, 'Лимит: 100 страниц'],
            ['Интеграции', (string)$active.' / 5', $integrationOk ? 'Локальная проверка пройдена' : 'Требуется диагностика'],
            ['Активные устройства', (string)$deviceCount, 'Серверные сеансы'],
        ];
        $html = '<div class="admin-intro"><div><h2>Dashboard — обзор сайта</h2><p>Состояние CMS и быстрый доступ к основным действиям.</p></div>'.
            self::link('/admin?view=pages&new=1', 'Создать страницу').'</div><div class="admin-metrics">';
        foreach ($stats as [$label,$value,$description]) {
            $html .= '<article class="admin-metric"><p>'.self::esc($label).'</p><strong>'.self::esc($value).'</strong>'.
                '<small>'.self::esc($description).'</small></article>';
        }
        $html .= '</div><div class="admin-dashboard-grid"><section class="admin-dashboard-card"><h2>Быстрые действия</h2><div class="admin-quick-links">';
        foreach ([
            '/admin?view=pages'=>'Управление страницами',
            '/admin/editor?new=1'=>'Новая страница (блоки)',
            '/admin/media'=>'Медиатека: файлы',
            '/admin/ai/editor'=>'AI-редактор',
            '/admin/ai/seo'=>'SEO-аудит',
            '/admin?view=analytics&analytics=1'=>'Отчёты аналитики',
            '/admin/ai/support'=>'Диагностика CMS',
        ] as $path=>$title) $html .= self::link($path,$title);
        $html .= '</div></section><section class="admin-dashboard-card"><h2>Состояние системы</h2><dl class="admin-status-list">';
        foreach ([
            'CMS'=>Lite::VERSION,
            'SkiF4er ID'=>$oidcLinked ? 'Владелец привязан' : 'Не привязан',
            'Режим входа'=>$state['auth_policy']['mode']==='oidc_only' ? 'Только SkiF4er ID' : 'Локальный + SkiF4er ID',
            'OpenAI'=>$aiEnabled ? 'Включён и ключ настроен' : 'Не готов к запросам',
            'Cookie Consent'=>$state['consent']['enabled'] ? 'Включён' : 'Выключен',
            'Интеграции'=>$integrationOk ? 'Проверка успешна' : 'Есть проблемы',
        ] as $name=>$status) $html .= '<div><dt>'.self::esc($name).'</dt><dd>'.self::esc($status).'</dd></div>';
        $html .= '</dl><p class="admin-help">Статусы отражают локальную конфигурацию, а не фактическую доставку данных сторонним сервисам.</p></section></div>';
        $html .= '<section class="admin-dashboard-card"><div class="admin-card-heading"><h2>Страницы сайта</h2>'.
            self::link('/admin?view=pages','Все страницы').'</div><ul class="admin-page-overview">';
        $shown=0;
        foreach ($state['pages'] as $page) {
            if (++$shown>6) break;
            $html .= '<li><div><strong>'.self::esc($page['title']).'</strong><small>'.self::esc($page['path']).'</small></div>'.
                '<span class="admin-page-status">'.($page['published']?'Опубликовано':'Черновик').'</span>'.
                self::link('/admin?view=pages&edit='.rawurlencode($page['path']), 'Изменить').'</li>';
        }
        return $html.'</ul></section>';
    }

    public static function sections(array $panels, string $view, string $dashboard, string $notice = ''): string
    {
        $html = $notice;
        $all = ['dashboard'=>$dashboard] + $panels;
        foreach (self::NAV as $id => [$title]) {
            $body=$all[$id]??'';
            // Hidden inactive views preserve old deep links and server-rendered form
            // compatibility. Only the selected view is visible to the administrator.
            $html .= '<div id="admin-view-'.self::esc($id).'" class="admin-view"'.
                ($view === $id ? '' : ' hidden').' aria-label="'.self::esc($title).'">'.$body.'</div>';
        }
        return $html;
    }
}
