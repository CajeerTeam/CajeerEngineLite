<?php
declare(strict_types=1);
namespace CajeerEngine\SEO;

use CajeerEngine\Lite;
use CajeerEngine\Admin\PagesManagement;

/** Real no-JavaScript, CSRF-protected SEO administration. */
final class SEOControlView
{
    private static function check(string $name,bool $value,string $text): string
    {
        return '<label><input type="checkbox" name="'.$name.'" value="1"'.($value?' checked':'').'> '.Lite::esc($text).'</label>';
    }

    public static function render(array $site,string $csrf,string $path,string $error='',string $notice=''): string
    {
        $e=static fn(string $value):string=>Lite::esc($value);
        $config=SEOControl::site($site);
        $selected=$site['pages'][$path];
        $page=SEOControl::page($selected);
        $html='<section class="seo-control"><p><a href="/admin">Панель управления</a> · ' .
            '<a href="/admin/ai/seo">AI SEO-аудит</a> · <a href="/admin/media">Медиатека</a></p>' .
            '<h2>SEO Control Center</h2><p>Реальные настройки публичной разметки, индексации, Open Graph и карты сайта. Изменения применяются после сохранения.</p>';
        if ($notice!=='') $html.='<p class="admin-feedback" role="status">'.$e($notice).'</p>';
        if ($error!=='') $html.='<p class="error" role="alert">'.$e($error).'</p>';
        $html.='<h3>Настройки сайта</h3><form method="post" action="/admin/seo">'.
            '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
            '<input type="hidden" name="action" value="global">'.
            '<input type="hidden" name="revision" value="'.$e(SEOControl::revision($site)).'">'.
            self::check('indexable',$config['indexable'],'Разрешить индексацию опубликованных страниц').
            self::check('title_suffix',$config['title_suffix'],'Добавлять название сайта к заголовку страницы').
            '<label>Изображение Open Graph по умолчанию (ID из медиатеки)<input name="default_image" maxlength="32" pattern="[a-f0-9]{32}" value="'.$e($config['default_image']).'"></label>'.
            '<p>При отключении индексации сайт отдаёт noindex и robots.txt с Disallow: /. SEO-изображения должны существовать в медиатеке.</p>'.
            '<button>Сохранить настройки сайта</button></form>'.
            '<h3>Настройки страницы</h3>'.
            '<form method="get" action="/admin/seo"><label>Страница<select name="path">';
        foreach ($site['pages'] as $item) {
            $html.='<option value="'.$e($item['path']).'"'.($item['path']===$path?' selected':'').'>'.
                $e($item['title'].' — '.$item['path']).'</option>';
        }
        $html.='</select></label><button type="submit">Открыть</button></form>'.
            '<p>Опубликована: '.($selected['published']?'да':'нет').'. <a href="/admin?view=pages&amp;edit='.rawurlencode($path).'">Редактировать заголовок и описание</a></p>'.
            '<form method="post" action="/admin/seo">'.
            '<input type="hidden" name="csrf" value="'.$e($csrf).'">'.
            '<input type="hidden" name="action" value="page">'.
            '<input type="hidden" name="path" value="'.$e($path).'">'.
            '<input type="hidden" name="revision" value="'.$e(PagesManagement::revision($selected)).'">'.
            self::check('index',$page['index'],'Разрешить индексацию этой страницы').
            self::check('follow',$page['follow'],'Разрешить поисковым роботам переходы по ссылкам').
            self::check('sitemap',$page['sitemap'],'Включить страницу в sitemap.xml (если индексируется)').
            '<label>Canonical (относительный адрес внутренней страницы; пусто — свой URL)<input name="canonical" maxlength="1024" value="'.$e($page['canonical']).'" placeholder="/page"></label>'.
            '<label>Open Graph заголовок (пусто — заголовок страницы)<input name="og_title" maxlength="200" value="'.$e($page['og_title']).'"></label>'.
            '<label>Open Graph описание (пусто — описание страницы)<textarea name="og_description" maxlength="500" rows="3">'.$e($page['og_description']).'</textarea></label>'.
            '<label>Open Graph изображение (ID из медиатеки; пусто — изображение сайта)<input name="og_image" maxlength="32" pattern="[a-f0-9]{32}" value="'.$e($page['og_image']).'"></label>'.
            '<label>Периодичность sitemap<select name="changefreq">';
        foreach (['always','hourly','daily','weekly','monthly','yearly','never'] as $freq) {
            $html.='<option value="'.$freq.'"'.($page['changefreq']===$freq?' selected':'').'>'.$freq.'</option>';
        }
        $html.='</select></label><label>Приоритет sitemap (0–10 соответствует 0.0–1.0)<input type="number" name="priority" min="0" max="10" step="1" value="'.$page['priority'].'"></label>'.
            '<p>Canonical разрешён только на опубликованную внутреннюю страницу. Неиндексируемые страницы и альтернативные canonical не включаются в sitemap.</p>'.
            '<button>Сохранить SEO страницы</button></form>'.
            '<h3>Текущая публичная разметка</h3>';
        $actual=SEOControl::presentation($site,$selected);
        foreach (['title'=>'Title','canonical'=>'Canonical','robots'=>'Robots','og_title'=>'OG title',
            'og_description'=>'OG description','og_image'=>'OG image','og_url'=>'OG URL'] as $field=>$label) {
            $html.='<p><strong>'.$label.':</strong> <code>'.$e($actual[$field]).'</code></p>';
        }
        $html.='<p>Системные файлы: <a href="/robots.txt">robots.txt</a> · <a href="/sitemap.xml">sitemap.xml</a></p></section>';
        return $html;
    }
}
