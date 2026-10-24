<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamAttempt, ExamPackage, ExamSection, Question, QuestionUsage, User};
use App\Services\{AttemptService, ScoringService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestPolicyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoring_methods_produce_distinct_totals_and_consistent_competency_contributions(): void
    {
        [$user, $package] = $this->fixture();
        foreach ([
            'weighted_competency' => [70, 25, 40],
            'weighted_questions' => [83.33, 50, 33.33],
            'equal_competency' => [62.5, 25, 50],
        ] as $method => [$expected, $firstCompetencyScore, $firstCompetencyWeight]) {
            $sections = [];
            foreach ([[1, 1, 1], [1, 3, 1], [2, 6, 4]] as $index => [$competency, $sectionWeight, $questionWeight]) {
                $sections[] = ['id' => $index, 'title' => 'Section '.$index, 'competency_id' => $competency, 'weight' => $sectionWeight, 'questions' => [['id' => $index + 1, 'key' => 'q'.$index, 'type' => 'single_choice', 'weight' => $questionWeight, 'answer_schema' => ['correct' => 'a']]]];
            }
            $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'started_at' => now(), 'expires_at' => null, 'submitted_at' => now(), 'status' => 'pending_review', 'snapshot' => ['test_type' => 'practice', 'package' => ['pass_score' => 60], 'certificate_policy' => ['enabled' => false], 'scoring' => ['method' => $method, 'scale_max' => 100], 'levels' => [['code' => 'A1', 'name' => 'Initial', 'min_score' => 0]], 'sections' => $sections]]);
            foreach (['a', 'b', 'a'] as $index => $response) $attempt->answers()->create(['question_key' => 'q'.$index, 'response' => $response]);
            $graded = app(ScoringService::class)->finalize($attempt);
            $this->assertEquals($expected, $graded->result['normalized_score'], $method);
            $this->assertEquals($firstCompetencyScore, $graded->result['competency_scores'][0]['normalized_score'], $method);
            $this->assertEquals($firstCompetencyWeight, $graded->result['competency_scores'][0]['weight_percentage'], $method);
            $contribution = collect($graded->result['competency_scores'])->sum(fn ($row) => $row['normalized_score'] * $row['weight_percentage'] / 100);
            $this->assertEqualsWithDelta($expected, $contribution, 0.02, $method);
        }
    }

    public function test_random_and_manual_selection_enforce_difficulty_and_rollback_rejected_selections(): void
    {
        [$user, $package, $easy] = $this->fixture();
        $hard = $this->question($easy->competency_id, ['difficulty' => 'hard']);
        $section = $package->sections()->firstOrFail();
        $section->update(['difficulties' => ['hard']]);
        $package->update(['question_selection' => 'random']);
        $attempt = app(AttemptService::class)->start($user, $package);
        $this->assertSame($hard->id, $attempt->snapshot['sections'][0]['questions'][0]['id']);
        $this->assertSame(0, $easy->fresh()->usage_count);
        $this->assertSame(1, $hard->fresh()->usage_count);

        $other = User::factory()->create(['role' => 'participant', 'active' => true]);
        $package->update(['question_selection' => 'manual']);
        $section->update(['question_ids' => [$easy->id]]);
        $this->actingAs($other)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('question_usages', 1);
        $this->assertSame(0, $easy->fresh()->usage_count);
        $section->update(['question_ids' => [$hard->id]]);
        $manual = app(AttemptService::class)->start($other, $package);
        $this->assertSame($hard->id, $manual->snapshot['sections'][0]['questions'][0]['id']);
    }

    public function test_package_reclassification_preserves_active_attempts_and_separates_limits_and_comparisons(): void
    {
        [$user, $package] = $this->fixture('certification', ['attempts_limit' => 1]);
        $service = app(AttemptService::class);
        $certification = $service->start($user, $package);
        $this->travel(1)->seconds();
        $package->update(['test_type' => 'practice', 'name' => 'Converted practice']);
        $practice = $service->start($user, $package);
        $this->assertNotSame($certification->id, $practice->id);
        $this->assertSame('practice', $practice->test_type);
        $this->assertNull($practice->expires_at);
        $this->actingAs($user)->getJson('/api/packages/'.$package->id)->assertOk()->assertJsonPath('package.active_attempt.id', $practice->id)->assertJsonPath('package.attempts_used', 1);
        $service->save($practice, $practice->snapshot['sections'][0]['questions'][0]['key'], 'a');
        $practice = $service->submit($practice);
        $package->update(['test_type' => 'certification']);
        $this->assertSame($certification->id, $service->start($user, $package)->id);
        $service->save($certification, $certification->snapshot['sections'][0]['questions'][0]['key'], 'a');
        $certification = $service->submit($certification);
        $this->assertSame('certification', $certification->test_type);
        $this->assertSame('certification', $certification->result['test_type']);
        $this->assertNotSame($practice->snapshot['package']['name'], $certification->snapshot['package']['name']);
        $this->getJson('/api/results/'.$certification->id)->assertOk()->assertJsonPath('previous_result', null)->assertJsonPath('attempt.package.name', 'Policy regression');
        $this->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->travelBack();
    }

    public function test_confidential_answers_remain_hidden_even_when_completed_review_is_enabled(): void
    {
        [$user, $package, $public] = $this->fixture('certification', ['show_review' => true, 'lock_sections' => false]);
        $secret = $this->question($public->competency_id, ['confidential' => true, 'allowed_test_types' => ['certification'], 'explanation' => 'SECRET_EXPLANATION']);
        $package->sections()->firstOrFail()->update(['question_count' => 2, 'question_ids' => [$public->id, $secret->id]]);
        $service = app(AttemptService::class);
        $attempt = $service->start($user, $package);
        $this->assertSame([], $service->review($attempt));
        $this->actingAs($user)->getJson('/api/attempts/'.$attempt->id)->assertOk()->assertDontSee('answer_schema')->assertDontSee('SECRET_EXPLANATION');
        foreach ($attempt->snapshot['sections'][0]['questions'] as $question) $service->save($attempt, $question['key'], 'a');
        $attempt = $service->submit($attempt);
        $this->getJson('/api/results/'.$attempt->id)->assertOk()->assertJsonCount(1, 'review_answers')->assertJsonPath('review_answers.0.question_id', $public->id)->assertDontSee('SECRET_EXPLANATION');
        $attempt->update(['status' => 'pending_review']);
        $this->assertSame([], $service->review($attempt));
    }

    public function test_usage_records_preserve_version_type_and_time_when_the_bank_changes(): void
    {
        [$user, $package, $question] = $this->fixture();
        $this->travelTo(now()->startOfSecond());
        $started = now();
        $attempt = app(AttemptService::class)->start($user, $package);
        $question->update(['version' => 2, 'prompt' => 'Changed bank content', 'allowed_test_types' => ['certification']]);
        $package->update(['test_type' => 'certification']);
        $usage = QuestionUsage::where('exam_attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame('practice', $usage->test_type);
        $this->assertSame(1, $usage->question_version);
        $this->assertSame($started->toISOString(), $usage->used_at->toISOString());
        $this->assertSame('Pilih sapaan.', $attempt->fresh()->snapshot['sections'][0]['questions'][0]['prompt']);
        $this->assertDatabaseHas('question_versions', ['question_id' => $question->id, 'version' => 1]);
        $this->travelBack();
    }

    public function test_a_later_invalid_section_rolls_back_all_earlier_usage_reservations(): void
    {
        [$user, $package, $question] = $this->fixture();
        $question->update(['usage_limit' => 1]);
        $missing = Competency::create(['code' => 'empty-'.Str::random(8), 'name' => 'Kosakata', 'name_en' => 'Vocabulary', 'active' => true]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $missing->id, 'title' => 'Empty competency', 'position' => 2, 'duration_minutes' => 5, 'weight' => 1, 'question_count' => 1, 'randomize' => false]);
        $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->assertSame(0, $question->fresh()->usage_count);
        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('question_usages', 0);
        $this->assertDatabaseCount('question_versions', 0);
    }

    public function test_upgrade_backfills_historical_usage_without_exposing_legacy_questions_or_rewriting_results(): void
    {
        [$user, $package, $question] = $this->fixture('certification');
        $versionSnapshot = ['prompt' => 'Historical wording', 'answer_schema' => ['correct' => 'b']];
        $question->versions()->create(['version' => 1, 'snapshot' => $versionSnapshot]);
        $snapshot = ['package' => ['name' => 'Historical assessment'], 'sections' => [['questions' => [['id' => $question->id, 'key' => 'historical-key', 'version' => 4]]]]];
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'started_at' => now()->subMonth(), 'expires_at' => now()->subMonth()->addHour(), 'submitted_at' => now()->subMonth()->addHour(), 'status' => 'completed', 'score' => 88, 'level' => 'C1', 'result' => ['total_score' => 88], 'snapshot' => $snapshot]);
        $migration = require database_path('migrations/2026_10_23_000500_add_independent_test_types.php');
        $migration->down();
        $migration->up();
        $historical = ExamAttempt::findOrFail($attempt->id);
        $this->assertSame($snapshot, $historical->snapshot);
        $this->assertSame(['total_score' => 88], $historical->result);
        $this->assertSame('certification', $historical->test_type);
        $this->assertSame(['certification'], $question->fresh()->allowed_test_types);
        $this->assertSame(1, $question->fresh()->usage_count);
        $this->assertSame($versionSnapshot, $question->versions()->firstOrFail()->snapshot);
        $this->assertDatabaseHas('question_usages', ['exam_attempt_id' => $attempt->id, 'question_id' => $question->id, 'question_key' => 'historical-key', 'question_version' => 4, 'test_type' => 'certification']);
        $this->assertSame($historical->started_at->toISOString(), QuestionUsage::firstOrFail()->used_at->toISOString());
        $this->actingAs($user)->getJson('/api/admin/questions/'.$question->id.'/usage')->assertForbidden();
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->getJson('/api/admin/questions/'.$question->id.'/usage')->assertOk()->assertJsonPath('data.0.used_at', $historical->started_at->toISOString());
    }

    private function fixture(string $type = 'practice', array $overrides = []): array
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $competency = Competency::create(['code' => 'policy-'.Str::random(8), 'name' => 'Membaca', 'name_en' => 'Reading', 'active' => true]);
        $question = $this->question($competency->id);
        $package = ExamPackage::create(['code' => 'POL-'.Str::random(8), 'name' => 'Policy regression', 'test_type' => $type, 'policy_overrides' => $overrides, 'published' => true, 'duration_minutes' => 60, 'attempts_limit' => 1, 'certificate_enabled' => false, 'question_selection' => 'manual']);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Reading', 'duration_minutes' => 60, 'weight' => 1, 'question_count' => 1, 'question_ids' => [$question->id], 'randomize' => false]);

        return [$user, $package, $question];
    }

    private function question(int $competencyId, array $attributes = []): Question
    {
        return Question::create(array_merge(['code' => 'POL-Q-'.Str::random(8), 'competency_id' => $competencyId, 'type' => 'single_choice', 'title' => 'Sapaan', 'prompt' => 'Pilih sapaan.', 'difficulty' => 'easy', 'weight' => 1, 'status' => 'active', 'version' => 1, 'options' => [['id' => 'a', 'text' => 'Selamat pagi'], ['id' => 'b', 'text' => 'Selamat tinggal']], 'answer_schema' => ['correct' => 'a'], 'explanation' => 'Sapaan pagi.', 'allowed_test_types' => ['practice', 'placement', 'certification']], $attributes));
    }
}
