<?php
declare(strict_types=1);
namespace CajeerEngine\Content;

use CajeerEngine\Lite;

/** Server-owned block schema. Text is never interpreted as HTML. */
final class Blocks
{
    public const MAX_COUNT = 120;
    public const MAX_BYTES = 180000;
    private const TYPES = ['paragraph','heading','quote','list','link','button','divider','image','file'];

    public static function decode(mixed $json): array
    {
        if (!is_string($json) || strlen($json) > self::MAX_BYTES || $json === '') {
            throw new \InvalidArgumentException('Invalid block document');
        }
        try { $blocks=json_decode($json,true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Invalid block JSON',0,$e); }
        return self::validate($blocks);
    }

    /** Strict keys, lengths, UTF-8, and safe URLs; disallow active markup entirely. */
    public static function validate(mixed $blocks): array
    {
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks)>self::MAX_COUNT ||
            count($blocks)<1) throw new \InvalidArgumentException('Invalid blocks count');
        $normalized=[];
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['type']??null) ||
                !in_array($block['type'],self::TYPES,true)) throw new \InvalidArgumentException('Invalid block type');
            $type=$block['type'];$keys=array_keys($block);sort($keys);
            $expected=match($type) {
                'heading'=>['level','text','type'],
                'list'=>['items','ordered','type'],
                'link','button'=>['text','type','url'],
                'divider'=>['type'],
                'image'=>['alt','caption','media_id','size','type'],
                'file'=>['label','media_id','type'],
                default=>['text','type'],
            };
            if ($keys!==$expected) throw new \InvalidArgumentException('Invalid block properties');
            if ($type==='divider') { $normalized[]=['type'=>$type];continue; }
            if ($type==='image' || $type==='file') {
                if (!is_string($block['media_id']) || !preg_match('/^[a-f0-9]{32}$/D',$block['media_id'])) {
                    throw new \InvalidArgumentException('Invalid media identifier');
                }
                if ($type==='image') {
                    self::text($block['alt'],300);
                    self::text($block['caption'],600);
                    if (!in_array($block['size'],['small','medium','full'],true)) {
                        throw new \InvalidArgumentException('Invalid image size');
                    }
                    $normalized[]=['type'=>'image','media_id'=>$block['media_id'],
                        'alt'=>$block['alt'],'caption'=>$block['caption'],'size'=>$block['size']];
                } else {
                    self::text($block['label'],300);
                    if (trim($block['label'])==='') throw new \InvalidArgumentException('File label is required');
                    $normalized[]=['type'=>'file','media_id'=>$block['media_id'],'label'=>$block['label']];
                }
                continue;
            }
            if ($type==='list') {
                if (!is_bool($block['ordered']) || !is_array($block['items']) || !array_is_list($block['items']) ||
                    count($block['items'])<1 || count($block['items'])>40) throw new \InvalidArgumentException('Invalid list');
                foreach ($block['items'] as $item) self::text($item,2000);
                $normalized[]=['type'=>$type,'ordered'=>$block['ordered'],'items'=>$block['items']];continue;
            }
            self::text($block['text'], $type==='paragraph'?60000:6000);
            if ($type==='heading') {
                if (!is_int($block['level']) || $block['level']<2 || $block['level']>6) throw new \InvalidArgumentException('Invalid heading level');
                $normalized[]=['type'=>$type,'level'=>$block['level'],'text'=>$block['text']];continue;
            }
            if ($type==='link' || $type==='button') {
                if ($block['text']==='' || !is_string($block['url']) || !self::url($block['url'])) {
                    throw new \InvalidArgumentException('Invalid link URL or caption');
                }
                $normalized[]=['type'=>$type,'text'=>$block['text'],'url'=>$block['url']];continue;
            }
            $normalized[]=['type'=>$type,'text'=>$block['text']];
        }
        if (strlen(json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>self::MAX_BYTES) {
            throw new \InvalidArgumentException('Block document too large');
        }
        if (strlen(self::plain($normalized))>60000) throw new \InvalidArgumentException('Page body exceeds 60000 bytes');
        return $normalized;
    }

    private static function text(mixed $v,int $max): void
    {
        if (!is_string($v) || strlen($v)>$max || str_contains($v,"\0") || !preg_match('//u',$v) ||
            preg_match('/[\x{2028}\x{2029}]/u',$v)) throw new \InvalidArgumentException('Invalid block text');
    }
    private static function url(string $url): bool
    {
        if (strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return false;
        if (str_starts_with($url,'/') && !str_starts_with($url,'//')) return !str_contains($url,'/..');
        $parts=parse_url($url);
        return is_array($parts) && ($parts['scheme']??'')==='https' &&
            is_string($parts['host']??null) && filter_var($url,FILTER_VALIDATE_URL)!==false &&
            !isset($parts['user']) && !isset($parts['pass']);
    }

    public static function plain(array $blocks): string
    {
        $lines=[];
        foreach ($blocks as $block) {
            $type=$block['type'];
            if ($type==='divider') continue;
            if ($type==='image') {
                if ($block['caption']!=='') $lines[]=$block['caption'];
                continue;
            }
            if ($type==='file') { $lines[]=$block['label'];continue; }
            if ($type==='list') { $lines[]=implode("\n",$block['items']);continue; }
            $lines[]=$block['text'];
        }
        return implode("\n\n",$lines);
    }

    public static function fromLegacy(string $body): array
    {
        return [['type'=>'paragraph','text'=>$body]];
    }

    public static function html(array $blocks): string
    {
        $result='';
        foreach (self::validate($blocks) as $block) {
            $type=$block['type'];
            if ($type==='divider') { $result.='<hr class="cms-divider">';continue; }
            if ($type==='image') {
                $url='/media/'.$block['media_id'];
                $result.='<figure class="cms-media-figure cms-media-size-'.$block['size'].'">'.
                    '<img src="'.$url.'" alt="'.Lite::esc($block['alt']).'" loading="lazy" decoding="async">'.
                    ($block['caption']!==''?'<figcaption>'.nl2br(Lite::esc($block['caption']),false).'</figcaption>':'').
                    '</figure>';
                continue;
            }
            if ($type==='file') {
                $result.='<p class="cms-media-document"><a href="/media/'.$block['media_id'].'?download=1" '.
                    'download rel="noopener noreferrer">'.Lite::esc($block['label']).'</a></p>';
                continue;
            }
            if ($type==='list') {
                $tag=$block['ordered']?'ol':'ul'; $result.='<'.$tag.' class="cms-list">';
                foreach($block['items'] as $item) $result.='<li>'.nl2br(Lite::esc($item),false).'</li>';
                $result.='</'.$tag.'>';continue;
            }
            $text=nl2br(Lite::esc($block['text']),false);
            if ($type==='heading') { $tag='h'.$block['level'];$result.='<'.$tag.'>'.$text.'</'.$tag.'>';continue; }
            if ($type==='quote') { $result.='<blockquote>'.$text.'</blockquote>';continue; }
            if ($type==='link' || $type==='button') {
                $class=$type==='button'?' class="cms-button"':'';
                $result.='<p><a'.$class.' href="'.Lite::esc($block['url']).'"'.
                    (str_starts_with($block['url'],'https://')?' rel="noopener noreferrer"':'').'>'.$text.'</a></p>';continue;
            }
            $result.='<p>'.$text.'</p>';
        }
        return '<div class="cms-block-content">'.$result.'</div>';
    }

    /** Preserve metadata-only AI edits; text AI edits deliberately leave block mode. */
    public static function reconcile(array $before,array $after): array
    {
        if ($after['body']===$before['body'] && isset($before['blocks'])) {
            $after['blocks']=$before['blocks'];
        } elseif ($after['body']!==$before['body']) {
            unset($after['blocks']);
        }
        return $after;
    }
}
