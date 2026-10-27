<?php

/** Read-only controller timings on an existing restored QA database, never production capacity evidence. */
if (PHP_SAPI !== 'cli') exit(1);
if (empty($argv[1])) {
    echo "Usage: php tests/Operations/query-benchmark.php <existing isolated QA worker manifest>\n";
    exit(0);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\{AdminController, ParticipantController};
use App\Models\{ExamAttempt, User};
use App\Services\AttemptService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function benchmarkAssert(bool $condition): void
{
    if (! $condition) throw new RuntimeException('Read-only benchmark guard failed.');
}

function benchmarkStats(array $values): array
{
    sort($values);
    $count = count($values);

    return ['n' => $count, 'min' => round($values[0], 3), 'p50' => round($values[(int) ceil($count * .5) - 1], 3), 'p95' => round($values[(int) ceil($count * .95) - 1], 3), 'max' => round($values[$count - 1], 3)];
}

$report = ['status' => 'NOT RUN', 'scope' => 'Five warm samples of real controllers on a restored local MySQL QA copy; one excluded warm-up per operation. No HTTP transport, middleware, production capacity threshold, or load certification.', 'timing_note' => 'SQL times are Laravel QueryExecuted timings; wall time includes controller work and JSON serialization. Cursor consumption may extend beyond the SQL execution timer.', 'read_only' => true, 'operations' => []];
$connection = null;
$root = null;
try {
    $base = dirname(__DIR__, 2);
    $root = realpath($base.'/tmp/qa/final/operations');
    $path = realpath($argv[1]);
    $normalized = fn (string $value) => strtolower(str_replace('\\', '/', rtrim($value, '/\\')));
    benchmarkAssert($root !== false && $path !== false && str_starts_with($normalized($path), $normalized($root).'/'));
    benchmarkAssert((bool) preg_match('/^worker-[a-f0-9]+-\d+\.json$/D', basename($path)));
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $name = $manifest['database'] ?? '';
    benchmarkAssert((bool) preg_match('/^inbaf_qa_rc_[a-f0-9]{12}$/D', $name));
    $private = $root.'/private-'.$name;
    benchmarkAssert($normalized((string) ($manifest['private_root'] ?? '')) === $normalized($private));
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    benchmarkAssert(! $app->configurationIsCached() && config('database.default') === 'mysql');
    benchmarkAssert($name !== DB::connection('mysql')->getDatabaseName());
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name, 'database.connections.mysql.url' => null, 'app.env' => 'testing', 'app.debug' => false, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array', 'filesystems.disks.local.root' => $private]);
    DB::purge('mysql');
    $connection = DB::connection('mysql');
    // The server rejects writes even if a future controller change introduces
    // one. A pre-execution hook rejects them before SQL is sent as well.
    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->statement('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $connection->beforeExecuting(function (string $sql): void {
        benchmarkAssert((bool) preg_match('/^\s*(?:select|show|describe|explain)\b/i', $sql));
    });
    $report['mysql_version'] = $connection->selectOne('SELECT VERSION() AS version')->version;
    $report['dataset'] = [];
    foreach (['users', 'questions', 'exam_packages', 'exam_attempts', 'exam_answers', 'certificates'] as $table) $report['dataset'][$table] = DB::table($table)->count();
    $admin = User::where('role', 'superadmin')->where('active', true)->where('activation_pending', false)->first();
    $participant = User::where('role', 'participant')->where('active', true)->where('activation_pending', false)->withCount('attempts')->orderByDesc('attempts_count')->orderBy('id')->first();
    $expired = ExamAttempt::where('status', 'in_progress')->where('expires_at', '<=', now())->exists();
    $recording = false;
    $current = [];
    DB::listen(function ($query) use (&$recording, &$current): void {
        if (! $recording) return;
        $current['query_count']++;
        $current['sql_total_ms'] += $query->time;
        $current['sql_max_query_ms'] = max($current['sql_max_query_ms'], $query->time);
    });
    $operations = [
        'dashboard_admin' => [$admin, '/api/dashboard', false],
        'dashboard_participant' => [$participant, '/api/dashboard', false],
        'question_bank_admin_page_1' => [$admin, '/api/admin/questions', true],
    ];
    foreach ($operations as $label => [$actor, $uri, $questions]) {
        if (! $actor || (! $questions && $expired)) {
            $report['operations'][$label] = ['status' => 'BLOCKED', 'reason' => ! $actor ? 'Required active QA role is unavailable.' : 'Expired attempts require finalization; this read-only probe refuses to finalize them.'];
            continue;
        }
        $samples = [];
        for ($iteration = 0; $iteration < 6; $iteration++) {
            $request = Request::create($uri, 'GET', $questions ? ['page' => 1, 'per_page' => 20] : [], server: ['HTTP_ACCEPT' => 'application/json']);
            app()->instance('request', $request);
            // Rebinding the request installs Laravel's guard-based resolver.
            // Supply the isolated actor afterward without logging in or writing
            // a session, so the real controller receives the intended role.
            $request->setUserResolver(fn () => $actor);
            $current = ['query_count' => 0, 'sql_total_ms' => 0.0, 'sql_max_query_ms' => 0.0];
            $started = hrtime(true);
            $recording = true;
            try {
                $result = $questions
                    ? app(AdminController::class)->index($request, 'questions')
                    : app(ParticipantController::class)->dashboard($request, app(AttemptService::class));
                if ($result instanceof JsonResponse) $encoded = $result->getContent();
                else $encoded = json_encode($result instanceof Arrayable ? $result->toArray() : $result, JSON_THROW_ON_ERROR);
                benchmarkAssert(is_string($encoded));
            } finally {
                $recording = false;
            }
            $current['wall_ms'] = (hrtime(true) - $started) / 1_000_000;
            if ($iteration > 0) $samples[] = $current;
        }
        $metrics = [];
        foreach (['wall_ms', 'query_count', 'sql_total_ms', 'sql_max_query_ms'] as $metric) $metrics[$metric] = benchmarkStats(array_column($samples, $metric));
        $report['operations'][$label] = ['status' => 'PASS', 'samples' => 5, 'excluded_warmups' => 1, 'metrics' => $metrics];
    }
    $report['status'] = in_array('BLOCKED', array_column($report['operations'], 'status'), true) ? 'BLOCKED' : 'PASS';
} catch (Throwable $exception) {
    // No SQL, bindings, database names, participant identities, or credentials.
    $report['status'] = 'FAIL';
    $report['reason'] = 'Guard or read-only controller benchmark failed; no benchmark response data was saved.';
    $report['failure'] = ['class' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine()];
    if ($exception instanceof \Illuminate\Database\QueryException) $report['failure']['sqlstate'] = $exception->errorInfo[0] ?? null;
} finally {
    if ($connection && $connection->transactionLevel() > 0) $connection->rollBack();
}
if ($root) file_put_contents($root.'/query-benchmark.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status'] === 'PASS' ? 0 : 1);
