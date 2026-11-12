<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/[A-Za-z0-9_-]+\.(css|js|png|jpg|jpeg|webp|ico)$~D',$path) && is_file(__DIR__.'/public'.$path)) return false;
require __DIR__.'/public/index.php';
