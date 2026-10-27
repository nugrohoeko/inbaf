<?php

// Run compiled-cache checks against an existing, explicitly guarded QA database only.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use Symfony\Component\Process\Process;

$base = dirname(__DIR__, 2);
$root = realpath($base.'/tmp/qa/final/operations');
$manifest = realpath($argv[1] ?? '');
if (! $root || ! $manifest || ! str_starts_with(str_replace('\\', '/', $manifest), str_replace('\\', '/', $root).'/')) throw new RuntimeException('Use an isolated QA worker manifest.');
$context = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^inbaf_qa_rc_[a-f0-9]{12}$/D', $context['database'] ?? '')) throw new RuntimeException('Unsafe database target.');
$cacheRoot = $root.'/compiled-'.$context['database'];
$cacheRelative = 'tmp/qa/final/operations/compiled-'.$context['database'];
foreach ([$cacheRoot, $cacheRoot.'/views'] as $path) if (! is_dir($path)) mkdir($path, 0700, true);
$environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $context['database'], 'DB_URL' => '', 'APP_CONFIG_CACHE' => $cacheRelative.'/config.php', 'APP_ROUTES_CACHE' => $cacheRelative.'/routes.php', 'APP_EVENTS_CACHE' => $cacheRelative.'/events.php', 'VIEW_COMPILED_PATH' => $cacheRoot.'/views', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
$checks = [];
foreach ([['config:cache'], ['route:cache'], ['view:cache'], ['route:list', '--json'], ['schedule:list'], ['migrate:status'], ['inbaf:audit-data', '--json']] as $arguments) {
    $process = new Process([PHP_BINARY, 'artisan', ...$arguments], $base, $environment, null, 120);
    $started = microtime(true); $process->run();
    if (! $process->isSuccessful()) file_put_contents($root.'/cache-error-private.txt', $process->getOutput().$process->getErrorOutput());
    $checks[] = ['command' => implode(' ', $arguments), 'status' => $process->isSuccessful() ? 'PASS' : 'FAIL', 'duration_ms' => round((microtime(true) - $started) * 1000, 2)];
    if (! $process->isSuccessful()) break;
}
$report = ['status' => count($checks) === 7 && ! in_array('FAIL', array_column($checks, 'status')) ? 'PASS' : 'FAIL', 'scope' => 'Compiled caches, routes, views, migrations, scheduler declaration and data integrity on restored MySQL copy. Does not prove a production scheduler/worker service is running.', 'live_cache_untouched' => ! is_file($base.'/bootstrap/cache/config.php') && ! is_file($base.'/bootstrap/cache/routes-v7.php'), 'checks' => $checks];
file_put_contents($root.'/cache-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit($report['status'] === 'PASS' ? 0 : 1);
