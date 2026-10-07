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

// TEMPORARY database connection check: /up/db reports whether the database is reachable
// (error code and a short message only; never the password). Remove once the deploy works.
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/up/db') {
    header('Content-Type: text/plain');
    $ca = getenv('MYSQL_ATTR_SSL_CA') ?: '';
    $caPath = $ca === '' ? '' : (is_file($ca) ? $ca : __DIR__.'/../'.$ca);
    echo 'host: '.getenv('DB_HOST').':'.getenv('DB_PORT').' / db: '.getenv('DB_DATABASE').' / user: '.getenv('DB_USERNAME')."\n";
    echo 'password set: '.(getenv('DB_PASSWORD') ? 'yes ('.strlen(getenv('DB_PASSWORD')).' chars)' : 'NO')."\n";
    echo 'ssl ca: '.($ca === '' ? 'NOT SET' : $ca.' -> '.(is_file($caPath) ? 'found' : 'MISSING'))."\n";
    echo 'app key set: '.(getenv('APP_KEY') ? 'yes' : 'NO')."\n";
    try {
        $pdo = new PDO(
            'mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'),
            getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
            $caPath !== '' ? [PDO::MYSQL_ATTR_SSL_CA => $caPath] : [],
        );
        echo 'connection: OK, MySQL '.$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
        echo 'sessions table: '.($pdo->query("SHOW TABLES LIKE 'sessions'")->fetchColumn() ? 'yes' : 'NO')."\n";
        echo 'clients: '.$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn()."\n";
    } catch (Throwable $e) {
        echo 'connection: FAILED - '.substr(str_replace(getenv('DB_PASSWORD') ?: "\0", '***', $e->getMessage()), 0, 300)."\n";
    }
    exit;
}

require __DIR__.'/../public/index.php';
