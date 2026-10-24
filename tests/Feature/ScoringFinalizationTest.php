<?php

namespace Tests\Feature;

use App\Models\{ExamAttempt, ExamPackage, User};
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(bool $manual): ExamAttempt
    {
        $user = User::factory()->create();
        $package = ExamPackage::create(['code' => 'TEST', 'name' => 'Test package', 'pass_score' => 60]);
        $questions = [['id' => 1, 'key' => 'objective', 'type' => 'single_choice', 'weight' => 1, 'answer_schema' => ['correct' => 'b']]];
        if ($manual) $questions[] = ['id' => 2, 'key' => 'essay', 'type' => 'essay', 'weight' => 1];
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'started_at' => now()->subMinutes(2), 'expires_at' => now()->addMinutes(10), 'submitted_at' => now(), 'status' => 'pending_review', 'snapshot' => ['package' => ['pass_score' => 60], 'certificate_policy' => ['enabled' => false], 'scoring' => ['scale_max' => 600], 'levels' => [['code' => 'A1', 'name' => 'Starter', 'min_score' => 0], ['code' => 'C1', 'name' => 'Advanced', 'min_score' => 75]], 'sections' => [['id' => 1, 'title' => 'Grammar', 'competency_id' => 1, 'weight' => 1, 'questions' => $questions]]]]);
        $attempt->answers()->create(['question_key' => 'objective', 'response' => 'b']);
        if ($manual) $attempt->answers()->create(['question_key' => 'essay', 'response' => 'A meaningful response.']);
        return $attempt;
    }

    public function test_normalized_thresholds_and_presentation_scale_are_independent(): void
    {
        $attempt = app(ScoringService::class)->finalize($this->attempt(false));
        $this->assertSame('completed', $attempt->status);
        $this->assertSame(600.0, $attempt->score);
        $this->assertEquals(100, $attempt->result['normalized_score']);
        $this->assertEquals(600, $attempt->result['score_max']);
        $this->assertEquals(360, $attempt->result['pass_score']);
        $this->assertTrue($attempt->result['passed']);
        $this->assertSame('C1', $attempt->level);
    }

    public function test_manual_responses_withhold_final_score_and_level_until_reviewed(): void
    {
        $attempt = app(ScoringService::class)->finalize($this->attempt(true));
        $this->assertSame('pending_review', $attempt->status);
        $this->assertNull($attempt->score);
        $this->assertNull($attempt->level);
        $this->assertNull($attempt->result['total_score']);
        $this->assertNull($attempt->result['level']);
        $this->assertEquals(1, $attempt->result['manual_pending']);
        $this->assertFalse($attempt->result['passed']);
        $attempt->answers()->where('question_key', 'essay')->update(['manual_score' => 80]);
        $attempt = app(ScoringService::class)->finalize($attempt);
        $this->assertSame('completed', $attempt->status);
        $this->assertEquals(90, $attempt->result['normalized_score']);
        $this->assertSame(540.0, $attempt->score);
        $this->assertTrue($attempt->result['passed']);
    }

    public function test_repeated_competency_sections_are_aggregated_using_their_relative_weights(): void
    {
        $attempt = $this->attempt(false);
        $snapshot = $attempt->snapshot;
        $section = $snapshot['sections'][0];
        $section['id'] = 2; $section['weight'] = 3;
        $section['questions'][0]['key'] = 'second-objective';
        $snapshot['sections'][] = $section;
        $attempt->update(['snapshot' => $snapshot]);
        $attempt->answers()->create(['question_key' => 'second-objective', 'response' => 'a']);
        $graded = app(ScoringService::class)->finalize($attempt);
        $this->assertEquals(25, $graded->result['normalized_score']);
        $this->assertCount(2, $graded->result['sections']);
        $this->assertCount(1, $graded->result['competency_scores']);
        $competency = $graded->result['competency_scores'][0];
        $this->assertEquals(25, $competency['normalized_score']);
        $this->assertEquals(150, $competency['score']);
        $this->assertEquals(100, $competency['weight_percentage']);
        $this->assertEquals(2, $competency['question_count']);
    }

    public function test_previous_result_comparison_uses_only_the_same_owner_and_package(): void
    {
        $previous = app(ScoringService::class)->finalize($this->attempt(false));
        $current = ExamAttempt::create(['user_id' => $previous->user_id, 'exam_package_id' => $previous->exam_package_id, 'status' => 'completed', 'started_at' => now()->subMinute(), 'expires_at' => now()->addMinutes(10), 'submitted_at' => now(), 'score' => 450, 'level' => 'C1', 'result' => ['normalized_score' => 75, 'total_score' => 450, 'score_max' => 600, 'competency_scores' => []], 'snapshot' => $previous->snapshot]);
        $other = User::factory()->create(['active' => true]);
        ExamAttempt::create(['user_id' => $other->id, 'exam_package_id' => $previous->exam_package_id, 'status' => 'completed', 'started_at' => now()->subSeconds(90), 'expires_at' => now()->addMinutes(10), 'submitted_at' => now(), 'score' => 0, 'result' => [], 'snapshot' => $previous->snapshot]);
        $this->actingAs(User::findOrFail($previous->user_id))->getJson('/api/results/'.$current->id)->assertOk()->assertJsonPath('previous_result.attempt_id', $previous->id)->assertJsonPath('previous_result.total_score', 600);
        $this->actingAs($other)->getJson('/api/results/'.$current->id)->assertForbidden();
    }
}
