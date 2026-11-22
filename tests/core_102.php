<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Lite;
use CajeerEngine\Modules\ModuleConfig;
use CajeerEngine\Storage\{SiteRepository,SiteState};
use CajeerEngine\Update\OperationFiles;

function assert102(bool $test, string $why): void { if (!$test) throw new RuntimeException($why); }
function fails102(callable $callback, string $why): void {
    try { $callback(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Should have failed: '.$why);
}
function clear102(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) clear102($entry->getPathname());
    rmdir($path);
}
$root=sys_get_temp_dir().'/lite-102-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $app = new Lite($root);
    $app->install('AnotherPassword123', 'https://example.org', 'Hello');
    assert102(Lite::VERSION === '1.2.2', 'wrong version');
    assert102($app->load()['schema'] === 13, 'new install schema');
    assert102($app->load()['modules'] === ModuleConfig::DEFAULTS, 'new modules defaults');
    assert102(!$app->migrate(), 'noop migration');
    assert102($app->login('AnotherPassword123'), 'login failed');
    $backup = $app->backups();
    $first = $backup->create();
    assert102($backup->verify($first)['schema'] === 13, 'backup verify');
    assert102(count($backup->names()) === 1, 'backup listing');
    assert102((fileperms($root.'/storage/backups') & 0777) === 0700, 'private backup directory');
    assert102((fileperms($root.'/storage/backups/'.$first) & 0777) === 0600, 'private backup');
    $app->module('navigation', false);
    assert102(!str_contains((new Lite(dirname(__DIR__)))->render($app->load(), $app->load()['pages']['/']), '<nav'), 'navigation module not disabled');
    $app->module('sitemap', false);
    assert102(!$app->load()['modules']['sitemap'], 'sitemap persisted');
    fails102(fn()=> $app->module('bad-module', true), 'reject unknown module');
    assert102($backup->restore($first) !== '', 'pre-restore snapshot missing');
    assert102($app->load()['modules'] === ModuleConfig::DEFAULTS, 'restore did not restore modules');
    assert102(count($backup->names()) === 2, 'pre-restore copy missing');

    // Build a real schema 1 site like Lite 1.0.1 and ensure its original bytes are backed up.
    $state = $app->load();
    $state['schema'] = 1;
    unset($state['modules'], $state['consent'], $state['integrations']);
    $raw = SiteState::encode($state);
    OperationFiles::write($app->file(), $raw, 0600);
    assert102($app->migrate(), 'v1 migration was not performed');
    $migrated = $app->load();
    assert102($migrated['schema'] === 13, 'v1 upgrade incorrect');
    assert102($migrated['modules'] === ModuleConfig::DEFAULTS, 'v1 defaults absent');
    $migration = null;
    foreach ($backup->names() as $entry) if ($backup->verify($entry)['reason'] === 'migration') $migration = $entry;
    assert102(is_string($migration), 'missing migration backup');
    $info = $backup->verify($migration);
    assert102($info['reason'] === 'migration' && $info['schema'] === 1, 'migration backup not preserved');
    assert102($backup->restore($migration) !== '', 'restore v1 snapshot');
    assert102($app->load()['schema'] === 13, 'restored schema 1 not upgraded');
    assert102($app->login('AnotherPassword123'), 'restored site authentication');

    // Tampered checksums, symlinks, and permissions must never overwrite the live site.
    $before = file_get_contents($app->file());
    $bad = $backup->create();
    $path = $root.'/storage/backups/'.$bad;
    $bytes = file_get_contents($path);
    file_put_contents($path, str_replace('"sha256": "', '"sha256": "bad', $bytes));
    fails102(fn()=> $backup->verify($bad), 'tampered digest');
    fails102(fn()=> $backup->restore($bad), 'tampered restore');
    assert102(file_get_contents($app->file()) === $before, 'invalid backup changed state');
    unlink($path);
    symlink($app->file(), $path);
    fails102(fn()=> $backup->restore($bad), 'backup symlink');
    unlink($path);
    $permissions = $backup->create();
    chmod($root.'/storage/backups/'.$permissions, 0666);
    fails102(fn()=> $backup->verify($permissions), 'world-readable backup');
    chmod($root.'/storage/backups/'.$permissions, 0600);
    fails102(fn()=> $backup->verify('../site.json'), 'path traversal');

    // A rollback can recover a damaged JSON and keeps its exact bytes for forensic rescue.
    $good = $backup->create();
    OperationFiles::write($app->file(), '{bad JSON', 0600);
    fails102(fn()=> $app->load(), 'corrupted JSON');
    $rescue = $backup->restore($good);
    assert102($rescue !== '' && is_file($root.'/storage/backups/'.$rescue), 'damaged state not preserved');
    assert102($app->load()['schema'] === 13, 'recovery failed');
    assert102($app->login('AnotherPassword123'), 'recovered auth failed');

    // Future schemas and invalid state transitions are rejected before any write.
    $bytes=file_get_contents($app->file());
    fails102(fn()=> $app->change(static function(array $d): array {$d['schema']=999; return $d;}), 'future schema');
    assert102(file_get_contents($app->file()) === $bytes, 'bad transaction committed');
    $store = new SiteRepository($root);
    fails102(fn()=> $store->commit($app->load()), 'commit without lock');
    echo "Lite legacy core regression OK\n";
} finally { clear102($root); }
