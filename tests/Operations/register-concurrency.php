<?php

/** Explicit isolated MySQL registration race; never creates, resets, or drops the configured database. */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AuthController;
use App\Models\{AuditLog, SystemSetting, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

function registrationAssert(bool $condition): void
{
    if (! $condition) throw new RuntimeException('Registration concurrency qualification failed.');
}

function registrationManifest(string $path): array
{
    $path = realpath($path);
    $root = realpath(base_path('tmp/qa/final/operations'));
    registrationAssert($path !== false && $root !== false && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/'));
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $name = $manifest['database'] ?? '';
    registrationAssert((bool) preg_match('/^inbaf_qa_rc_[a-f0-9]{12}$/D', $name) && $name !== DB::connection()->getDatabaseName());
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name, 'database.connections.mysql.url' => null, 'app.env' => 'testing', 'app.debug' => false, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
    DB::purge('mysql');

    return $manifest;
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $manifest = registrationManifest($argv[2] ?? '');
        $released = false;
        // Force all ten requests beyond the same successful unique check before
        // any insert. This reliably exercises the database-constraint race.
        DB::listen(function ($query) use ($manifest, &$released): void {
            if ($released || ! str_contains(strtolower($query->sql), 'count(*)') || ! in_array($manifest['email'], $query->bindings, true)) return;
            $released = true;
            touch($manifest['ready']);
            $deadline = microtime(true) + 60;
            while (! is_file($manifest['barrier'])) {
                registrationAssert(microtime(true) < $deadline);
                usleep(10_000);
            }
        });
        $request = Request::create('/api/register', 'POST', ['name' => 'Synthetic registration QA', 'email' => $manifest['email'], 'password' => $manifest['password'], 'password_confirmation' => $manifest['password']]);
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        app()->instance('request', $request);
        Auth::forgetGuards();
        $started = microtime(true);
        try {
            $response = app(AuthController::class)->register($request);
            $status = $response->getStatusCode();
        } catch (ValidationException $exception) {
            $status = 422;
        } catch (Throwable $exception) {
            // Do not print SQL, participant identities, credentials, or tokens.
            $status = 500;
        }
        echo json_encode(['status' => $status, 'ms' => round((microtime(true) - $started) * 1000, 2)], JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Guarded registration worker failed.');
        exit(1);
    }
}

if (($argv[1] ?? '') !== '--run' || empty($argv[2])) {
    echo "Usage: php tests/Operations/register-concurrency.php --run <existing guarded QA worker manifest>\n";
    exit(0);
}

try {
    $manifest = registrationManifest($argv[2]);
    $root = base_path('tmp/qa/final/operations');
    $batch = bin2hex(random_bytes(6));
    $barrier = $root.'/registration-'.$batch.'.release';
    $email = 'qa-register-'.$batch.'@example.test';
    $password = bin2hex(random_bytes(16)).'Aa1!';
    SystemSetting::updateOrCreate(['key' => 'registration_enabled'], ['value' => true]);
    $jobs = [];
    for ($i = 0; $i < 10; $i++) {
        $path = $root.'/registration-'.$batch.'-'.$i.'.json';
        $ready = $path.'.ready';
        file_put_contents($path, json_encode(['database' => $manifest['database'], 'private_root' => $manifest['private_root'], 'email' => $email, 'password' => $password, 'barrier' => $barrier, 'ready' => $ready], JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $path], base_path(), null, null, 90);
        $process->start();
        $jobs[] = [$process, $ready];
    }
    $deadline = microtime(true) + 60;
    while (count(array_filter($jobs, fn ($job) => is_file($job[1]))) !== 10) {
        registrationAssert(microtime(true) < $deadline);
        usleep(20_000);
    }
    touch($barrier);
    $responses = [];
    foreach ($jobs as [$process]) {
        $process->wait();
        registrationAssert($process->isSuccessful());
        $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $user = User::where('email', $email)->first();
    $counts = array_count_values(array_column($responses, 'status'));
    $checks = [
        'one_created_and_nine_validation_responses' => ($counts[201] ?? 0) === 1 && ($counts[422] ?? 0) === 9 && ($counts[500] ?? 0) === 0,
        'one_user_profile_and_registration_audit' => User::where('email', $email)->count() === 1 && $user?->profile()->count() === 1 && AuditLog::where('user_id', $user?->id)->where('event', 'auth.registered')->count() === 1,
    ];
    $report = ['scope' => 'Ten PHP processes call the real registration controller after a synchronized unique-validation query on a pre-existing isolated MySQL QA copy. HTTP transport is excluded.', 'status_counts' => $counts, 'checks' => array_map(fn ($name, $passed) => ['check' => $name, 'status' => $passed ? 'PASS' : 'FAIL'], array_keys($checks), array_values($checks)), 'responses' => $responses];
    file_put_contents($root.'/registration-concurrency-latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Guarded registration qualification failed. No configured live database was modified.');
    exit(1);
}
