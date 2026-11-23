<?php
declare(strict_types=1);
namespace CajeerEngine\Admin;

use CajeerEngine\Lite;
use CajeerEngine\Public\PublicPath;

/** Server-side page operations. All writes run inside Lite::change()'s locked transaction. */
final class PagesManagement
{
    private const STATUSES = ['all'=>'Все статусы', 'published'=>'Опубликованные', 'draft'=>'Черновики'];
    private const ORDERS = ['title'=>'По названию', 'path'=>'По адресу', 'published'=>'Сначала опубликованные', 'draft'=>'Сначала черновики'];
    private const PAGE_SIZES = [10,20,50,100];

    public static function revision(array $page): string
    {
        return hash('sha256', json_encode($page, JSON_THROW_ON_ERROR));
    }

    private static function value(array $query, string $key, string $default, int $max = 120): string
    {
        $value = $query[$key] ?? $default;
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid pages parameter: '.$key);
        }
        return trim($value);
    }

    /** Query parameters are strictly bounded; no untrusted sort expressions or database syntax. */
    public static function listing(array $pages, array $query): array
    {
        $search = self::value($query, 'q', '');
        $status = self::value($query, 'status', 'all', 20);
        $order = self::value($query, 'sort', 'title', 20);
        $direction = self::value($query, 'dir', 'asc', 4);
        $pageRaw = self::value($query, 'page', '1', 5);
        $sizeRaw = self::value($query, 'per_page', '20', 3);
        if (!isset(self::STATUSES[$status]) || !isset(self::ORDERS[$order]) || !in_array($direction, ['asc','desc'], true) ||
            !preg_match('/^[1-9][0-9]{0,4}$/D', $pageRaw) || !ctype_digit($sizeRaw) || !in_array((int)$sizeRaw, self::PAGE_SIZES, true)) {
            throw new \InvalidArgumentException('Invalid pages filter');
        }
        $rows = array_values(array_filter($pages, static function(array $p) use ($search, $status): bool {
            if ($status === 'published' && !$p['published']) return false;
            if ($status === 'draft' && $p['published']) return false;
            if ($search === '') return true;
            foreach (['title','path','description'] as $field) {
                $value = $p[$field];
                if (function_exists('mb_stripos')) {
                    if (mb_stripos($value, $search, 0, 'UTF-8') !== false) return true;
                } elseif (str_contains(self::fold($value), self::fold($search))) return true;
            }
            return false;
        }));
        usort($rows, static function(array $a, array $b) use ($order, $direction): int {
            $comparison = match ($order) {
                'title' => strnatcasecmp(self::fold($a['title']), self::fold($b['title'])),
                'path' => strnatcasecmp($a['path'], $b['path']),
                'published' => (int)$b['published'] <=> (int)$a['published'],
                'draft' => (int)$a['published'] <=> (int)$b['published'],
            };
            if ($comparison !== 0) return $direction === 'desc' ? -$comparison : $comparison;
            return strcmp($a['path'], $b['path']); // deterministic ordering for pagination
        });
        $total = count($rows);
        $perPage = (int)$sizeRaw;
        $maxPage = max(1, (int)ceil($total / $perPage));
        $page = min((int)$pageRaw, $maxPage);
        return [
            'rows'=>array_slice($rows, ($page-1)*$perPage, $perPage),
            'total'=>$total, 'page'=>$page, 'pages'=>$maxPage, 'per_page'=>$perPage,
            'q'=>$search, 'status'=>$status, 'sort'=>$order, 'dir'=>$direction,
        ];
    }

    /** Unicode-aware enough for Russian UI without relying on optional mbstring. */
    private static function fold(string $text): string
    {
        $upper=preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY);
        $lower=preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY);
        return strtolower(strtr($text,array_combine($upper,$lower)));
    }

    public static function url(array $list, int $page): string
    {
        return '/admin?'.http_build_query([
            'view'=>'pages','q'=>$list['q'],'status'=>$list['status'],'sort'=>$list['sort'],
            'dir'=>$list['dir'],'per_page'=>$list['per_page'],'page'=>$page,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function select(string $name, array $labels, string $active): string
    {
        $html = '<select name="'.Lite::esc($name).'">';
        foreach ($labels as $key=>$title) {
            $html .= '<option value="'.Lite::esc((string)$key).'"'.($active===(string)$key?' selected':'').'>'.Lite::esc($title).'</option>';
        }
        return $html.'</select>';
    }

    public static function render(array $pages, array $query, string $csrf): string
    {
        $l = self::listing($pages, $query);
        $e = static fn(string $s): string => Lite::esc($s);
        $sizes = array_combine(array_map('strval', self::PAGE_SIZES), array_map(static fn(int $n): string => $n.' на странице', self::PAGE_SIZES));
        $countPublished = count(array_filter($pages, static fn(array $p): bool => $p['published']));
        $html = '<section class="admin-pages"><div class="admin-pages-heading"><div><h2>Управление страницами</h2>'.
            '<p>Всего: '.count($pages).' · Опубликовано: '.$countPublished.' · Черновиков: '.(count($pages)-$countPublished).'</p></div>'.
            '<a class="admin-page-create" href="/admin?view=pages&amp;new=1">Создать страницу</a></div>'.
            '<form method="get" action="/admin" class="admin-pages-filters" role="search">'.
            '<input type="hidden" name="view" value="pages">'.
            '<label>Поиск по названию, адресу и описанию<input type="search" name="q" maxlength="120" value="'.$e($l['q']).'" placeholder="Название или адрес страницы"></label>'.
            '<label>Статус'.self::select('status',self::STATUSES,$l['status']).'</label>'.
            '<label>Сортировка'.self::select('sort',self::ORDERS,$l['sort']).'</label>'.
            '<label>Порядок'.self::select('dir',['asc'=>'По возрастанию','desc'=>'По убыванию'],$l['dir']).'</label>'.
            '<label>Показать'.self::select('per_page',$sizes,(string)$l['per_page']).'</label>'.
            '<button type="submit">Применить</button><a href="/admin?view=pages">Сбросить</a></form>'.
            '<div class="admin-pages-results" role="status">Найдено страниц: '.$l['total'].'</div>'.
            '<form method="post" action="/admin" id="admin-pages-bulk" class="admin-pages-bulk">'.
            '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
            '<input type="hidden" name="action" value="pages_bulk">'.
            '<span>Выбранные страницы:</span><button type="submit" name="operation" value="publish">Опубликовать</button>'.
            '<button type="submit" name="operation" value="draft">Снять с публикации</button></form>'.
            '<div class="admin-pages-table-wrap"><table class="admin-pages-table"><thead><tr>'.
            '<th scope="col">Выбор</th><th scope="col">Страница</th><th scope="col">Статус</th>'.
            '<th scope="col">Действия</th></tr></thead><tbody>';
        foreach ($l['rows'] as $p) {
            $path=$p['path']; $revision=self::revision($p); $key=hash('sha256',$path);
            $html.='<tr><td><input type="checkbox" form="admin-pages-bulk" name="selected[]" value="'.$e($path).'" aria-label="Выбрать '.$e($p['title']).'">'.
                '<input type="hidden" form="admin-pages-bulk" name="revisions['.$key.']" value="'.$e($revision).'"></td>'.
                '<td><strong>'.$e($p['title']).'</strong><small>'.$e($path).'</small></td>'.
                '<td><span class="admin-pages-badge '.($p['published']?'is-published':'is-draft').'">'.($p['published']?'Опубликована':'Черновик').'</span></td>'.
                '<td class="admin-pages-actions"><a href="/admin?view=pages&amp;edit='.rawurlencode($path).'">Изменить</a>'.
                '<a href="/admin/ai/editor?path='.rawurlencode($path).'">AI-редактор</a>'.
                '<a href="/admin/ai/seo?path='.rawurlencode($path).'">SEO</a>'.
                '<form method="post" action="/admin" class="admin-pages-inline">'.
                '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
                '<input type="hidden" name="action" value="page_status">'.
                '<input type="hidden" name="old" value="'.$e($path).'">'.
                '<input type="hidden" name="revision" value="'.$e($revision).'">'.
                '<input type="hidden" name="published" value="'.($p['published']?'0':'1').'">'.
                '<button type="submit"'.($path==='/'?' disabled title="Главная страница должна быть опубликована"':'').'>'.
                ($p['published']?'В черновики':'Опубликовать').'</button></form>'.
                '<form method="post" action="/admin" class="admin-pages-inline">'.
                '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
                '<input type="hidden" name="action" value="page_duplicate">'.
                '<input type="hidden" name="old" value="'.$e($path).'">'.
                '<input type="hidden" name="revision" value="'.$e($revision).'">'.
                '<button type="submit">Дублировать</button></form></td></tr>';
        }
        if ($l['rows']===[]) $html.='<tr><td colspan="4">По заданным условиям страницы не найдены.</td></tr>';
        $html.='</tbody></table></div><nav class="admin-pages-pagination" aria-label="Страницы результатов"><span>Страница '.$l['page'].' из '.$l['pages'].'</span>';
        if ($l['page']>1) $html.='<a href="'.$e(self::url($l,$l['page']-1)).'">Предыдущая</a>';
        if ($l['page']<$l['pages']) $html.='<a href="'.$e(self::url($l,$l['page']+1)).'">Следующая</a>';
        return $html.'</nav></section>';
    }

    private static function checkedPage(array $pages, string $path, string $revision): array
    {
        if (!isset($pages[$path]) || !hash_equals(self::revision($pages[$path]), $revision)) {
            throw new \InvalidArgumentException('Page changed; reload');
        }
        return $pages[$path];
    }

    public static function status(array $state, string $path, string $revision, bool $publish): array
    {
        self::checkedPage($state['pages'], $path, $revision);
        if ($path==='/' && !$publish) throw new \InvalidArgumentException('Home must remain published at /');
        $state['pages'][$path]['published']=$publish;
        return $state;
    }

    public static function bulk(array $state, mixed $selected, mixed $revisions, string $operation): array
    {
        if (!in_array($operation, ['publish','draft'], true) || !is_array($selected) || !array_is_list($selected) ||
            count($selected)<1 || count($selected)>100 || !is_array($revisions) || count($revisions)>100) {
            throw new \InvalidArgumentException('Invalid selection');
        }
        $unique=[];
        foreach ($selected as $path) {
            if (!is_string($path) || strlen($path)>1024 || !preg_match('//u',$path) || isset($unique[$path])) {
                throw new \InvalidArgumentException('Invalid selection');
            }
            $unique[$path]=true;
            $key=hash('sha256',$path);
            $rev=$revisions[$key]??null;
            if (!is_string($rev) || !preg_match('/^[a-f0-9]{64}$/D',$rev)) {
                throw new \InvalidArgumentException('Missing page revision');
            }
            self::checkedPage($state['pages'],$path,$rev);
            if ($path==='/' && $operation==='draft') {
                throw new \InvalidArgumentException('Home must remain published at /');
            }
        }
        foreach ($unique as $path=>$_) $state['pages'][$path]['published']=($operation==='publish');
        return $state;
    }

    public static function duplicate(array $state, string $path, string $revision): array
    {
        $source=self::checkedPage($state['pages'],$path,$revision);
        if (count($state['pages'])>=100) throw new \InvalidArgumentException('Page limit: 100');
        $stem = $path==='/' ? '/home' : rtrim($path,'/');
        $copyPath='';
        for ($i=1;$i<=100;$i++) {
            $candidate=$stem.'-copy'.($i>1?'-'.$i:'');
            if (strlen($candidate)>1024) throw new \InvalidArgumentException('Page path too long to duplicate');
            $candidate=PublicPath::content($candidate);
            if (!isset($state['pages'][$candidate])) { $copyPath=$candidate; break; }
        }
        if ($copyPath==='') throw new \InvalidArgumentException('Cannot create unique page path');
        $title=$source['title'];
        while (strlen($title.' (копия)')>200) {
            $title=preg_replace('/.$/u','',$title);
            if (!is_string($title) || $title==='') throw new \InvalidArgumentException('Invalid source title');
        }
        $copy=$source;
        $copy['path']=$copyPath;
        $copy['title']=$title.' (копия)';
        $copy['published']=false;
        $copy['menu']=false;
        $state['pages'][$copyPath]=$copy;
        return $state;
    }
}
