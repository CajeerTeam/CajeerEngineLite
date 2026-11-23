<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\SEO\SEOControl;

/** Reproducible on-site SEO checks. No AI guesswork is presented as measured data. */
final class SEOAudit
{
    private static function characters(string $value): int
    {
        return preg_match_all('/./us', $value) ?: 0;
    }

    public static function run(array $site): array
    {
        $pages = $site['pages'];
        $titles = []; $descriptions = [];
        foreach ($pages as $path => $page) {
            if (!$page['published']) continue;
            foreach (['title' => &$titles, 'description' => &$descriptions] as $field => &$index) {
                $text = preg_replace('/\s+/u', ' ', trim($page[$field]));
                if ($text !== '') $index[$text][] = $path;
            }
            unset($index);
        }
        unset($page);
        $pagesReport = []; $total = 0;
        foreach ($pages as $path => $page) {
            $issues = [];
            $add = static function(string $code, string $severity, string $message) use (&$issues): void {
                $issues[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
            };
            $titleLength = self::characters($page['title']);
            $descriptionLength = self::characters($page['description']);
            $bodyLength = self::characters($page['body']);
            if (!$page['published']) {
                $add('unpublished', 'info', 'Страница не опубликована и не входит в sitemap.');
            }
            $seo=SEOControl::page($page);
            if ($page['published'] && !SEOControl::isIndexable($site,$page)) {
                $add('noindex','info','Индексация страницы отключена в SEO Control Center.');
            }
            if ($seo['canonical']!=='' && $seo['canonical']!==$path) {
                if (!isset($pages[$seo['canonical']]) || !$pages[$seo['canonical']]['published']) {
                    $add('broken_canonical','error','Canonical ведёт на отсутствующую или неопубликованную страницу.');
                } else {
                    $add('alternate_canonical','info','Canonical указан на другой адрес; текущая страница исключена из sitemap.');
                }
            }
            if ($page['published'] && !$seo['sitemap']) {
                $add('sitemap_disabled','info','Страница исключена из sitemap через SEO Control Center.');
            }
            if ($titleLength < 25) $add('short_title', 'warning', 'Заголовок короче 25 символов.');
            if ($titleLength > 65) $add('long_title', 'warning', 'Заголовок длиннее 65 символов.');
            if ($descriptionLength === 0) $add('missing_description', 'error', 'Meta description отсутствует.');
            elseif ($descriptionLength < 70) $add('short_description', 'warning', 'Описание короче 70 символов.');
            elseif ($descriptionLength > 160) $add('long_description', 'warning', 'Описание длиннее 160 символов.');
            if ($bodyLength < 300) $add('thin_body', 'warning', 'Основной текст короче 300 символов; проверьте полноту материала.');
            if ($page['published']) {
                foreach (['title' => $titles, 'description' => $descriptions] as $field => $index) {
                    $text = preg_replace('/\s+/u', ' ', trim($page[$field]));
                    if ($text !== '' && count($index[$text] ?? []) > 1) {
                        $add('duplicate_'.$field, 'error', 'Метаданные «'.$field.'» повторяются у опубликованных страниц: '.implode(', ', array_slice($index[$text], 0, 5)));
                    }
                }
            }
            $penalty = 0;
            foreach ($issues as $issue) $penalty += match ($issue['severity']) {'error' => 22, 'warning' => 9, default => 0};
            $pagesReport[$path] = [
                'path' => $path, 'published' => $page['published'],
                'canonical' => $site['settings']['url'].SEOControl::canonicalPath($page),
                'title_chars' => $titleLength, 'description_chars' => $descriptionLength,
                'body_chars' => $bodyLength, 'issues' => $issues,
                'score' => max(0, 100 - $penalty),
            ];
            $total += count(array_filter($issues, static fn(array $issue): bool => $issue['severity'] !== 'info'));
        }
        $global = [];
        if (!ModuleConfig::enabled($site, 'sitemap')) $global[] = 'Sitemap выключен в конфигурации модулей.';
        if (!ModuleConfig::enabled($site, 'robots')) $global[] = 'robots.txt выключен в конфигурации модулей.';
        if (!SEOControl::site($site)['indexable']) $global[] = 'Индексация сайта отключена в SEO Control Center.';
        if (!str_starts_with($site['settings']['url'], 'https://')) $global[] = 'Публичный URL сайта использует HTTP вместо HTTPS.';
        return ['pages' => $pagesReport, 'issue_count' => $total,
            'global' => $global, 'published_count' => count(array_filter($pages, static fn(array $p): bool => $p['published']))];
    }
}
