<?php
declare(strict_types=1);
namespace CajeerEngine\Media;

use CajeerEngine\Lite;

final class MediaView
{
    private static function e(string $value): string { return Lite::esc($value); }

    public static function render(MediaLibrary $library,string $csrf,string $query='',string $category='all',string $message='',array $pages=[]): string
    {
        $rows=$library->listing($query,$category);$stats=$library->statistics();
        $html='<section class="cms-media"><div class="cms-media-header"><div><h2>Медиатека</h2><p>Файлы в приватном хранилище. Изображения доступны по безопасным ссылкам, документы — только для скачивания.</p></div>'.
            '<p class="cms-media-stats">'.count($rows).' найдено · '.$stats['count'].' / '.MediaLibrary::MAX_FILES.' файлов · '.number_format($stats['bytes']/1000000,2,',',' ').' / 100 МБ</p></div>';
        if ($message!=='') $html.='<p class="admin-feedback" role="status">'.self::e($message).'</p>';
        $html.='<form class="cms-media-upload" method="post" action="/admin/media" enctype="multipart/form-data">'.
            '<input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="upload">'.
            '<label for="cms-media-file">Загрузить изображение или PDF</label><input id="cms-media-file" type="file" name="media_file" required accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/jpeg,image/png,image/webp,image/gif,application/pdf">'.
            '<p>JPEG, PNG, WebP, GIF, PDF · до 8 МБ · изображения до 24 млн пикселей.</p><button type="submit">Загрузить файл</button></form>'.
            '<form class="cms-media-filters" method="get" action="/admin/media"><label>Поиск по имени <input type="search" name="q" maxlength="120" value="'.self::e($query).'" placeholder="Имя файла"></label>'.
            '<label>Тип <select name="type">';
        foreach (['all'=>'Все файлы','image'=>'Изображения','document'=>'Документы'] as $value=>$label) {
            $html.='<option value="'.$value.'"'.($category===$value?' selected':'').'>'.$label.'</option>';
        }
        $html.='</select></label><button type="submit">Найти</button><a href="/admin/media">Сбросить</a></form>';
        if ($rows===[]) return $html.'<p class="cms-media-empty">Файлы не найдены.</p></section>';
        $html.='<div class="cms-media-grid">';
        foreach ($rows as $row) {
            $id=$row['id'];$isImage=str_starts_with($row['mime'],'image/');
            $used=\CajeerEngine\Content\MediaReferences::pages($pages,$id);
            $link='/media/'.$id;
            $html.='<article class="cms-media-item"><div class="cms-media-thumb">'.
                ($isImage?'<a href="'.$link.'" target="_blank" rel="noopener"><img loading="lazy" src="'.$link.'" alt="'.self::e($row['name']).'"></a>':'<span aria-hidden="true">PDF</span>').'</div>'.
                '<div class="cms-media-details"><strong title="'.self::e($row['name']).'">'.self::e($row['name']).'</strong>'.
                '<small>'.self::e($row['mime']).' · '.number_format($row['size']/1024,1,',',' ').' КБ'.
                ($isImage?' · '.$row['width'].'×'.$row['height']:'').'</small>'.
                '<a href="'.$link.($isImage?'':'?download=1').'" target="_blank" rel="noopener">'.($isImage?'Открыть файл':'Скачать PDF').'</a>'.
                '<code class="cms-media-url">'.$link.'</code>'.
                ($used!==[] ? '<p class="cms-media-used">Используется на '.count($used).' страницах: '.
                    implode(', ',array_map(static fn(string $path):string =>
                        '<a href="/admin/editor?path='.rawurlencode($path).'">'.self::e($path).'</a>',array_slice($used,0,5))).'</p>' : '').
                '<form method="post" action="/admin/media" class="cms-media-rename">'.
                '<input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="rename">'.
                '<input type="hidden" name="id" value="'.$id.'"><label>Отображаемое имя <input type="text" name="name" required maxlength="150" value="'.self::e($row['name']).'"></label>'.
                '<button type="submit">Переименовать</button></form>'.
                '<form method="post" action="/admin/media" class="cms-media-delete">'.
                '<input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="delete">'.
                '<input type="hidden" name="id" value="'.$id.'"><button type="submit"'.
                ($used!==[]?' disabled title="Файл используется на страницах"':'').'>Удалить</button></form></div></article>';
        }
        return $html.'</div></section>';
    }
}
