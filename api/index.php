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
        try {
            $server = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
                $caPath !== '' ? [PDO::MYSQL_ATTR_SSL_CA => $caPath] : []);
            foreach ($server->query("SELECT s.schema_name, (SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = s.schema_name) FROM information_schema.schemata s WHERE s.schema_name NOT IN ('mysql','sys','information_schema','performance_schema')")->fetchAll(PDO::FETCH_NUM) as [$name, $tables]) {
                echo "database on server: {$name} ({$tables} tables)\n";
                $names[$name] = $server->query('SELECT table_name FROM information_schema.tables WHERE table_schema = '.$server->quote($name))->fetchAll(PDO::FETCH_COLUMN);
            }
            if (isset($names['defaultdb'], $names['rbel_crm_demo'])) {
                echo 'only in defaultdb: '.implode(', ', array_diff($names['defaultdb'], $names['rbel_crm_demo']))."\n";
                echo 'only in rbel_crm_demo: '.implode(', ', array_diff($names['rbel_crm_demo'], $names['defaultdb']))."\n";
                $c = $server->query('SELECT COUNT(*) FROM rbel_crm_demo.clients')->fetchColumn();
                $p = $server->query('SELECT COUNT(*) FROM rbel_crm_demo.policies')->fetchColumn();
                echo "rbel_crm_demo rows: {$c} clients, {$p} policies\n";
            }
        } catch (Throwable) {
        }
    }
    exit;
}

require __DIR__.'/../public/index.php';
