<?php
declare(strict_types=1);
namespace CajeerEngine\SEO;

use CajeerEngine\Lite;
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Public\PublicPath;
use CajeerEngine\Media\MediaLibrary;

/** Persistent SEO preferences applied to actual public HTML, robots and sitemap. */
final class SEOControl
{
    public const GLOBAL_DEFAULTS = ['indexable'=>true, 'title_suffix'=>true, 'default_image'=>''];
    public const PAGE_DEFAULTS = [
        'index'=>true, 'follow'=>true, 'sitemap'=>true, 'canonical'=>'',
        'og_title'=>'', 'og_description'=>'', 'og_image'=>'',
        'changefreq'=>'monthly', 'priority'=>5,
    ];
    private const FREQUENCIES = ['always','hourly','daily','weekly','monthly','yearly','never'];

    private static function exactKeys(array $value, array $expected): void
    {
        $a=array_keys($value); $b=array_keys($expected); sort($a); sort($b);
        if ($a!==$b) throw new \InvalidArgumentException('Invalid SEO configuration keys');
    }

    private static function text(mixed $value,int $limit): string
    {
        if (!is_string($value) || strlen($value)>$limit || str_contains($value,"\0") ||
            !preg_match('//u',$value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) {
            throw new \InvalidArgumentException('Invalid SEO text');
        }
        return $value;
    }

    private static function imageId(mixed $id): string
    {
        if (!is_string($id) || ($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id))) {
            throw new \InvalidArgumentException('Invalid SEO image ID');
        }
        return $id;
    }

    public static function validateGlobal(array $config): void
    {
        self::exactKeys($config,self::GLOBAL_DEFAULTS);
        if (!is_bool($config['indexable']) || !is_bool($config['title_suffix'])) {
            throw new \InvalidArgumentException('Invalid SEO site flags');
        }
        self::imageId($config['default_image']);
    }

    public static function validatePage(array $config): void
    {
        self::exactKeys($config,self::PAGE_DEFAULTS);
        foreach (['index','follow','sitemap'] as $field) {
            if (!is_bool($config[$field])) throw new \InvalidArgumentException('Invalid SEO page flags');
        }
        self::text($config['og_title'],200);
        self::text($config['og_description'],500);
        self::imageId($config['og_image']);
        if (!is_string($config['canonical']) || strlen($config['canonical'])>1024 ||
            ($config['canonical']!=='' && PublicPath::content($config['canonical'])!==$config['canonical'])) {
            throw new \InvalidArgumentException('Canonical must be a normalized internal page path');
        }
        if (!in_array($config['changefreq'],self::FREQUENCIES,true) || !is_int($config['priority']) ||
            $config['priority']<0 || $config['priority']>10) {
            throw new \InvalidArgumentException('Invalid sitemap options');
        }
    }

    public static function site(array $state): array
    {
        return $state['seo'] ?? self::GLOBAL_DEFAULTS;
    }

    public static function page(array $page): array
    {
        return $page['seo'] ?? self::PAGE_DEFAULTS;
    }

    private static function checkbox(array $post,string $key): bool
    {
        if (isset($post[$key]) && $post[$key]!=='1') throw new \InvalidArgumentException('Invalid checkbox: '.$key);
        return isset($post[$key]);
    }

    public static function revision(array $state): string
    {
        return hash('sha256',json_encode(self::site($state),JSON_THROW_ON_ERROR));
    }

    public static function globalFromPost(array $post): array
    {
        $config=[
            'indexable'=>self::checkbox($post,'indexable'),
            'title_suffix'=>self::checkbox($post,'title_suffix'),
            'default_image'=>self::imageId($post['default_image']??''),
        ];
        self::validateGlobal($config);
        return $config;
    }

    public static function pageFromPost(array $post): array
    {
        $priority=$post['priority']??null;
        if (!is_string($priority) || !preg_match('/^(?:10|[0-9])$/D',$priority)) {
            throw new \InvalidArgumentException('Priority must be an integer from 0 to 10');
        }
        $canonical=self::text($post['canonical']??'',1024);
        if ($canonical!=='' && !str_starts_with($canonical,'/')) {
            throw new \InvalidArgumentException('Canonical must be a local path starting with /');
        }
        $config=[
            'index'=>self::checkbox($post,'index'),
            'follow'=>self::checkbox($post,'follow'),
            'sitemap'=>self::checkbox($post,'sitemap'),
            'canonical'=>$canonical,
            'og_title'=>trim(self::text($post['og_title']??'',200)),
            'og_description'=>trim(self::text($post['og_description']??'',500)),
            'og_image'=>self::imageId($post['og_image']??''),
            'changefreq'=>self::text($post['changefreq']??'',10),
            'priority'=>(int)$priority,
        ];
        self::validatePage($config);
        return $config;
    }

    /** Called with the media lock held by the admin route. */
    public static function verifyImage(MediaLibrary $media, string $id): void
    {
        if ($id==='') return;
        $meta=$media->metadata($id);
        if ($meta===null || !str_starts_with($meta['mime'],'image/')) {
            throw new \InvalidArgumentException('SEO image must be an existing image in the media library');
        }
    }

    public static function canonicalPath(array $page): string
    {
        return self::page($page)['canonical'] ?: $page['path'];
    }

    public static function isIndexable(array $state,array $page): bool
    {
        return $page['published'] && self::site($state)['indexable'] && self::page($page)['index'];
    }

    public static function robots(array $state,array $page): string
    {
        $config=self::page($page);
        return (self::isIndexable($state,$page)?'index':'noindex').', '.($config['follow']?'follow':'nofollow');
    }

    public static function presentation(array $state,array $page): array
    {
        $config=self::page($page);
        $site=$state['settings'];
        $imageId=$config['og_image']!==''?$config['og_image']:self::site($state)['default_image'];
        $title=self::site($state)['title_suffix']?$page['title'].' — '.$site['name']:$page['title'];
        return [
            'title'=>$title,
            'canonical'=>$site['url'].self::canonicalPath($page),
            'robots'=>self::robots($state,$page),
            'og_title'=>$config['og_title']!==''?$config['og_title']:$page['title'],
            'og_description'=>$config['og_description']!==''?$config['og_description']:$page['description'],
            'og_image'=>$imageId!==''?$site['url'].'/media/'.$imageId:'',
            'og_url'=>$site['url'].self::canonicalPath($page),
            'twitter_card'=>$imageId!==''?'summary_large_image':'summary',
        ];
    }

    /** JSON-LD values are JSON encoded with HTML-significant bytes escaped. */
    public static function structuredData(array $state,array $page): string
    {
        if (!self::isIndexable($state,$page) || self::canonicalPath($page)!==$page['path']) return '';
        $presentation=self::presentation($state,$page);
        $json=[
            '@context'=>'https://schema.org',
            '@type'=>'WebPage',
            '@id'=>$presentation['canonical'].'#webpage',
            'url'=>$presentation['canonical'],
            'name'=>$presentation['title'],
            'description'=>$page['description'],
            'inLanguage'=>'ru',
            'isPartOf'=>[
                '@type'=>'WebSite',
                '@id'=>$state['settings']['url'].'/#website',
                'url'=>$state['settings']['url'].'/',
                'name'=>$state['settings']['name'],
            ],
        ];
        if ($presentation['og_image']!=='') $json['image']=$presentation['og_image'];
        return json_encode($json, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public static function robotsText(array $state): string
    {
        $lines=['User-agent: *'];
        $lines[]=self::site($state)['indexable']?'Disallow: /admin':'Disallow: /';
        if (self::site($state)['indexable'] && ModuleConfig::enabled($state,'sitemap')) {
            $lines[]='Sitemap: '.$state['settings']['url'].'/sitemap.xml';
        }
        return implode("\n",$lines)."\n";
    }

    public static function sitemapXml(array $state): string
    {
        $escape=static fn(string $value):string=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($state['pages'] as $page) {
            $options=self::page($page);
            if (!self::isIndexable($state,$page) || !$options['sitemap'] ||
                self::canonicalPath($page)!==$page['path']) continue;
            $xml.='  <url><loc>'.$escape($state['settings']['url'].$page['path']).'</loc>'.
                '<changefreq>'.$options['changefreq'].'</changefreq>'.
                '<priority>'.number_format($options['priority']/10,1,'.','').'</priority></url>'."\n";
        }
        return $xml.'</urlset>';
    }
}
