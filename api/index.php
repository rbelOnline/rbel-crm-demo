<?php

/*
| Vercel entry point (see vercel.json and docs/DEPLOY-VERCEL.md).
| Vercel's filesystem is read-only except /tmp, so Laravel's storage folder
| (compiled views, framework cache, uploaded files) is moved there. Files in
| /tmp are temporary: they disappear when Vercel starts a fresh instance.
*/

$storage = '/tmp/storage';

foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir("{$storage}/{$dir}")) {
        mkdir("{$storage}/{$dir}", 0755, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

require __DIR__.'/../public/index.php';
