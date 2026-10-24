<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Competency;
use App\Models\ExamAttempt;
use App\Models\ExamPackage;
use App\Models\ExamSection;
use App\Models\MediaAsset;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'app.url' => 'https://inbaf.example']);
    }

    public function test_registration_cannot_escalate_role_and_inactive_accounts_cannot_use_authenticated_routes(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New Learner', 'email' => 'learner@example.test',
            'password' => 'SecureTestPass123!', 'password_confirmation' => 'SecureTestPass123!',
            'role' => 'superadmin', 'active' => true,
        ])->assertCreated()->assertJsonPath('user.role', 'participant');
        $user = User::where('email', 'learner@example.test')->firstOrFail();
        $this->actingAs($user)->getJson('/api/admin/settings')->assertForbidden();
        $user->update(['active' => false]);
        $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden();
        $this->postJson('/api/login', ['email' => 'learner@example.test', 'password' => 'SecureTestPass123!'])->assertUnprocessable();
    }

    public function test_certificates_are_idempotent_and_preserve_the_final_snapshot(): void
    {
        [$user, $attempt] = $this->completedAttempt();
        $service = app(CertificateService::class);
        $first = $service->issue($attempt);
        $this->assertNotNull($first);
        $user->update(['name' => 'Changed Profile Name']);
        $attempt->examPackage->update(['name' => 'Changed Package', 'certificate_enabled' => false]);
        $second = $service->issue($attempt);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Original Participant', $second->payload['holder_name']);
        $this->assertSame('Historical Assessment', $second->payload['exam_package']);
        $this->assertTrue($service->signatureIsValid($second));
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame(1, AuditLog::where('event', 'certificate.issued')->count());
    }

    public function test_pending_manual_scores_and_failed_results_cannot_receive_certificates(): void
    {
        [, $attempt] = $this->completedAttempt();
        $attempt->update(['status' => 'pending_review', 'result' => ['passed' => true, 'manual_pending' => true, 'total_score' => 100]]);
        $this->assertNull(app(CertificateService::class)->issue($attempt));
        $attempt->update(['status' => 'completed', 'score' => 30, 'result' => ['passed' => false, 'manual_pending' => false, 'total_score' => 30]]);
        $this->assertNull(app(CertificateService::class)->issue($attempt));
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_public_verification_exposes_only_safe_signed_information_and_detects_tampering(): void
    {
        [, $attempt] = $this->completedAttempt();
        $certificate = app(CertificateService::class)->issue($attempt);
        $this->getJson('/api/verify/'.$certificate->token)
            ->assertOk()->assertJsonPath('status', 'valid')
            ->assertJsonPath('certificate.holder_name', 'Original Participant')
            ->assertJsonMissingPath('certificate.email')->assertJsonMissingPath('certificate.user_id')
            ->assertJsonMissingPath('certificate.signature')->assertJsonMissingPath('certificate.attempt_id');
        $payload = $certificate->payload;
        $payload['total_score'] = 999;
        $certificate->update(['payload' => $payload]);
        $this->getJson('/api/verify/'.$certificate->token)->assertNotFound()->assertJsonPath('status', 'not_found');
        $this->getJson('/api/verify/not-a-real-token')->assertNotFound();
    }

    public function test_certificate_expiry_uses_signed_policy_and_revocation_requires_admin_with_an_audit(): void
    {
        [$user, $attempt] = $this->completedAttempt();
        $certificate = app(CertificateService::class)->issue($attempt);
        $this->actingAs($user)->postJson('/api/admin/certificates/'.$certificate->id.'/revoke', ['reason' => 'Invalid identification'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->postJson('/api/admin/certificates/'.$certificate->id.'/revoke', [])->assertUnprocessable();
        $this->travel(25)->months();
        $this->getJson('/api/verify/'.$certificate->token)->assertOk()->assertJsonPath('status', 'expired');
        $this->actingAs($admin)->postJson('/api/admin/certificates/'.$certificate->id.'/revoke', ['reason' => 'Invalid identification'])->assertOk();
        $this->getJson('/api/verify/'.$certificate->token)->assertOk()->assertJsonPath('status', 'revoked')->assertJsonMissingPath('certificate.revocation_reason');
        $this->assertDatabaseHas('audit_logs', ['event' => 'certificate.revoked', 'user_id' => $admin->id]);
        $this->travelBack();
    }

    public function test_real_pdf_contains_a_qr_image_and_the_deployment_verification_url_and_enforces_ownership(): void
    {
        [$user, $attempt] = $this->completedAttempt();
        $certificate = app(CertificateService::class)->issue($attempt);
        $url = '/api/certificates/'.$certificate->id.'/pdf';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->getJson($url)->assertForbidden();
        $response = $this->actingAs($user)->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('https://inbaf.example/verify/'.$certificate->token, $pdf);
        $this->assertGreaterThan(10000, strlen($pdf));
    }

    public function test_started_attempt_hides_keys_and_keeps_answers_stable_after_question_changes(): void
    {
        [$user, $package, $question] = $this->objectivePackage();
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertSuccessful();
        $id = $start->json('attempt.id') ?? $start->json('id');
        $this->assertNotEmpty($id);
        $attemptResponse = $this->getJson('/api/attempts/'.$id)->assertOk();
        $this->assertStringNotContainsString('answer_schema', $attemptResponse->getContent());
        $this->assertStringNotContainsString('correct_answer', $attemptResponse->getContent());
        $attempt = ExamAttempt::findOrFail($id);
        $questionSnapshot = $attempt->snapshot['sections'][0]['questions'][0];
        $key = $questionSnapshot['key'];
        $question->update(['answer_schema' => ['correct' => 'b'], 'prompt' => 'Changed after start']);
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a', 'marked' => false])->assertSuccessful();
        $this->postJson('/api/attempts/'.$id.'/submit')->assertSuccessful();
        $this->assertSame(100.0, $attempt->fresh()->score);
        $this->postJson('/api/attempts/'.$id.'/submit')->assertSuccessful();
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('exam_answers', 1);
        $this->postJson('/api/packages/'.$package->id.'/attempts')->assertStatus(422);
    }

    public function test_foreign_attempt_access_and_answers_are_forbidden_and_expired_attempt_auto_submits(): void
    {
        [$user, $package] = $this->objectivePackage();
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertSuccessful();
        $id = $start->json('attempt.id') ?? $start->json('id');
        $attempt = ExamAttempt::findOrFail($id);
        $key = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->getJson('/api/attempts/'.$id)->assertForbidden();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertForbidden();
        $this->actingAs($user)->putJson('/api/attempts/'.$id.'/answers', ['question_key' => 'foreign_question', 'response' => 'a'])->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->getJson('/api/attempts/'.$id)->assertOk();
        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->submitted_at);
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertStatus(422);
        $this->assertSame(0.0, $attempt->fresh()->score);
        $this->travelBack();
    }

    public function test_manual_submission_waits_for_review_before_final_score_and_certificate(): void
    {
        [$user, $package, $question] = $this->objectivePackage();
        $question->update(['type' => 'essay', 'answer_schema' => [], 'metadata' => ['rubric' => ['grammar', 'vocabulary', 'coherence']]]);
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertSuccessful();
        $id = $start->json('attempt.id');
        $attempt = ExamAttempt::findOrFail($id);
        $key = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'Saya sedang belajar bahasa Indonesia.'])->assertSuccessful();
        $this->postJson('/api/attempts/'.$id.'/submit')->assertSuccessful();
        $this->assertSame('pending_review', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->score);
        $this->assertDatabaseCount('certificates', 0);
        $answer = $attempt->answers()->firstOrFail();
        $this->postJson('/api/admin/reviews/'.$answer->id, ['score' => 80, 'feedback' => 'Clear and relevant response.'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->postJson('/api/admin/reviews/'.$answer->id, [
            'score' => 80, 'feedback' => 'Clear and relevant response.', 'scores' => ['grammar' => 80, 'vocabulary' => 80, 'coherence' => 80],
        ])->assertSuccessful();
        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame(80.0, $attempt->fresh()->score);
        $this->assertDatabaseHas('manual_assessments', ['exam_answer_id' => $answer->id, 'reviewer_id' => $admin->id]);
        $this->assertDatabaseCount('certificates', 1);
        $this->postJson('/api/admin/reviews/'.$answer->id, ['score' => 10, 'feedback' => 'Attempt to alter final result.'])->assertStatus(409);
        $this->assertSame(80.0, $attempt->fresh()->score);
    }

    public function test_objective_scoring_handles_exact_sets_partial_penalties_text_normalization_and_order(): void
    {
        $scoring = app(ScoringService::class);
        $multiple = ['type' => 'multiple_response', 'answer_schema' => ['correct' => ['a', 'b']]];
        $this->assertSame(1.0, $scoring->scoreQuestion($multiple, ['b', 'a']));
        $this->assertSame(0.0, $scoring->scoreQuestion($multiple, ['a']));
        $multiple['answer_schema']['partial_credit'] = true;
        $this->assertSame(0.5, $scoring->scoreQuestion($multiple, ['a']));
        $this->assertSame(0.0, $scoring->scoreQuestion($multiple, ['a', 'wrong']));
        $this->assertSame(1.0, $scoring->scoreQuestion(['type' => 'short_answer', 'answer_schema' => ['accepted' => ['selamat pagi']]], '  SELAMAT   PAGI  '));
        $this->assertSame(1.0, $scoring->scoreQuestion(['type' => 'true_false', 'answer_schema' => ['correct' => true]], true));
        $ordered = ['type' => 'word_ordering', 'answer_schema' => ['correct' => ['item-a', 'item-b', 'item-c']]];
        $this->assertSame(1.0, $scoring->scoreQuestion($ordered, ['item-a', 'item-b', 'item-c']));
        $this->assertSame(0.0, $scoring->scoreQuestion($ordered, ['item-c', 'item-b', 'item-a']));
        $this->assertSame(0.0, $scoring->scoreQuestion($ordered, null));
    }

    public function test_score_scale_is_snapshotted_and_certificate_eligibility_uses_normalized_score(): void
    {
        [$user, $package] = $this->objectivePackage();
        SystemSetting::create(['key' => 'score_scale', 'value' => 50]);
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertSuccessful();
        $attempt = ExamAttempt::findOrFail($start->json('attempt.id'));
        SystemSetting::where('key', 'score_scale')->firstOrFail()->update(['value' => 20]);
        $key = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        $this->putJson('/api/attempts/'.$attempt->id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertSuccessful();
        $this->postJson('/api/attempts/'.$attempt->id.'/submit')->assertSuccessful();
        $this->assertSame(50.0, $attempt->fresh()->score);
        $this->assertSame(100.0, (float) $attempt->fresh()->result['normalized_score']);
        $certificate = Certificate::where('exam_attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame(50.0, (float) $certificate->payload['total_score']);
        $this->assertSame(50.0, (float) $certificate->payload['score_max']);
    }

    public function test_stimulus_media_is_authorized_through_snapshot_and_transcripts_and_internal_paths_are_hidden(): void
    {
        Storage::fake('local');
        [$user, $package, $question] = $this->objectivePackage();
        Storage::disk('local')->put('media/stimulus.wav', 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 0));
        $media = MediaAsset::create(['original_name' => 'stimulus.wav', 'path' => 'media/stimulus.wav', 'mime' => 'audio/wav', 'kind' => 'audio', 'size' => 44, 'metadata' => ['internal' => 'not-public']]);
        $group = QuestionGroup::create(['title' => 'Announcement', 'kind' => 'audio', 'media_asset_id' => $media->id, 'content' => 'SECRET AUDIO TRANSCRIPT', 'metadata' => ['max_plays' => 2, 'transcript' => 'SECRET AUDIO TRANSCRIPT']]);
        $question->update(['type' => 'listening', 'question_group_id' => $group->id, 'metadata' => ['max_plays' => 2, 'autoplay' => false, 'transcript' => 'SECRET AUDIO TRANSCRIPT']]);
        $response = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $response->assertJsonPath('sections.0.questions.0.group.media_asset.id', $media->id)
            ->assertJsonPath('sections.0.questions.0.metadata.max_plays', 2)
            ->assertJsonMissingPath('sections.0.questions.0.group.media_asset.path')
            ->assertJsonMissingPath('sections.0.questions.0.group.content');
        $this->assertStringNotContainsString('SECRET AUDIO TRANSCRIPT', $response->getContent());
        $this->assertStringNotContainsString('not-public', $response->getContent());
        $this->get('/api/media/'.$media->id)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->getJson('/api/media/'.$media->id)->assertForbidden();
    }

    public function test_certificate_paper_orientation_and_authorized_images_are_signed_and_changed_image_bytes_are_rejected(): void
    {
        Storage::fake('local');
        [$user, $package] = $this->objectivePackage();
        $image = UploadedFile::fake()->image('signature.png', 300, 90);
        Storage::disk('local')->put('media/signature.png', file_get_contents($image->getRealPath()));
        Storage::disk('local')->put('media/background.png', file_get_contents($image->getRealPath()));
        $signature = MediaAsset::create(['original_name' => 'signature.png', 'path' => 'media/signature.png', 'mime' => 'image/png', 'kind' => 'image', 'size' => $image->getSize()]);
        $background = MediaAsset::create(['original_name' => 'background.png', 'path' => 'media/background.png', 'mime' => 'image/png', 'kind' => 'image', 'size' => $image->getSize()]);
        foreach (['certificate_paper' => 'letter', 'certificate_orientation' => 'portrait', 'certificate_template' => 'formal', 'certificate_signature_media_id' => $signature->id, 'certificate_background_media_id' => $background->id] as $key => $value) {
            SystemSetting::create(['key' => $key, 'value' => $value]);
        }
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $attempt = ExamAttempt::findOrFail($start->json('attempt.id'));
        $this->assertSame('media/signature.png', $attempt->snapshot['certificate_policy']['signature_path']);
        SystemSetting::where('key', 'certificate_template')->firstOrFail()->update(['value' => 'modern']);
        SystemSetting::where('key', 'certificate_paper')->firstOrFail()->update(['value' => 'a4']);
        $questionKey = $attempt->snapshot['sections'][0]['questions'][0]['key'];
        $this->putJson('/api/attempts/'.$attempt->id.'/answers', ['question_key' => $questionKey, 'response' => 'a'])->assertOk();
        $this->postJson('/api/attempts/'.$attempt->id.'/submit')->assertOk();
        $service = app(CertificateService::class);
        $certificate = $service->issue($attempt);
        $this->assertSame('letter', $certificate->payload['paper']);
        $this->assertSame('portrait', $certificate->payload['orientation']);
        $this->assertSame('formal', $certificate->payload['template']);
        $this->assertSame(hash_file('sha256', $image->getRealPath()), $certificate->payload['signature_hash']);
        $response = $this->actingAs($user)->get('/api/certificates/'.$certificate->id.'/pdf')->assertOk();
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[0\.000 0\.000 612\.000 792\.000\]/', $response->getContent());
        $this->assertTrue($service->signatureIsValid($certificate));
        $replacement = UploadedFile::fake()->image('replacement.png', 301, 91);
        Storage::disk('local')->put('media/signature.png', file_get_contents($replacement->getRealPath()));
        $this->getJson('/api/certificates/'.$certificate->id.'/pdf')->assertStatus(409);
        $this->assertNull($service->imageAsset('../.env'));
        $this->assertNull($service->imageAsset('https://example.org/background.png'));
        Storage::disk('local')->put('media/unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertNull($service->imageAsset('media/unsafe.svg'));
    }

    private function completedAttempt(): array
    {
        $user = User::factory()->create(['name' => 'Original Participant', 'role' => 'participant', 'active' => true]);
        $package = ExamPackage::create(['code' => 'CERT-'.Str::random(8), 'name' => 'Historical Assessment', 'published' => true]);
        $attempt = ExamAttempt::create([
            'user_id' => $user->id, 'exam_package_id' => $package->id, 'status' => 'completed',
            'started_at' => now()->subHour(), 'expires_at' => now(), 'submitted_at' => now(), 'score' => 85, 'level' => 'B2',
            'result' => ['total_score' => 85, 'passed' => true, 'manual_pending' => false, 'competency_scores' => [['competency_id' => 1, 'code' => 'reading', 'name' => 'Reading', 'score' => 85, 'weight' => 1]]],
            'snapshot' => ['participant_name' => $user->name, 'package' => ['name' => $package->name], 'certificate_policy' => [
                'enabled' => true, 'pass_score' => 60, 'expiry_months' => 24, 'template' => 'modern',
                'institution' => 'INBAF Language Assessment', 'signer_name' => 'Assessment Director', 'signer_title' => 'Director',
            ]],
        ]);

        return [$user, $attempt];
    }

    private function objectivePackage(): array
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $competency = Competency::create(['code' => 'reading-'.Str::random(6), 'name' => 'Membaca', 'name_en' => 'Reading']);
        $question = Question::create([
            'code' => 'Q-'.Str::random(8), 'competency_id' => $competency->id, 'type' => 'multiple_choice',
            'title' => 'Snapshot invariant', 'prompt' => 'Select the Indonesian greeting.', 'status' => 'active', 'weight' => 1,
            'options' => [['id' => 'a', 'text' => 'Selamat pagi'], ['id' => 'b', 'text' => 'Goodbye']], 'answer_schema' => ['correct' => 'a'],
        ]);
        $package = ExamPackage::create(['code' => 'EXAM-'.Str::random(8), 'name' => 'Snapshot Test', 'published' => true, 'attempts_limit' => 1, 'duration_minutes' => 60, 'pass_score' => 60, 'certificate_enabled' => true]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Reading', 'question_ids' => [$question->id], 'question_count' => 1, 'duration_minutes' => 60, 'randomize' => false, 'weight' => 1]);

        return [$user, $package, $question];
    }
}
