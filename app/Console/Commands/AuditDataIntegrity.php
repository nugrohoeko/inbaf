<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\ExamAttempt;
use App\Services\CertificateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditDataIntegrity extends Command
{
    protected $signature = 'inbaf:audit-data {--json : Output aggregate evidence without personal data}';
    protected $description = 'Read-only audit of foreign keys, uniqueness, exam snapshots and final certificate consistency.';

    public function handle(): int
    {
        $tables = collect(Schema::getTables(Schema::getCurrentSchemaName()))->pluck('name')->sort()->values();
        $checks = []; $counts = []; $foreignKeys = 0; $uniqueIndexes = 0;
        $check = function (string $name, int $violations) use (&$checks): void {
            $checks[] = ['check' => $name, 'status' => $violations === 0 ? 'PASS' : 'FAIL', 'violations' => $violations];
        };

        // Query only. No repair, model events, certificate verification logs or PII are emitted.
        DB::transaction(function () use ($tables, &$counts, &$foreignKeys, &$uniqueIndexes, $check): void {
            foreach ($tables as $table) {
                $counts[$table] = DB::table($table)->count();
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    $foreignKeys++;
                    $query = DB::table($table.' as child')->leftJoin($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                        foreach ($foreign['columns'] as $index => $column) $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                    })->whereNull('parent.'.$foreign['foreign_columns'][0]);
                    foreach ($foreign['columns'] as $column) $query->whereNotNull('child.'.$column);
                    $check('foreign_key:'.$table.'.'.implode(',', $foreign['columns']), $query->count());
                }
                foreach (Schema::getIndexes($table) as $index) {
                    if (! $index['unique']) continue;
                    $uniqueIndexes++;
                    $query = DB::table($table)->select($index['columns'])->groupBy($index['columns'])->havingRaw('COUNT(*) > 1');
                    foreach ($index['columns'] as $column) $query->whereNotNull($column);
                    $check('unique:'.$table.'.'.implode(',', $index['columns']), DB::query()->fromSub($query, 'duplicates')->count());
                }
            }

            foreach (['users' => [['email']], 'exam_answers' => [['exam_attempt_id', 'question_key']], 'question_versions' => [['question_id', 'version']], 'question_usages' => [['exam_attempt_id', 'question_key']], 'certificates' => [['exam_attempt_id'], ['number'], ['token']]] as $table => $required) {
                $indexes = $tables->contains($table) ? Schema::getIndexes($table) : [];
                foreach ($required as $columns) {
                    $present = collect($indexes)->contains(fn ($index) => $index['unique'] && ! array_diff($columns, $index['columns']) && count($columns) === count($index['columns']));
                    $check('required_unique:'.$table.'.'.implode(',', $columns), $present ? 0 : 1);
                }
            }

            if (! $tables->contains('exam_attempts')) return;
            $invalidStates = $invalidScores = $invalidAnswers = $invalidTypes = $invalidAnswerReferences = $invalidUsageReferences = 0;
            ExamAttempt::with(['answers', 'questionUsages'])->orderBy('id')->chunk(100, function ($attempts) use (&$invalidStates, &$invalidScores, &$invalidAnswers, &$invalidTypes, &$invalidAnswerReferences, &$invalidUsageReferences): void {
                foreach ($attempts as $attempt) {
                    $result = $attempt->result ?? [];
                    if ($attempt->status === 'completed' && (! $attempt->submitted_at || $attempt->score === null || (int) ($result['manual_pending'] ?? 0) !== 0 || ! isset($result['total_score'], $result['level']))) $invalidStates++;
                    if ($attempt->status === 'pending_review' && ($attempt->score !== null || ! ($result['manual_pending'] ?? 0))) $invalidStates++;
                    if ($attempt->status === 'completed' && (abs((float) $attempt->score - (float) ($result['total_score'] ?? -1)) > 0.001 || $attempt->level !== ($result['level'] ?? null))) $invalidScores++;
                    $keys = []; $captured = [];
                    foreach ($attempt->snapshot['sections'] ?? [] as $section) foreach ($section['questions'] ?? [] as $question) {
                        $keys[] = $question['key'] ?? '';
                        $captured[$question['key'] ?? ''] = $question;
                    }
                    $answers = $attempt->answers->pluck('question_key')->all();
                    if (! $keys || count($keys) !== count(array_unique($keys)) || array_diff($keys, $answers) || array_diff($answers, $keys)) $invalidAnswers++;
                    if (($attempt->snapshot['test_type'] ?? $attempt->snapshot['package']['test_type'] ?? $attempt->test_type) !== $attempt->test_type) $invalidTypes++;
                    foreach ($attempt->answers as $answer) if ($answer->question_id !== null && (int) $answer->question_id !== (int) ($captured[$answer->question_key]['id'] ?? 0)) $invalidAnswerReferences++;
                    foreach ($attempt->questionUsages as $usage) {
                        $question = $captured[$usage->question_key] ?? null;
                        if (! $question || (int) $usage->question_version !== (int) ($question['version'] ?? 1) || $usage->test_type !== $attempt->test_type || ($usage->question_id !== null && (int) $usage->question_id !== (int) ($question['id'] ?? 0))) $invalidUsageReferences++;
                    }
                    $usageKeys = $attempt->questionUsages->pluck('question_key')->all();
                    if (array_diff($keys, $usageKeys) || array_diff($usageKeys, $keys)) $invalidUsageReferences++;
                }
            });
            $duplicateActive = DB::query()->fromSub(DB::table('exam_attempts')->where('status', 'in_progress')->select(['user_id', 'exam_package_id', 'test_type'])->groupBy(['user_id', 'exam_package_id', 'test_type'])->havingRaw('COUNT(*) > 1'), 'active_duplicates')->count();
            $check('attempt.finalization_state', $invalidStates);
            $check('attempt.result_score_and_level', $invalidScores);
            $check('attempt.snapshot_answer_keys', $invalidAnswers);
            $check('attempt.snapshot_test_type', $invalidTypes);
            $check('attempt.answer_question_reference', $invalidAnswerReferences);
            $check('attempt.usage_snapshot_reference', $invalidUsageReferences);
            $check('attempt.single_active_per_user_package_type', $duplicateActive);
            $check('assessment.answer_belongs_to_same_attempt', DB::table('manual_assessments as assessment')->join('exam_answers as answer', 'answer.id', '=', 'assessment.exam_answer_id')->whereColumn('assessment.exam_attempt_id', '<>', 'answer.exam_attempt_id')->count());
            $check('package.test_type_reference', DB::table('exam_packages as package')->leftJoin('test_types as type', 'type.code', '=', 'package.test_type')->whereNull('type.id')->count());
            $usageCounts = DB::table('question_usages')->whereNotNull('question_id')->selectRaw('question_id, COUNT(*) AS total')->groupBy('question_id')->pluck('total', 'question_id');
            $counterMismatches = DB::table('questions')->select(['id', 'usage_count'])->get()->filter(fn ($question) => (int) $question->usage_count !== (int) ($usageCounts[$question->id] ?? 0))->count();
            $check('question.usage_counter_matches_history', $counterMismatches);

            if (! $tables->contains('certificates')) return;
            $invalidCertificates = 0;
            Certificate::with('attempt')->orderBy('id')->chunk(100, function ($certificates) use (&$invalidCertificates): void {
                foreach ($certificates as $certificate) {
                    $attempt = $certificate->attempt;
                    $payload = $certificate->payload ?? [];
                    if (! app(CertificateService::class)->signatureIsValid($certificate) || ! $attempt || $attempt->status !== 'completed' || $attempt->test_type !== 'certification' || $certificate->user_id !== $attempt->user_id || abs((float) ($payload['total_score'] ?? -1) - (float) $attempt->score) > 0.001 || ($payload['level'] ?? null) !== $attempt->level) $invalidCertificates++;
                }
            });
            $check('certificate.signature_owner_final_score', $invalidCertificates);
        });

        $failed = count(array_filter($checks, fn ($row) => $row['status'] === 'FAIL'));
        $report = ['status' => $failed ? 'FAIL' : 'PASS', 'read_only' => true, 'driver' => DB::connection()->getDriverName(), 'tables' => $counts, 'foreign_keys_checked' => $foreignKeys, 'unique_indexes_checked' => $uniqueIndexes, 'checks_total' => count($checks), 'checks_failed' => $failed, 'checks' => $checks];
        if ($this->option('json')) $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        else {
            $this->table(['Check', 'Status', 'Violations'], array_map(fn ($row) => array_values($row), $checks));
            $this->line($report['status'].' — '.count($tables).' tables, '.count($checks).' checks. No data changed.');
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
