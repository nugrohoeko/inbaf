<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\ExamAttempt;
use App\Models\ExamPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_schema_passes_without_mutation_or_disclosing_personal_data(): void
    {
        $user = User::factory()->create(['email' => 'private-audit@example.test']);
        $before = DB::table('users')->get()->toJson();
        $mutations = [];
        DB::listen(function ($query) use (&$mutations): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $query->sql)) $mutations[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PASS', $report['status']);
        $this->assertTrue($report['read_only']);
        $this->assertGreaterThan(60, $report['checks_total']);
        $this->assertStringNotContainsString($user->email, $output);
        $this->assertSame([], $mutations);
        $this->assertSame($before, DB::table('users')->get()->toJson());
    }

    public function test_historical_snapshot_and_score_corruption_is_reported_without_repair(): void
    {
        $user = User::factory()->create();
        $package = ExamPackage::create(['code' => 'AUDIT', 'name' => 'Audit', 'published' => true]);
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => 'completed', 'started_at' => now(), 'expires_at' => null, 'submitted_at' => now(), 'score' => 75, 'level' => 'C1', 'result' => ['total_score' => 90, 'level' => 'C2', 'manual_pending' => 0], 'snapshot' => ['sections' => [['questions' => [['key' => 'missing']]]]]]);
        $this->assertSame(1, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $checks = collect($report['checks'])->keyBy('check');
        $this->assertSame('FAIL', $checks['attempt.result_score_and_level']['status']);
        $this->assertSame('FAIL', $checks['attempt.snapshot_answer_keys']['status']);
        $this->assertSame(75.0, $attempt->fresh()->score);
        $this->assertDatabaseCount('exam_answers', 0);
    }

    public function test_parallel_active_attempt_duplicates_are_detected_without_deletion(): void
    {
        $user = User::factory()->create();
        $package = ExamPackage::create(['code' => 'DUP', 'name' => 'Duplicate Audit']);
        for ($i = 0; $i < 2; $i++) ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => null, 'snapshot' => ['sections' => []]]);
        $this->assertSame(1, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('check');
        $this->assertSame(1, $checks['attempt.single_active_per_user_package_type']['violations']);
        $this->assertDatabaseCount('exam_attempts', 2);
    }

    public function test_cross_attempt_manual_assessment_is_detected_even_when_both_foreign_keys_exist(): void
    {
        $user = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'superadmin']);
        $package = ExamPackage::create(['code' => 'CROSS', 'name' => 'Cross reference', 'test_type' => 'practice']);
        $data = ['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => 'pending_review', 'started_at' => now(), 'expires_at' => null, 'result' => ['manual_pending' => 1], 'snapshot' => ['sections' => [['questions' => [['key' => 'essay']]]]]];
        $first = ExamAttempt::create($data); $second = ExamAttempt::create($data);
        $answer = $first->answers()->create(['question_key' => 'essay', 'requires_manual' => true]);
        DB::table('manual_assessments')->insert(['exam_attempt_id' => $second->id, 'exam_answer_id' => $answer->id, 'reviewer_id' => $reviewer->id, 'scores' => '{}', 'score' => 80, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('check');
        $this->assertSame(1, $checks['assessment.answer_belongs_to_same_attempt']['violations']);
        $this->assertDatabaseCount('manual_assessments', 1);
    }

    public function test_missing_usage_is_detected_even_if_the_usage_counter_has_been_adjusted(): void
    {
        $user = User::factory()->create();
        $competency = Competency::create(['code' => 'USAGE', 'name' => 'Reading', 'name_en' => 'Reading']);
        $question = \App\Models\Question::create(['code' => 'USAGE-Q', 'title' => 'Usage reference', 'competency_id' => $competency->id, 'type' => 'single_choice', 'prompt' => 'Select A', 'status' => 'active', 'allowed_test_types' => ['practice'], 'usage_count' => 1]);
        $package = ExamPackage::create(['code' => 'USAGE', 'name' => 'Usage audit', 'test_type' => 'practice']);
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => null, 'snapshot' => ['test_type' => 'practice', 'sections' => [['questions' => [['id' => $question->id, 'key' => 'captured', 'version' => 1]]]]]]);
        $attempt->answers()->create(['question_id' => $question->id, 'question_key' => 'captured']);
        $attempt->questionUsages()->create(['question_id' => $question->id, 'question_key' => 'captured', 'question_version' => 1, 'test_type' => 'practice', 'used_at' => now()]);
        $this->assertSame(0, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $attempt->questionUsages()->delete(); $question->update(['usage_count' => 0]);
        $this->assertSame(1, Artisan::call('inbaf:audit-data', ['--json' => true]));
        $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('check');
        $this->assertSame('FAIL', $checks['attempt.usage_snapshot_reference']['status']);
        $this->assertSame('PASS', $checks['question.usage_counter_matches_history']['status']);
        $this->assertDatabaseCount('exam_answers', 1);
    }
}
