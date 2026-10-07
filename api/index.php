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

// This script lives in /api, so Laravel would treat "/api" as the base path and strip it
// from every URL (/api/login would become /login). Present it as the site's root front controller.
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';

require __DIR__.'/../public/index.php';
