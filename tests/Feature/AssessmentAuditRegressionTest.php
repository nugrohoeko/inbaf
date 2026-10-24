<?php

namespace Tests\Feature;

use App\Models\{ExamAttempt, ExamPackage, User};
use App\Services\AttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_compares_mixed_scales_within_one_type_and_keeps_original_result_scores(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $user = User::factory()->create(['active' => true]);
        $package = $this->package('certification');
        $this->attempt($user, $package, 40, ['score_max' => 50], now()->subMinutes(3));
        $this->attempt($user, $package, 40, [], now()->subMinutes(2));
        $latest = $this->attempt($user, $package, 450, ['normalized_score' => 75, 'score_max' => 600], now()->subMinute());
        $this->attempt($user, $this->package('placement'), 100, ['score_max' => 100], now());
        $this->attempt(User::factory()->create(), $package, 100, ['score_max' => 100], now());
        $pending = $this->attempt($user, $package, 100, ['score_max' => 100], now());
        $pending->update(['status' => 'pending_review']);

        $response = $this->actingAs($user)->getJson('/api/dashboard?test_type=certification')->assertOk()
            ->assertJsonPath('stats.total_attempts', 4)->assertJsonPath('stats.completed_attempts', 3)
            ->assertJsonPath('stats.average_score', 65)->assertJsonPath('stats.best_score', 80)
            ->assertJsonPath('stats.aggregate_score_max', 100)->assertJsonPath('stats.latest_score', 450)
            ->assertJsonPath('stats.latest_score_max', 600)->assertJsonPath('stats.score_max', 600)
            ->assertJsonPath('trend.5.average_score', 65)->assertJsonPath('monthly_stats.5.average_score', 65);
        $recent = collect($response->json('recent_attempts'))->firstWhere('id', $latest->id);
        $this->assertSame(450, $recent['score']);
        $this->assertSame(600, $recent['result']['score_max']);
        $this->getJson('/api/results/'.$latest->id)->assertOk()->assertJsonPath('attempt.score', 450)->assertJsonPath('result.score_max', 600);
        $this->travelBack();
    }

    public function test_normalized_dashboard_aggregates_honor_type_package_and_date_filters(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
        $user = User::factory()->create(['active' => true]);
        $first = $this->package('certification');
        $second = $this->package('certification');
        $this->attempt($user, $first, 40, ['score_max' => 50], now()->subMonth());
        $this->attempt($user, $second, 450, ['normalized_score' => 75, 'score_max' => 600], now());
        $this->attempt($user, $this->package('practice'), 1000, ['score_max' => 1000], now());

        $this->actingAs($user)->getJson('/api/dashboard?test_type=certification')->assertOk()
            ->assertJsonPath('stats.average_score', 77.5)->assertJsonPath('stats.best_score', 80)
            ->assertJsonPath('trend.4.average_score', 80)->assertJsonPath('trend.5.average_score', 75);
        $query = http_build_query(['test_type' => 'certification', 'package_id' => $second->id, 'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString()]);
        $this->getJson('/api/dashboard?'.$query)->assertOk()
            ->assertJsonPath('stats.completed_attempts', 1)->assertJsonPath('stats.average_score', 75)
            ->assertJsonPath('stats.best_score', 75)->assertJsonPath('trend.4.average_score', 0)
            ->assertJsonPath('trend.5.average_score', 75);
        $this->getJson('/api/dashboard?test_type=placement')->assertOk()
            ->assertJsonPath('stats.average_score', 0)->assertJsonPath('stats.best_score', 0)
            ->assertJsonPath('stats.aggregate_score_max', 100)->assertJsonPath('stats.latest_score', null);
        $this->travelBack();
    }

    public function test_review_returns_snapshotted_answer_labels_with_sanitized_metadata_only_when_allowed(): void
    {
        $user = User::factory()->create(['active' => true]);
        $package = $this->package('practice');
        $attempt = $this->attempt($user, $package, 100, ['score_max' => 100], now());
        $questions = [
            ['id' => 1, 'key' => 'matching', 'type' => 'matching', 'prompt' => 'Match the profession.', 'options' => [], 'answer_schema' => ['correct' => ['left-1' => 'right-1']], 'metadata' => [
                'left_items' => [['id' => 'left-1', 'text' => 'Dokter', 'match_id' => 'SECRET_NESTED_KEY']],
                'right_items' => [['id' => 'right-1', 'text' => 'Rumah sakit', 'solution' => 'SECRET_NESTED_KEY']],
                'internal_notes' => 'SECRET_INTERNAL_NOTES',
            ]],
            ['id' => 2, 'key' => 'blank', 'type' => 'dropdown', 'prompt' => 'Saya [b1] Jakarta.', 'options' => [], 'answer_schema' => ['correct' => ['b1' => 'option-di']], 'metadata' => [
                'blanks' => [['id' => 'b1', 'label' => 'Kata depan', 'correct' => 'SECRET_NESTED_KEY', 'options' => [['id' => 'option-di', 'text' => 'di', 'is_correct' => 'SECRET_NESTED_KEY']]]],
            ]],
            ['id' => 3, 'key' => 'secret', 'type' => 'single_choice', 'prompt' => 'SECRET_CONFIDENTIAL_QUESTION', 'confidential' => true, 'metadata' => ['note' => 'SECRET_CONFIDENTIAL_METADATA']],
            ['id' => 4, 'key' => 'manual', 'type' => 'essay', 'metadata' => ['manual' => true]],
        ];
        $attempt->update(['snapshot' => ['test_type' => 'practice', 'package' => ['id' => $package->id, 'name' => $package->name, 'test_type' => 'practice'], 'rules' => ['show_review' => true], 'sections' => [['questions' => $questions]]]]);
        $attempt->answers()->create(['question_key' => 'matching', 'response' => ['left-1' => 'right-1']]);
        $attempt->answers()->create(['question_key' => 'blank', 'response' => ['b1' => 'option-di']]);

        $this->actingAs($user)->getJson('/api/results/'.$attempt->id)->assertOk()->assertJsonCount(3, 'review_answers')
            ->assertJsonPath('review_answers.0.metadata.left_items.0.text', 'Dokter')
            ->assertJsonPath('review_answers.0.metadata.right_items.0.text', 'Rumah sakit')
            ->assertJsonPath('review_answers.1.metadata.blanks.0.label', 'Kata depan')
            ->assertJsonPath('review_answers.1.metadata.blanks.0.options.0.text', 'di')
            ->assertJsonPath('review_answers.2.metadata.manual', true)
            ->assertDontSee('SECRET_NESTED_KEY')->assertDontSee('SECRET_INTERNAL_NOTES')
            ->assertDontSee('SECRET_CONFIDENTIAL_QUESTION')->assertDontSee('SECRET_CONFIDENTIAL_METADATA');
        $snapshot = $attempt->snapshot;
        $snapshot['rules']['show_review'] = false;
        $attempt->update(['snapshot' => $snapshot]);
        $this->assertSame([], app(AttemptService::class)->review($attempt));
        $snapshot['rules']['show_review'] = true;
        $attempt->update(['snapshot' => $snapshot, 'status' => 'pending_review']);
        $this->assertSame([], app(AttemptService::class)->review($attempt));
    }

    private function package(string $type): ExamPackage
    {
        return ExamPackage::create(['code' => 'AUDIT-'.Str::random(8), 'name' => 'Audit '.$type, 'test_type' => $type, 'policy_overrides' => [], 'published' => false, 'certificate_enabled' => false]);
    }

    private function attempt(User $user, ExamPackage $package, float $score, array $result, mixed $started): ExamAttempt
    {
        return ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => $package->test_type, 'status' => 'completed', 'score' => $score, 'level' => 'B2', 'started_at' => $started, 'submitted_at' => $started->copy()->addSeconds(1), 'result' => $result + ['competency_scores' => []], 'snapshot' => ['test_type' => $package->test_type, 'package' => ['id' => $package->id, 'name' => $package->name, 'test_type' => $package->test_type], 'sections' => [], 'rules' => []]]);
    }
}
