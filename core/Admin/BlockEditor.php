<?php
declare(strict_types=1);
namespace CajeerEngine\Admin;

use CajeerEngine\AI\PageHistory;
use CajeerEngine\Content\Blocks;
use CajeerEngine\Lite;

/** Strict, atomic writes shared by visual editor and sandboxed server preview. */
final class BlockEditor
{
    public static function candidate(array $post): array
    {
        $blocks=Blocks::decode($post['blocks_json']??null);
        $body=Blocks::plain($blocks);
        $page=Lite::page([
            'path'=>$post['path']??null,
            'title'=>$post['title']??null,
            'description'=>$post['description']??null,
            'body'=>$body,
            'published'=>$post['published']??'',
            'menu'=>$post['menu']??'',
        ]);
        // The block document is the canonical editor source. Keep exact plain text
        // including trailing whitespace for AI and full-text audit compatibility.
        $page['body']=$body;
        $page['blocks']=$blocks;
        return $page;
    }

    public static function save(Lite $app,array $post): string
    {
        $page=self::candidate($post);
        $old=Lite::field($post,'old',1024);
        $rev=Lite::field($post,'revision',64);
        if (($old==='' && $rev!=='') || ($old!=='' && !preg_match('/^[a-f0-9]{64}$/D',$rev))) {
            throw new \InvalidArgumentException('Invalid edit revision');
        }
        $generation=$app->load()['identity']['generation'];
        $history=$old!=='' ? new PageHistory($app->root,$generation) : null;
        $app->change(static function(array $state) use ($old,$rev,$page,$generation,$history):array {
            if (!hash_equals($generation,$state['identity']['generation'])) throw new \InvalidArgumentException('Owner changed');
            if ($old!=='') {
                if (!isset($state['pages'][$old]) || !hash_equals(PagesManagement::revision($state['pages'][$old]),$rev)) {
                    throw new \InvalidArgumentException('Page changed; reload before saving');
                }
            }
            if ($old==='/' && ($page['path']!=='/' || !$page['published'])) throw new \InvalidArgumentException('Home must remain published at /');
            if (isset($state['pages'][$page['path']]) && $old!==$page['path']) throw new \InvalidArgumentException('Page path already exists');
            if ($old==='') {
                if (count($state['pages'])>=100) throw new \InvalidArgumentException('Page limit: 100');
            } else {
                if ($state['pages'][$old]!==$page) $history?->save($state['pages'][$old]);
                unset($state['pages'][$old]);
            }
            $state['pages'][$page['path']]=$page;
            return $state;
        });
        return $page['path'];
    }
}
