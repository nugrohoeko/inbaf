<?php

// Serve the built UI without changing the developer's public/hot marker.
$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($public.$path)) return false;

define('LARAVEL_START', microtime(true));
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || realpath(config('database.connections.sqlite.database')) !== realpath(dirname(__DIR__, 2).'/tmp/browser.sqlite')) {
    throw new RuntimeException('The browser router requires the isolated SQLite QA database.');
}
$app->make(Illuminate\Foundation\Vite::class)->useHotFile(base_path('tmp/browser-no-hot'));
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
