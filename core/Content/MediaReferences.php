<?php
declare(strict_types=1);
namespace CajeerEngine\Content;

use CajeerEngine\Media\MediaLibrary;

/** Media relationships are derived from validated page documents; there is no stale side index. */
final class MediaReferences
{
    /** @return list<string> */
    public static function ids(array $blocks): array
    {
        $ids=[];
        foreach ($blocks as $block) {
            if (is_array($block) && in_array($block['type']??null,['image','file'],true) &&
                is_string($block['media_id']??null) && preg_match('/^[a-f0-9]{32}$/D',$block['media_id'])) {
                $ids[$block['media_id']]=true;
            }
        }
        return array_keys($ids);
    }

    /** @return list<string> */
    public static function pages(array $pages,string $mediaId): array
    {
        $found=[];
        foreach ($pages as $path=>$page) {
            if (is_array($page) && (
                (is_array($page['blocks']??null) && in_array($mediaId,self::ids($page['blocks']),true)) ||
                ($page['seo']['og_image']??'')===$mediaId)) {
                $found[]=$path;
            }
        }
        return $found;
    }

    /** Called with MediaLibrary::guard() held through a page transaction or preview. */
    public static function verify(MediaLibrary $media,array $blocks): void
    {
        $checked=[];
        foreach ($blocks as $block) {
            if (!in_array($block['type'],['image','file'],true)) continue;
            $id=$block['media_id'];
            $expected=$block['type'];
            if (isset($checked[$id]) && $checked[$id]!==$expected) {
                throw new \InvalidArgumentException('A media item has conflicting block types');
            }
            $checked[$id]=$expected;
            $meta=$media->metadata($id);
            if ($meta===null || ($expected==='image' && !str_starts_with($meta['mime'],'image/')) ||
                ($expected==='file' && $meta['mime']!=='application/pdf')) {
                throw new \InvalidArgumentException('Media not found or its type is incompatible with the block');
            }
        }
    }
}
