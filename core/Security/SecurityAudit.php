<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

/** Offline release/deployment audit. Scans real executable files and dependency inventory.
 * No network, package installation or private application data is required.
 */
final class SecurityAudit
{
    /** @return array{ok:bool,checks:list<array{id:string,status:string,detail:string}>,dependency_inventory:array<string,mixed>} */
    public static function run(string $root): array
    {
        $checks=[];
        $add=static function(string $id,bool $passed,string $detail)use(&$checks):void {
            $checks[]=['id'=>$id,'status'=>$passed?'pass':'fail','detail'=>$detail];
        };
        $public=$root.'/public';
        $add('docroot',is_dir($public) && !is_link($public) && is_file($public.'/index.php') && !is_link($public.'/index.php'),
            'Public document root and front controller must be regular, local paths');
        $unsafe=[];
        if (is_dir($public) && !is_link($public)) {
            $scan=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($scan as $entry) {
                $relative=substr($entry->getPathname(),strlen($public)+1);
                if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                    $unsafe[]=$relative; continue;
                }
                if ($entry->isDir()) continue;
                $name=$entry->getFilename();
                if ($relative==='index.php' || $relative==='.htaccess') continue;
                if (preg_match('/(?:^|\/)\./',$relative) ||
                    preg_match('/\.(?:php\d*|phtml|phar|cgi|pl|py|sh|htaccess|env|bak|sql|sqlite|json|lock)$/iD',$name)) {
                    $unsafe[]=$relative;
                }
            }
        }
        sort($unsafe,SORT_STRING);
        $add('public_executable_files',$unsafe===[],
            $unsafe===[]?'No additional executable/private files in document root':'Unsafe public files: '.implode(', ',array_slice($unsafe,0,12)));
        $add('php_runtime',PHP_VERSION_ID>=80401 && extension_loaded('json') && extension_loaded('hash') && extension_loaded('filter'),
            'PHP 8.4.1+, json, hash and filter are required');
        $composer=is_file($root.'/composer.json');
        $node=is_file($root.'/package.json');
        $vendor=is_dir($root.'/vendor') || is_link($root.'/vendor');
        $modules=is_dir($root.'/node_modules') || is_link($root.'/node_modules');
        $composerLock=is_file($root.'/composer.lock');
        $nodeLock=is_file($root.'/package-lock.json') || is_file($root.'/npm-shrinkwrap.json') || is_file($root.'/pnpm-lock.yaml') || is_file($root.'/yarn.lock');
        $dependencyMetadata=[
            'composer.json','composer.lock','package.json','package-lock.json',
            'npm-shrinkwrap.json','pnpm-lock.yaml','yarn.lock',
        ];
        $metadataLinks=false;
        foreach ($dependencyMetadata as $name) {
            if (is_link($root.'/'.$name)) $metadataLinks=true;
        }
        $dependenciesSafe=!$metadataLinks && (!$composer || $composerLock) && (!$node || $nodeLock) &&
            (!$vendor || ($composer && $composerLock)) && (!$modules || ($node && $nodeLock));
        $add('dependency_lockfiles',$dependenciesSafe,
            $dependenciesSafe?'No symlinked manifests or missing dependency lockfiles':'Unpinned or untracked third-party dependency directory/manifest');
        $add('public_vendor_exposure',!is_dir($public.'/vendor') && !is_link($public.'/vendor') &&
            !is_dir($public.'/node_modules') && !is_link($public.'/node_modules'),
            'No third-party source directories may be web-accessible');
        return [
            'ok'=>!in_array('fail',array_column($checks,'status'),true),
            'checks'=>$checks,
            'dependency_inventory'=>[
                'composer_manifest'=>$composer, 'composer_lock'=>$composerLock,
                'node_manifest'=>$node, 'node_lock'=>$nodeLock,
                'vendor_directory'=>$vendor, 'node_modules_directory'=>$modules,
                'note'=>'Local inventory and lockfile enforcement only; live CVE/advisory audit requires a separate trusted vulnerability scanner.',
            ],
        ];
    }
}
