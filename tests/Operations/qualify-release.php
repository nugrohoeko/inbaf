<?php

/** Explicit opt-in operational qualification. Never drops a database or resets the live database. */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\FinalizeExpiredAttempt;
use App\Models\{AuditLog, Certificate, Competency, ExamAttempt, ExamPackage, Question, User};
use App\Services\{AttemptService, CertificateService, ScoringService};
use Illuminate\Support\Facades\{Artisan, DB, Storage};
use Symfony\Component\Process\{ExecutableFinder, Process};

function qaAssert(bool $condition, string $label): void
{
    if (! $condition) throw new RuntimeException($label);
}

function qaSwitch(string $name, string $privateRoot): void
{
    qaAssert((bool) preg_match('/^inbaf_qa_rc_[a-f0-9]{12}$/D', $name), 'Invalid isolated QA database name.');
    qaAssert($name !== config('database.connections.mysql.database'), 'QA database must differ from configured live database.');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name, 'database.connections.mysql.url' => null, 'app.env' => 'testing', 'app.debug' => false, 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array', 'queue.default' => 'database', 'filesystems.disks.local.root' => $privateRoot, 'hashing.bcrypt.rounds' => 4]);
    DB::purge('mysql');
    Storage::forgetDisk('local');
}

function qaFingerprint(): array
{
    $out = [];
    foreach (['users', 'participant_profiles', 'questions', 'question_versions', 'exam_packages', 'exam_sections', 'exam_attempts', 'exam_answers', 'manual_assessments', 'certificates', 'media_assets', 'system_settings'] as $table) {
        $out[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR));
    }
    return $out;
}

function qaBatch(array $jobs, array $context, string $root): array
{
    $batch = bin2hex(random_bytes(5)); $barrier = $root.'/barrier-'.$batch;
    $processes = [];
    foreach ($jobs as $index => $job) {
        $path = $root.'/worker-'.$batch.'-'.$index.'.json';
        file_put_contents($path, json_encode($context + $job + ['barrier' => $barrier], JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $path], base_path(), null, null, 120);
        $process->start();
        $processes[] = [$process, $path];
    }
    $deadline = microtime(true) + 60;
    do {
        $ready = count(array_filter($processes, fn ($entry) => is_file($entry[1].'.ready')));
        if ($ready === count($processes)) break;
        qaAssert(microtime(true) < $deadline, 'Workers failed to reach concurrency barrier.');
        usleep(50_000);
    } while (true);
    $started = microtime(true); touch($barrier); $out = [];
    foreach ($processes as [$process, $path]) {
        $process->wait();
        $result = json_decode($process->getOutput(), true);
        file_put_contents($path.'.result.json', json_encode(['exit_code' => $process->getExitCode(), 'result' => $result, 'error_class' => $process->getErrorOutput()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        qaAssert($process->isSuccessful() && is_array($result), 'QA worker failed; inspect private operational artifact.');
        $out[] = $result;
    }
    return ['elapsed_ms' => round((microtime(true) - $started) * 1000, 2), 'workers' => $out];
}

function qaStats(array $values): array
{
    sort($values); $count = count($values);
    return ['samples' => $count, 'min_ms' => round($values[0], 2), 'median_ms' => round($values[(int) floor(($count - 1) / 2)], 2), 'p95_ms' => round($values[(int) ceil($count * .95) - 1], 2), 'max_ms' => round($values[$count - 1], 2)];
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $path = realpath($argv[2] ?? '');
        $allowed = realpath(base_path('tmp/qa/final/operations'));
        qaAssert($path && $allowed && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $allowed).'/'), 'Invalid worker manifest.');
        $job = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        qaSwitch($job['database'], $job['private_root']);
        touch($path.'.ready');
        $deadline = microtime(true) + 70;
        while (! is_file($job['barrier'])) { qaAssert(microtime(true) < $deadline, 'Barrier timed out.'); usleep(10_000); }
        $started = microtime(true); $service = app(AttemptService::class); $value = null;
        try {
            if ($job['action'] === 'archive') {
                Illuminate\Support\Facades\Auth::setUser(User::findOrFail($job['reviewer']));
                app(App\Http\Controllers\AdminController::class)->destroy('questions', (string) $job['question']);
                $value = Question::findOrFail($job['question'])->version;
            }
            elseif ($job['action'] === 'start') $value = $service->start(User::findOrFail($job['user']), ExamPackage::findOrFail($job['package']))->id;
            else {
                $attempt = ExamAttempt::findOrFail($job['attempt']);
                if ($job['action'] === 'answer') {
                    foreach ($attempt->snapshot['sections'] as $section) foreach ($section['questions'] as $question) $service->save($attempt, $question['key'], 'a', true);
                } elseif ($job['action'] === 'submit') $value = $service->submit($attempt)->id;
                elseif ($job['action'] === 'issue') $value = app(CertificateService::class)->issue($attempt)?->id;
                elseif ($job['action'] === 'grade') {
                    Illuminate\Support\Facades\Auth::setUser(User::findOrFail($job['reviewer']));
                    foreach ($attempt->answers as $answer) {
                        $request = Illuminate\Http\Request::create('/api/admin/reviews/'.$answer->id, 'POST', ['score' => 80, 'feedback' => 'Synthetic operational QA rubric', 'scores' => ['Content' => 80]]);
                        $request->setUserResolver(fn () => User::findOrFail($job['reviewer']));
                        app(App\Http\Controllers\AdminController::class)->assess($request, (string) $answer->id);
                    }
                } else throw new RuntimeException('Unknown QA action.');
            }
            echo json_encode(['ok' => true, 'value' => $value, 'ms' => (microtime(true) - $started) * 1000], JSON_THROW_ON_ERROR);
        } catch (Illuminate\Validation\ValidationException $exception) {
            echo json_encode(['ok' => false, 'validation' => true, 'ms' => (microtime(true) - $started) * 1000], JSON_THROW_ON_ERROR);
        }
        exit(0);
    } catch (Throwable $exception) {
        // Keep SQL, credentials, identities and certificate tokens out of stdout/reports.
        $table = null; $constraint = null;
        if ($exception instanceof Illuminate\Database\QueryException) {
            preg_match('/insert\s+into\s+[`"]?([a-z_]+)/i', $exception->getSql(), $tableMatch);
            preg_match("/for key '([a-zA-Z0-9_.]+)'/", $exception->getMessage(), $constraintMatch);
            $table = $tableMatch[1] ?? null; $constraint = $constraintMatch[1] ?? null;
        }
        if (isset($path) && $path) file_put_contents($path.'.error.json', json_encode(['exception' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine(), 'code' => $exception->getCode(), 'driver_code' => $exception instanceof Illuminate\Database\QueryException ? ($exception->errorInfo[1] ?? null) : null, 'table' => $table, 'constraint' => $constraint], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fwrite(STDERR, get_class($exception).': operational qualification failed.');
        exit(1);
    }
}

if (($argv[1] ?? '') !== '--run') {
    echo "Usage: php tests/Operations/qualify-release.php --run\nCreates a private backup and a NEW isolated MySQL QA database; never resets or drops the configured database.\n";
    exit(0);
}

$report = ['scope' => 'Local MySQL, 10 separate PHP processes; service timings exclude HTTP transport. Not a production capacity certification.', 'checks' => [], 'metrics' => []];
$root = base_path('tmp/qa/final/operations');
if (! is_dir($root)) mkdir($root, 0700, true);
$mark = function (string $name, bool $passed) use (&$report): void { $report['checks'][] = ['check' => $name, 'status' => $passed ? 'PASS' : 'FAIL']; qaAssert($passed, $name); };
try {
    qaAssert(config('database.default') === 'mysql' && ! app()->configurationIsCached(), 'Use uncached local MySQL configuration.');
    $original = config('database.connections.mysql');
    $original['database'] = DB::connection()->getDatabaseName();
    $report['mysql_version'] = DB::selectOne('SELECT VERSION() AS version')->version;
    $before = qaFingerprint();
    $started = microtime(true);
    $mark('live_database_private_backup', Artisan::call('inbaf:backup') === 0);
    $backup = trim(substr(trim(Artisan::output()), strlen('Private backup created: ')));
    qaAssert(is_file($backup) && filesize($backup) > 0, 'Missing private SQL backup.');
    $report['metrics']['backup_ms'] = round((microtime(true) - $started) * 1000, 2);
    $database = 'inbaf_qa_rc_'.bin2hex(random_bytes(6));
    qaAssert($database !== $original['database'], 'Refusing live database target.');
    DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $mysql = (new ExecutableFinder)->find('mysql');
    qaAssert((bool) $mysql, 'mysql executable unavailable.');
    $restore = new Process([$mysql, '--host='.$original['host'], '--port='.$original['port'], '--user='.$original['username'], '--database='.$database], base_path(), ['MYSQL_PWD' => $original['password']], file_get_contents($backup), 120);
    $started = microtime(true); $restore->run();
    $mark('restore_into_new_isolated_database', $restore->isSuccessful());
    $report['metrics']['restore_ms'] = round((microtime(true) - $started) * 1000, 2);
    $private = $root.'/private-'.$database;
    $source = Storage::disk('local'); $mediaCount = 0;
    foreach (DB::table('media_assets')->pluck('path') as $path) {
        if (! $source->exists($path)) continue;
        qaAssert(! str_contains($path, '..') && ! str_contains($path, ':'), 'Unsafe media path.');
        $target = $private.'/'.$path;
        if (! is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($source->path($path), $target);
        qaAssert(hash_file('sha256', $source->path($path)) === hash_file('sha256', $target), 'Media restore mismatch.');
        $mediaCount++;
    }
    qaSwitch($database, $private);
    $mark('restored_domain_table_fingerprints_match', $before === qaFingerprint());
    $mark('media_restore_hashes_match', $mediaCount === DB::table('media_assets')->count());
    $report['media_restored'] = $mediaCount;
    $mark('migrate_on_restored_copy', Artisan::call('migrate', ['--force' => true]) === 0);
    $mark('restored_copy_integrity', Artisan::call('inbaf:audit-data', ['--json' => true]) === 0);
    file_put_contents($root.'/restored-integrity.json', Artisan::output());

    $competency = Competency::create(['code' => 'qa-'.bin2hex(random_bytes(4)), 'name' => 'QA Reading', 'name_en' => 'QA Reading', 'active' => true]);
    $questions = [];
    for ($i = 0; $i < 2; $i++) $questions[] = Question::create(['code' => 'QA-'.bin2hex(random_bytes(6)), 'competency_id' => $competency->id, 'type' => 'single_choice', 'title' => 'QA objective', 'prompt' => 'Select A', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'answer_schema' => ['correct' => 'a'], 'status' => 'active', 'weight' => $i + 1, 'version' => 1, 'allowed_test_types' => ['practice', 'placement', 'certification']]);
    $package = ExamPackage::create(['code' => 'QA-'.bin2hex(random_bytes(6)), 'name' => 'QA Certification', 'test_type' => 'certification', 'published' => true, 'duration_minutes' => 60, 'certificate_enabled' => true, 'pass_score' => 60, 'question_selection' => 'manual', 'policy_overrides' => ['timer_enabled' => false, 'lock_sections' => false, 'attempts_limit' => 1, 'require_fullscreen' => false]]);
    $package->sections()->create(['competency_id' => $competency->id, 'title' => 'QA Reading', 'question_count' => 2, 'question_ids' => array_map(fn ($q) => $q->id, $questions), 'duration_minutes' => 60, 'weight' => 1, 'randomize' => false]);
    $users = [];
    for ($i = 0; $i < 20; $i++) $users[] = User::create(['name' => 'Synthetic QA '.($i + 1), 'email' => 'qa-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(24)), 'role' => 'participant', 'active' => true]);
    $context = ['database' => $database, 'private_root' => $private];
    $parallel = qaBatch(array_fill(0, 10, ['action' => 'start', 'user' => $users[0]->id, 'package' => $package->id]), $context, $root);
    $ids = array_column($parallel['workers'], 'value');
    $mark('ten_concurrent_starts_return_one_logical_attempt', count(array_unique($ids)) === 1 && count(array_filter($parallel['workers'], fn ($r) => $r['ok'])) === 10);
    $first = ExamAttempt::findOrFail($ids[0]);
    $mark('concurrent_start_creates_one_answer_set_and_one_audit', $first->answers()->count() === 2 && AuditLog::where('event', 'attempt.started')->where('subject_id', $first->id)->count() === 1);
    $report['metrics']['concurrent_same_user_start'] = qaStats(array_column($parallel['workers'], 'ms'));
    $startResults = [];
    foreach (array_chunk($users, 10) as $chunk) $startResults = [...$startResults, ...qaBatch(array_map(fn ($u) => ['action' => 'start', 'user' => $u->id, 'package' => $package->id], $chunk), $context, $root)['workers']];
    $mark('twenty_participants_start_in_parallel', count(array_filter($startResults, fn ($r) => $r['ok'])) === 20);
    $attemptIds = array_column($startResults, 'value');
    $answerResults = [];
    foreach (array_chunk($attemptIds, 10) as $chunk) $answerResults = [...$answerResults, ...qaBatch(array_map(fn ($id) => ['action' => 'answer', 'attempt' => $id], $chunk), $context, $root)['workers']];
    $repeat = qaBatch(array_fill(0, 10, ['action' => 'answer', 'attempt' => $first->id]), $context, $root);
    $mark('parallel_autosaves_and_repeats_preserve_exactly_two_answers', count(array_filter($answerResults, fn ($r) => $r['ok'])) === 20 && $first->answers()->count() === 2 && $first->answers()->whereNotNull('response')->count() === 2 && count(array_filter($repeat['workers'], fn ($r) => $r['ok'])) === 10);
    $report['metrics']['parallel_save_two_answers'] = qaStats(array_column($answerResults, 'ms'));
    $submitResults = [];
    foreach (array_chunk($attemptIds, 10) as $chunk) $submitResults = [...$submitResults, ...qaBatch(array_map(fn ($id) => ['action' => 'submit', 'attempt' => $id], $chunk), $context, $root)['workers']];
    $repeat = qaBatch(array_fill(0, 10, ['action' => 'submit', 'attempt' => $first->id]), $context, $root);
    $mark('parallel_scoring_and_double_submit_are_final_and_idempotent', count(array_filter($submitResults, fn ($r) => $r['ok'])) === 20 && ExamAttempt::whereIn('id', $attemptIds)->where('status', 'completed')->where('score', 100)->count() === 20 && AuditLog::where('event', 'attempt.graded')->where('subject_id', $first->id)->count() === 1 && count(array_filter($repeat['workers'], fn ($r) => $r['ok'])) === 10);
    $report['metrics']['parallel_submit_score_issue'] = qaStats(array_column($submitResults, 'ms'));
    $repeat = qaBatch(array_fill(0, 10, ['action' => 'issue', 'attempt' => $first->id]), $context, $root);
    $mark('certificate_batch_and_concurrent_reissue_are_unique', Certificate::whereIn('exam_attempt_id', $attemptIds)->count() === 20 && count(array_unique(array_column($repeat['workers'], 'value'))) === 1 && AuditLog::where('event', 'certificate.issued')->where('subject_id', (string) $first->fresh()->certificate->id)->count() === 1);

    // Competing participants cannot exceed a question's usage cap.
    $cappedQuestion = $questions[0]->replicate(); $cappedQuestion->code = 'QA-'.bin2hex(random_bytes(6)); $cappedQuestion->usage_count = 0; $cappedQuestion->usage_limit = 3; $cappedQuestion->save();
    $cappedPackage = $package->replicate(); $cappedPackage->code = 'QA-'.bin2hex(random_bytes(6)); $cappedPackage->save();
    $cappedPackage->sections()->create(['competency_id' => $competency->id, 'title' => 'QA capped', 'question_count' => 1, 'question_ids' => [$cappedQuestion->id], 'duration_minutes' => 60, 'weight' => 1, 'randomize' => false]);
    $capped = qaBatch(array_map(fn ($u) => ['action' => 'start', 'user' => $u->id, 'package' => $cappedPackage->id], array_slice($users, 0, 10)), $context, $root);
    $mark('ten_competing_participants_obey_three_use_question_cap', count(array_filter($capped['workers'], fn ($r) => $r['ok'])) === 3 && $cappedQuestion->fresh()->usage_count === 3 && ExamAttempt::where('exam_package_id', $cappedPackage->id)->count() === 3);

    // Multiple real manual-review controller calls contend for the same attempt lock.
    $reviewer = User::create(['name' => 'Synthetic QA Reviewer', 'email' => 'qa-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(24)), 'role' => 'superadmin', 'active' => true]);
    $manualQuestion = $questions[0]->replicate(); $manualQuestion->code = 'QA-'.bin2hex(random_bytes(6)); $manualQuestion->type = 'essay'; $manualQuestion->answer_schema = []; $manualQuestion->metadata = ['rubric' => ['Content' => 1]]; $manualQuestion->usage_count = 0; $manualQuestion->save();
    $secondManualQuestion = $manualQuestion->replicate(); $secondManualQuestion->code = 'QA-'.bin2hex(random_bytes(6)); $secondManualQuestion->save();
    $manualPackage = $package->replicate(); $manualPackage->code = 'QA-'.bin2hex(random_bytes(6)); $manualPackage->save();
    $manualPackage->sections()->create(['competency_id' => $competency->id, 'title' => 'QA manual', 'question_count' => 2, 'question_ids' => [$manualQuestion->id, $secondManualQuestion->id], 'duration_minutes' => 60, 'weight' => 1, 'randomize' => false]);
    $manualIds = [];
    foreach (array_slice($users, 0, 6) as $user) {
        $manual = app(AttemptService::class)->start($user, $manualPackage);
        foreach ($manual->snapshot['sections'][0]['questions'] as $question) app(AttemptService::class)->save($manual, $question['key'], 'Synthetic essay response');
        app(AttemptService::class)->submit($manual);
        $manualIds[] = $manual->id;
    }
    $gradeJobs = array_map(fn ($id) => ['action' => 'grade', 'attempt' => $id, 'reviewer' => $reviewer->id], [...$manualIds, ...array_fill(0, 4, $manualIds[0])]);
    $graded = qaBatch($gradeJobs, $context, $root);
    $mark('parallel_manual_review_and_replayed_review_are_idempotent', count(array_filter($graded['workers'], fn ($r) => $r['ok'])) === 10 && ExamAttempt::whereIn('id', $manualIds)->where('status', 'completed')->where('score', 80)->count() === 6 && DB::table('manual_assessments')->whereIn('exam_attempt_id', $manualIds)->count() === 12 && Certificate::whereIn('exam_attempt_id', $manualIds)->count() === 6);
    $report['metrics']['parallel_manual_review'] = qaStats(array_column($graded['workers'], 'ms'));

    // Inject a failure after question locking/increment: the transaction must undo every partial write.
    $beforeUsage = array_map(fn ($q) => $q->fresh()->usage_count, $questions);
    $beforeAttempts = ExamAttempt::count(); $beforeAnswers = DB::table('exam_answers')->count();
    $injected = false;
    $originalDispatcher = ExamAttempt::getEventDispatcher();
    ExamAttempt::setEventDispatcher(clone $originalDispatcher);
    ExamAttempt::creating(function () use (&$injected): void { $injected = true; throw new RuntimeException('QA injected transaction failure'); });
    $failureUser = User::create(['name' => 'Synthetic QA Rollback', 'email' => 'qa-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(24)), 'role' => 'participant', 'active' => true]);
    try { app(AttemptService::class)->start($failureUser, $package); }
    catch (RuntimeException $exception) { qaAssert($exception->getMessage() === 'QA injected transaction failure', 'Unexpected rollback failure.'); }
    finally { ExamAttempt::setEventDispatcher($originalDispatcher); }
    $mark('injected_failure_rolls_back_attempt_answers_and_usage', $injected && $beforeAttempts === ExamAttempt::count() && $beforeAnswers === DB::table('exam_answers')->count() && $beforeUsage === array_map(fn ($q) => $q->fresh()->usage_count, $questions));

    // Real queue delivery and duplicate jobs, on the isolated DB and a dedicated queue.
    $practice = $package->replicate(); $practice->code = 'QA-'.bin2hex(random_bytes(6)); $practice->name = 'QA Queue'; $practice->test_type = 'practice'; $practice->certificate_enabled = false; $practice->save();
    $section = $package->sections()->first()->replicate(); $section->exam_package_id = $practice->id; $section->save();
    $expired = app(AttemptService::class)->start($users[0], $practice);
    app(AttemptService::class)->save($expired, $expired->snapshot['sections'][0]['questions'][0]['key'], 'a');
    $expired->update(['expires_at' => now()->subMinute()]);
    FinalizeExpiredAttempt::dispatch($expired->id)->onQueue('final-qa');
    FinalizeExpiredAttempt::dispatch($expired->id)->onQueue('final-qa');
    $mark('queue_worker_processes_duplicate_expiry_jobs', Artisan::call('queue:work', ['--queue' => 'final-qa', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3]) === 0 && $expired->fresh()->status === 'completed' && DB::table('jobs')->where('queue', 'final-qa')->count() === 0 && AuditLog::where('event', 'attempt.graded')->where('subject_id', $expired->id)->count() === 1);
    $archived = qaBatch(array_fill(0, 10, ['action' => 'archive', 'question' => $questions[0]->id, 'reviewer' => $reviewer->id]), $context, $root);
    $mark('concurrent_question_archive_is_one_version_and_audit', count(array_filter($archived['workers'], fn ($r) => $r['ok'])) === 10 && $questions[0]->fresh()->version === 2 && $questions[0]->fresh()->status === 'archived' && DB::table('question_versions')->where('question_id', $questions[0]->id)->count() === 2 && AuditLog::where('event', 'questions.archived')->where('subject_id', (string) $questions[0]->id)->count() === 1);
    $mark('post_concurrency_integrity', Artisan::call('inbaf:audit-data', ['--json' => true]) === 0);
    file_put_contents($root.'/post-concurrency-integrity.json', Artisan::output());
    config(['database.connections.mysql' => $original]); DB::purge('mysql');
    $mark('live_domain_data_unchanged_after_entire_exercise', $before === qaFingerprint());
    $report['status'] = 'PASS';
    $report['isolated_database_retained'] = true;
} catch (Throwable $exception) {
    $report['status'] = 'FAIL';
    $report['failure'] = $exception instanceof RuntimeException ? $exception->getMessage() : get_class($exception);
}
file_put_contents($root.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($report['status'] === 'PASS' ? 0 : 1);
