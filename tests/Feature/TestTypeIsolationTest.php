<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamAttempt, ExamPackage, ExamSection, Question, QuestionType, TestType, User};
use App\Services\{AttemptService, CertificateService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestTypeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_types_share_one_question_and_only_certification_issues_a_certificate(): void
    {
        [$user, $practice, $question] = $this->fixture('practice');
        $placement = $this->package('placement', $question);
        $certification = $this->package('certification', $question);
        foreach ([$practice, $placement, $certification] as $package) {
            $attempt = $this->complete($user, $package);
            $this->assertSame(100.0, $attempt->score);
            $this->assertSame($package->test_type, $attempt->test_type);
            $this->assertSame($package->test_type === 'certification', $attempt->certificate()->exists());
            if ($package->test_type !== 'certification') $this->assertNull(app(CertificateService::class)->issue($attempt));
        }
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseCount('question_usages', 3);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame(3, $question->fresh()->usage_count);
        $this->actingAs($user)->getJson('/api/results?test_type=practice')->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.test_type', 'practice');
        $this->getJson('/api/dashboard?test_type=certification')->assertOk()->assertJsonPath('stats.total_attempts', 1);
    }

    public function test_untimed_practice_survives_reload_and_time_travel_without_creating_duplicate_usage(): void
    {
        [$user, $package, $question] = $this->fixture('practice');
        $question->update(['duration_seconds' => 1]);
        $this->actingAs($user);
        $start = $this->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated()->assertJsonPath('rules.timer_enabled', false)->assertJsonPath('attempt.expires_at', null);
        $attempt = ExamAttempt::findOrFail($start->json('attempt.id'));
        $key = $start->json('sections.0.questions.0.key');
        $this->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated()->assertJsonPath('attempt.id', $attempt->id);
        $this->travel(2)->days();
        $this->postJson('/api/attempts/'.$attempt->id.'/questions/'.$key.'/activate')->assertOk()->assertJsonPath('question_expires_at', null);
        $this->putJson('/api/attempts/'.$attempt->id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertOk();
        $this->getJson('/api/attempts/'.$attempt->id)->assertOk()->assertJsonPath('attempt.status', 'in_progress');
        $this->assertDatabaseCount('question_usages', 1);
        $this->assertSame(1, $question->fresh()->usage_count);
        $this->travelBack();
    }

    public function test_policy_score_scale_levels_and_review_use_the_start_snapshot(): void
    {
        [$user, $package, $question] = $this->fixture('practice');
        TestType::where('code', 'practice')->update(['score_scale' => 50, 'level_thresholds' => [['code' => 'A1', 'name' => 'Initial', 'min_score' => 0], ['code' => 'C2', 'name' => 'Excellent', 'min_score' => 90]]]);
        $service = app(AttemptService::class);
        $attempt = $service->start($user, $package);
        TestType::where('code', 'practice')->update(['score_scale' => 10, 'show_review' => false, 'timer_enabled' => true]);
        $question->update(['prompt' => 'Changed prompt', 'answer_schema' => ['correct' => 'b'], 'explanation' => 'Changed explanation']);
        $key = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        $service->save($attempt, $key, 'a');
        $attempt = $service->submit($attempt);
        $this->assertSame(50.0, $attempt->score);
        $this->assertSame('C2', $attempt->level);
        $response = $this->actingAs($user)->getJson('/api/results/'.$attempt->id)->assertOk()->assertJsonPath('review_answers.0.prompt', 'Pilih sapaan.')->assertJsonPath('review_answers.0.answer_schema.correct', 'a')->assertJsonPath('review_answers.0.explanation', 'Sapaan pagi.');
        $this->assertStringNotContainsString('Changed explanation', $response->getContent());
    }

    public function test_confidential_and_ungranted_questions_cannot_leak_into_other_types_even_with_manual_selection(): void
    {
        [$user, $package, $question] = $this->fixture('practice');
        $question->update(['confidential' => true]);
        $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $question->update(['confidential' => false, 'allowed_test_types' => ['certification']]);
        $this->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('question_usages', 0);
    }

    public function test_usage_limit_is_reserved_once_and_failure_rolls_back_the_attempt(): void
    {
        [$user, $package, $question] = $this->fixture('practice');
        $question->update(['usage_limit' => 1]);
        $this->complete($user, $package);
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->assertSame(1, $question->fresh()->usage_count);
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('question_usages', 1);
    }

    public function test_type_attempt_limit_and_package_override_do_not_affect_other_types(): void
    {
        [$user, $practice, $question] = $this->fixture('practice');
        TestType::where('code', 'practice')->update(['attempts_limit' => 1]);
        $this->complete($user, $practice);
        $this->actingAs($user)->postJson('/api/packages/'.$practice->id.'/attempts')->assertUnprocessable();
        $placement = $this->package('placement', $question);
        $this->postJson('/api/packages/'.$placement->id.'/attempts')->assertCreated();
        $practice->update(['policy_overrides' => ['attempts_limit' => 0]]);
        $this->postJson('/api/packages/'.$practice->id.'/attempts')->assertCreated();
    }

    public function test_admin_policy_endpoint_requires_role_validates_levels_and_logs_updates(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $this->actingAs($user)->getJson('/api/admin/test-types')->assertForbidden();
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->getJson('/api/admin/test-types')->assertOk()->assertJsonCount(3, 'test_types');
        $this->putJson('/api/admin/test-types/placement', ['level_thresholds' => [['code' => 'B1', 'name' => 'Intermediate', 'min_score' => 50]]])->assertUnprocessable();
        $this->putJson('/api/admin/test-types/placement', ['score_scale' => 200, 'level_thresholds' => [['code' => 'A1', 'name' => 'Initial', 'min_score' => 0], ['code' => 'C2', 'name' => 'Advanced', 'min_score' => 85]]])->assertOk();
        $this->assertSame(200.0, TestType::where('code', 'placement')->firstOrFail()->score_scale);
        $this->assertSame(100.0, TestType::where('code', 'practice')->firstOrFail()->score_scale);
        $this->assertDatabaseHas('audit_logs', ['event' => 'test_types.updated', 'user_id' => $admin->id]);
    }

    public function test_disabled_type_is_hidden_and_cannot_be_started(): void
    {
        [$user, $package] = $this->fixture('practice');
        TestType::where('code', 'practice')->update(['active' => false]);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(2, 'test_types')->assertJsonCount(0, 'packages');
        $this->getJson('/api/packages/'.$package->id)->assertNotFound();
        $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
    }

    public function test_placement_report_enforces_type_finalization_and_ownership(): void
    {
        [$user, $package, $question] = $this->fixture('placement');
        $attempt = app(AttemptService::class)->start($user, $package);
        $this->actingAs($user)->getJson('/api/results/'.$attempt->id.'/placement-report')->assertStatus(409);
        $key = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        app(AttemptService::class)->save($attempt, $key, 'a');
        $attempt = app(AttemptService::class)->submit($attempt);
        $response = $this->get('/api/results/'.$attempt->id.'/placement-report')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->actingAs(User::factory()->create(['role' => 'participant']))->getJson('/api/results/'.$attempt->id.'/placement-report')->assertForbidden();
        $practice = $this->complete($user, $this->package('practice', $question));
        $this->actingAs($user)->getJson('/api/results/'.$practice->id.'/placement-report')->assertNotFound();
    }

    public function test_text_size_is_saved_to_the_account_and_reloaded_on_bootstrap(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $this->actingAs($user)->putJson('/api/preferences', ['text_size' => 'extra_large'])->assertOk()->assertJsonPath('user.text_size', 'extra_large');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.text_size', 'extra_large');
        $this->putJson('/api/preferences', ['text_size' => 'huge'])->assertUnprocessable();
        $this->assertSame('extra_large', $user->fresh()->text_size);
    }

    public function test_admin_cannot_grant_confidential_questions_to_practice(): void
    {
        [, , $question] = $this->fixture('practice');
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        QuestionType::firstOrCreate(['code' => 'single_choice'], ['name' => 'Single choice', 'automatic' => true]);
        $data = array_merge($question->toArray(), ['confidential' => true, 'allowed_test_types' => ['practice', 'certification']]);
        $this->actingAs($admin)->putJson('/api/admin/questions/'.$question->id, $data)->assertUnprocessable();
        $this->assertFalse($question->fresh()->confidential);
    }

    public function test_csv_roundtrip_preserves_question_permissions_and_limits(): void
    {
        [, , $question] = $this->fixture('practice');
        $question->update(['allowed_test_types' => ['practice', 'placement'], 'usage_limit' => 10, 'duration_seconds' => 45]);
        QuestionType::firstOrCreate(['code' => 'single_choice'], ['name' => 'Single choice', 'automatic' => true]);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $export = $this->actingAs($admin)->get('/api/admin/questions/export')->assertOk();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $export->streamedContent()); rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', ''); $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $row = fgetcsv($stream, 0, ',', '"', ''); fclose($stream);
        $row[array_search('code', $headers)] = 'IMPORTED-PERMISSIONS';
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', ''); fputcsv($stream, $row, ',', '"', ''); rewind($stream);
        $file = UploadedFile::fake()->createWithContent('permissions.csv', stream_get_contents($stream)); fclose($stream);
        $this->postJson('/api/admin/questions/import', ['file' => $file])->assertCreated();
        $imported = Question::where('code', 'IMPORTED-PERMISSIONS')->firstOrFail();
        $this->assertSame(['practice', 'placement'], $imported->allowed_test_types);
        $this->assertFalse($imported->confidential);
        $this->assertSame(10, $imported->usage_limit);
        $this->assertSame(45, $imported->duration_seconds);
    }

    public function test_security_audit_counts_follow_captured_type_policy_without_affecting_practice(): void
    {
        [$user, $practice, $question] = $this->fixture('practice');
        $service = app(AttemptService::class);
        $practiceAttempt = $service->start($user, $practice);
        $this->actingAs($user)->postJson('/api/attempts/'.$practiceAttempt->id.'/events', ['type' => 'tab_hidden'])->assertOk()->assertJsonPath('recorded', false);
        TestType::where('code', 'certification')->update(['tab_switch_limit' => 0]);
        $certification = $service->start($user, $this->package('certification', $question));
        TestType::where('code', 'certification')->update(['tab_switch_limit' => 99]);
        $this->postJson('/api/attempts/'.$certification->id.'/events', ['type' => 'tab_hidden'])->assertOk()->assertJsonPath('tab_switch_count', 1)->assertJsonPath('tab_switch_limit', 0)->assertJsonPath('limit_exceeded', true);
        $this->assertSame('in_progress', $certification->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'exam.tab_hidden', 'subject_id' => $certification->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'exam.tab_hidden', 'subject_id' => $practiceAttempt->id]);
    }

    private function fixture(string $type): array
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $competency = Competency::create(['code' => 'reading-'.Str::random(6), 'name' => 'Membaca', 'name_en' => 'Reading', 'active' => true]);
        $question = Question::create(['code' => 'Q-'.Str::random(8), 'competency_id' => $competency->id, 'type' => 'single_choice', 'title' => 'Sapaan', 'prompt' => 'Pilih sapaan.', 'explanation' => 'Sapaan pagi.', 'difficulty' => 'easy', 'weight' => 1, 'status' => 'active', 'options' => [['id' => 'a', 'text' => 'Selamat pagi'], ['id' => 'b', 'text' => 'Selamat tinggal']], 'answer_schema' => ['correct' => 'a'], 'allowed_test_types' => ['practice', 'placement', 'certification']]);
        return [$user, $this->package($type, $question), $question];
    }

    private function package(string $type, Question $question): ExamPackage
    {
        $package = ExamPackage::create(['code' => 'PKG-'.Str::random(8), 'name' => 'Test '.$type, 'test_type' => $type, 'policy_overrides' => [], 'published' => true, 'duration_minutes' => 60, 'attempts_limit' => 2, 'pass_score' => 60, 'certificate_enabled' => true, 'question_selection' => 'manual']);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $question->competency_id, 'title' => 'Reading', 'question_count' => 1, 'question_ids' => [$question->id], 'duration_minutes' => 60, 'weight' => 1, 'randomize' => false]);
        return $package;
    }

    private function complete(User $user, ExamPackage $package): ExamAttempt
    {
        $service = app(AttemptService::class);
        $attempt = $service->start($user, $package);
        $service->save($attempt, $attempt->snapshot['sections'][0]['questions'][0]['key'], 'a');
        return $service->submit($attempt);
    }
}
