<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Competency, ExamAttempt, ExamPackage, ExamSection, Question, QuestionType, User};
use App\Services\{AttemptService, CertificateService, ScoringService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinalDomainReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'app.url' => 'https://inbaf.example']);
    }

    #[DataProvider('levelBoundaries')]
    public function test_internal_level_boundaries_and_scaled_results_use_known_reference_values(float $normalized, string $level, bool $passed): void
    {
        $attempt = $this->pending([[$this->question('essay')]], ['essay' => ['response' => 'Reference answer', 'manual_score' => $normalized]]);
        $result = app(ScoringService::class)->finalize($attempt);
        $this->assertSame(round($normalized * 6, 2), $result->score);
        $this->assertSame($level, $result->level);
        $this->assertEquals($normalized, $result->result['normalized_score']);
        $this->assertSame($passed, $result->result['passed']);
        $this->assertEquals(360, $result->result['pass_score']);
        $this->assertSame($passed, $result->certificate()->exists());
        if ($passed) {
            $certificate = $result->certificate;
            $this->assertEquals($result->score, $certificate->payload['total_score']);
            $this->assertSame($level, $certificate->payload['level']);
            $this->getJson('/api/verify/'.$certificate->token)->assertOk()
                ->assertJsonPath('status', 'valid')->assertJsonPath('certificate.total_score', fn ($value) => (float) $value === $result->score);
        }
        $this->actingAs($result->user)->getJson('/api/results/'.$result->id)->assertOk()->assertJsonPath('attempt.score', fn ($value) => (float) $value === $result->score);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.latest_score', fn ($value) => (float) $value === $result->score)
            ->assertJsonPath('stats.current_level', $level)->assertJsonPath('stats.score_max', 600);
    }

    public static function levelBoundaries(): array
    {
        return [
            'minimum' => [0.0, 'A1', false], 'before A2' => [19.99, 'A1', false], 'A2' => [20.0, 'A2', false],
            'before B1' => [39.99, 'A2', false], 'B1' => [40.0, 'B1', false], 'before B2 and pass' => [59.99, 'B1', false],
            'B2 and pass' => [60.0, 'B2', true], 'before C1' => [74.99, 'B2', true], 'C1' => [75.0, 'C1', true],
            'before C2' => [89.99, 'C1', true], 'C2' => [90.0, 'C2', true], 'maximum' => [100.0, 'C2', true],
        ];
    }

    #[DataProvider('scoringMethods')]
    public function test_fractional_sections_are_not_rounded_before_aggregation(string $method): void
    {
        // Section 1 earns 1/3 and section 2 earns 5/9. Their exact mean is 4/9 = 44.44, not 44.45.
        $first = [$this->question('first-correct', 'single_choice', 1), $this->question('first-wrong', 'single_choice', 2)];
        $second = [$this->question('second-correct', 'single_choice', 5), $this->question('second-wrong', 'single_choice', 4)];
        $attempt = $this->pending([$first, $second], [
            'first-correct' => ['response' => 'a'], 'first-wrong' => ['response' => 'b'],
            'second-correct' => ['response' => 'a'], 'second-wrong' => ['response' => 'b'],
        ], $method, false);
        $graded = app(ScoringService::class)->finalize($attempt);
        $expected = $method === 'weighted_questions' ? 50 : 44.44;
        $this->assertEquals($expected, $graded->result['normalized_score']);
        $this->assertEquals($method === 'weighted_questions' ? 300 : 266.67, $graded->score);
        $this->assertEquals(33.33, $graded->result['sections'][0]['normalized_score']);
        $this->assertEquals(55.56, $graded->result['sections'][1]['normalized_score']);
    }

    public static function scoringMethods(): array
    {
        return [['weighted_competency'], ['weighted_questions'], ['equal_competency']];
    }

    public function test_weighted_manual_rubrics_are_authoritative_and_retries_cannot_duplicate_or_rewrite_grades(): void
    {
        $rubric = [['name' => 'Clarity', 'weight' => 3], ['name' => 'Register', 'weight' => 1]];
        $first = $this->question('writing', 'essay'); $first['metadata'] = ['rubric' => $rubric];
        $second = $this->question('speaking', 'speaking'); $second['metadata'] = ['rubric' => ['Fluency' => 1, 'Accuracy' => 3]];
        $attempt = $this->pending([[$first, $second]], ['writing' => ['response' => 'Reference writing.'], 'speaking' => ['response' => ['media_asset_id' => 1]]]);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $writing = $attempt->answers()->where('question_key', 'writing')->firstOrFail();
        $speaking = $attempt->answers()->where('question_key', 'speaking')->firstOrFail();
        $payload = ['score' => 100, 'feedback' => 'Reference rubric feedback.', 'scores' => ['Clarity' => 90, 'Register' => 70]];
        $this->actingAs($admin)->postJson('/api/admin/reviews/'.$writing->id, $payload)->assertOk()->assertJsonPath('attempt.status', 'pending_review');
        $this->assertSame(85.0, $writing->fresh()->manual_score);
        $this->postJson('/api/admin/reviews/'.$writing->id, $payload)->assertOk();
        $this->assertDatabaseCount('manual_assessments', 1);
        $this->assertSame(1, AuditLog::where('event', 'answer.assessed')->count());
        $this->postJson('/api/admin/reviews/'.$writing->id, [...$payload, 'scores' => ['Clarity' => 0, 'Register' => 0]])->assertConflict();
        $this->postJson('/api/admin/reviews/'.$speaking->id, ['score' => 1, 'feedback' => 'Speaking rubric feedback.', 'scores' => ['Fluency' => 100, 'Accuracy' => 80]])->assertOk()->assertJsonPath('attempt.status', 'completed');
        $this->assertSame(510.0, $attempt->fresh()->score);
        $this->assertDatabaseCount('manual_assessments', 2);
        $this->assertDatabaseCount('certificates', 1);
        $this->postJson('/api/admin/reviews/'.$writing->id, $payload)->assertOk();
        $this->assertSame(510.0, $attempt->fresh()->score);
        $this->assertDatabaseCount('manual_assessments', 2);
    }

    public function test_missing_or_unknown_rubric_criteria_are_rejected_without_partial_state(): void
    {
        $question = $this->question('essay'); $question['metadata'] = ['rubric' => ['Grammar', 'Vocabulary']];
        $attempt = $this->pending([[$question]], ['essay' => ['response' => 'Reference response.']]);
        $answer = $attempt->answers()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        foreach ([[], ['Grammar' => 80], ['Grammar' => 80, 'Vocabulary' => 80, 'Invented' => 100]] as $scores) {
            $this->postJson('/api/admin/reviews/'.$answer->id, ['score' => 100, 'feedback' => 'Complete feedback.', 'scores' => $scores])->assertUnprocessable();
            $this->assertNull($answer->fresh()->manual_score);
            $this->assertDatabaseCount('manual_assessments', 0);
            $this->assertDatabaseCount('certificates', 0);
        }
        $this->postJson('/api/admin/reviews/'.$answer->id, ['score' => 0, 'feedback' => 'Complete feedback.', 'scores' => ['Grammar' => 80, 'Vocabulary' => 100]])->assertOk();
        $this->assertSame(90.0, $answer->fresh()->manual_score);
    }

    public function test_finalized_results_and_certificate_payload_survive_replayed_scoring_jobs(): void
    {
        $attempt = app(ScoringService::class)->finalize($this->pending([[$this->question('objective', 'single_choice')]], ['objective' => ['response' => 'a']]));
        $result = $attempt->getRawOriginal('result');
        $certificate = $attempt->certificate->getRawOriginal('payload');
        $audits = AuditLog::where('event', 'attempt.graded')->count();
        // Even an out-of-band answer mutation must not silently rewrite the official signed result.
        $attempt->answers()->update(['response' => json_encode('b')]);
        $this->travel(1)->minutes();
        foreach (range(1, 3) as $_) app(ScoringService::class)->finalize($attempt);
        $this->assertSame($result, $attempt->fresh()->getRawOriginal('result'));
        $this->assertSame($certificate, $attempt->fresh()->certificate->getRawOriginal('payload'));
        $this->assertSame($audits, AuditLog::where('event', 'attempt.graded')->count());
        $this->assertTrue(app(CertificateService::class)->signatureIsValid($attempt->fresh()->certificate));
        $this->assertDatabaseCount('certificates', 1);
        $this->travelBack();
    }

    public function test_certificate_issue_failure_rolls_back_scoring_and_succeeds_on_retry_once(): void
    {
        $attempt = $this->pending([[$this->question('objective', 'single_choice')]], ['objective' => ['response' => 'a']]);
        config(['app.key' => '']);
        try {
            app(ScoringService::class)->finalize($attempt);
            $this->fail('An unsigned certificate must not be issued.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('APP_KEY is required for certificate signatures.', $exception->getMessage());
        }
        $this->assertSame('pending_review', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->score);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame(0, AuditLog::where('event', 'attempt.graded')->count());
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
        $this->assertSame('completed', app(ScoringService::class)->finalize($attempt)->status);
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_certificate_templates_preserve_the_two_decimal_official_score(): void
    {
        $attempt = app(ScoringService::class)->finalize($this->pending([[$this->question('essay')]], ['essay' => ['response' => 'Reference answer.', 'manual_score' => 92.55]]));
        $certificate = $attempt->certificate;
        $this->assertSame(555.3, $attempt->score);
        foreach (['modern', 'formal'] as $template) {
            $html = view('certificates.'.$template, ['certificate' => $certificate, 'payload' => $certificate->payload, 'qrDataUri' => '', 'logoDataUri' => null, 'backgroundDataUri' => null, 'signatureDataUri' => null, 'verificationUrl' => 'https://inbaf.example/verify/reference', 'status' => 'valid'])->render();
            $this->assertStringContainsString('555.30', $html);
            $this->assertStringNotContainsString('555.3<', $html);
        }
    }

    #[DataProvider('invalidRubrics')]
    public function test_invalid_manual_rubrics_cannot_be_activated(mixed $rubric): void
    {
        $competency = Competency::create(['code' => 'rubric', 'name' => 'Writing', 'name_en' => 'Writing']);
        QuestionType::firstOrCreate(['code' => 'essay'], ['name' => 'Essay', 'automatic' => false]);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]))->postJson('/api/admin/questions', [
            'code' => 'INVALID-RUBRIC', 'title' => 'Invalid rubric fixture', 'competency_id' => $competency->id,
            'type' => 'essay', 'prompt' => 'Write a short response.', 'difficulty' => 'easy', 'weight' => 1,
            'status' => 'active', 'options' => [], 'answer_schema' => [], 'metadata' => ['rubric' => $rubric],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.rubric');
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
    }

    public static function invalidRubrics(): array
    {
        return [
            'zero weight' => [[['name' => 'Fluency', 'weight' => 0]]],
            'negative weight' => [[['name' => 'Fluency', 'weight' => -1]]],
            'unbounded weight' => [[['name' => 'Fluency', 'weight' => 1001]]],
            'duplicate names' => [[['name' => 'Fluency', 'weight' => 1], ['name' => 'Fluency', 'weight' => 3]]],
            'empty name' => [[['name' => '   ', 'weight' => 1]]],
            'invalid shape' => ['unstructured'],
            'duplicate legacy names' => [['Fluency', 'Fluency']],
        ];
    }

    public function test_a_stale_active_user_cannot_start_after_the_account_is_deactivated(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $competency = Competency::create(['code' => 'stale-user', 'name' => 'Reading', 'name_en' => 'Reading', 'active' => true]);
        $question = Question::create(['code' => 'STALE-Q', 'competency_id' => $competency->id, 'type' => 'single_choice', 'title' => 'Reference', 'prompt' => 'Choose.', 'status' => 'active', 'answer_schema' => ['correct' => 'a']]);
        $package = ExamPackage::create(['code' => 'STALE-PKG', 'name' => 'Stale participant guard', 'published' => true, 'duration_minutes' => 10, 'question_selection' => 'manual', 'settings' => ['lock_sections' => false]]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Reading', 'duration_minutes' => 10, 'question_count' => 1, 'question_ids' => [$question->id], 'randomize' => false]);
        User::whereKey($user->id)->update(['active' => false]);
        try {
            app(AttemptService::class)->start($user, $package);
            $this->fail('The account state must be read again under the user lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attempt', $exception->errors());
        }
        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('question_usages', 0);
        $this->assertSame(0, $question->fresh()->usage_count);
    }

    private function question(string $key, string $type = 'essay', float $weight = 1): array
    {
        return ['id' => 1, 'key' => $key, 'type' => $type, 'weight' => $weight, 'answer_schema' => ['correct' => 'a']];
    }

    private function pending(array $questionSections, array $answers, string $method = 'weighted_competency', bool $certificateEnabled = true): ExamAttempt
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $package = ExamPackage::create(['code' => 'REF-'.Str::random(10), 'name' => 'Reference Assessment', 'test_type' => 'certification', 'published' => true, 'pass_score' => 60]);
        $sections = [];
        foreach ($questionSections as $index => $questions) {
            $sections[] = ['id' => $index + 1, 'title' => 'Reference section '.($index + 1), 'competency_id' => $index + 1, 'weight' => 1, 'questions' => $questions];
        }
        $levels = [];
        foreach (['A1' => 0, 'A2' => 20, 'B1' => 40, 'B2' => 60, 'C1' => 75, 'C2' => 90] as $code => $minimum) $levels[] = ['code' => $code, 'name' => $code, 'min_score' => $minimum];
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'certification', 'status' => 'pending_review', 'started_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(5), 'submitted_at' => now(), 'snapshot' => ['test_type' => 'certification', 'participant_name' => $user->name, 'package' => ['name' => $package->name, 'pass_score' => 60], 'certificate_policy' => ['enabled' => $certificateEnabled, 'pass_score' => 60], 'scoring' => ['method' => $method, 'scale_max' => 600], 'levels' => $levels, 'sections' => $sections, 'rules' => ['show_review' => false]]]);
        foreach ($answers as $key => $data) $attempt->answers()->create(['question_key' => $key, ...$data]);

        return $attempt;
    }
}
