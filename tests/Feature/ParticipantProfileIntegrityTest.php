<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Certificate, Competency, ExamAttempt, ExamPackage, ExamSection, Question, User};
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipantProfileIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Independent authenticated browsers use only the isolated test database.
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->withCredentials();
    }

    private function browser(?string $sessionId = null): static
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->driver()->flush();
        $this->defaultCookies = $sessionId ? [config('session.cookie') => $sessionId] : [];

        return $this;
    }

    private function login(User $user): array
    {
        $response = $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        return [$response->getCookie(config('session.cookie'))->getValue(), $response->json('csrf_token')];
    }

    private function participant(): User
    {
        return User::factory()->create(['name' => 'Original Participant', 'role' => 'participant', 'active' => true]);
    }

    private function package(?int $questionSeconds = null): ExamPackage
    {
        $competency = Competency::create(['code' => 'profile-'.Str::random(8), 'name' => 'Membaca', 'name_en' => 'Reading']);
        $question = Question::create([
            'code' => 'profile-'.Str::random(8), 'competency_id' => $competency->id,
            'type' => 'single_choice', 'title' => 'Preserved response', 'prompt' => 'Choose the Indonesian greeting.',
            'status' => 'active', 'weight' => 1, 'duration_seconds' => $questionSeconds,
            'options' => [['id' => 'a', 'text' => 'Selamat pagi'], ['id' => 'b', 'text' => 'Goodbye']],
            'answer_schema' => ['correct' => 'a'],
        ]);
        $package = ExamPackage::create([
            'code' => 'profile-'.Str::random(8), 'name' => 'Historical Certification', 'test_type' => 'certification',
            'published' => true, 'duration_minutes' => 10, 'attempts_limit' => 1, 'pass_score' => 60,
            'certificate_enabled' => true, 'settings' => ['lock_sections' => false],
        ]);
        ExamSection::create([
            'exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Reading',
            'question_ids' => [$question->id], 'question_count' => 1, 'duration_minutes' => 10, 'randomize' => false, 'weight' => 1,
        ]);

        return $package;
    }

    public function test_updating_own_profile_preserves_completed_results_and_the_original_signed_certificate(): void
    {
        $user = $this->participant();
        $package = $this->package();
        [$session] = $this->login($user);
        $started = $this->browser($session)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $started->json('attempt.id');
        $key = $started->json('sections.0.questions.0.key');
        $this->browser($session)->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertOk();
        $this->browser($session)->postJson('/api/attempts/'.$id.'/submit')->assertOk()->assertJsonPath('attempt.status', 'completed');

        $attempt = ExamAttempt::findOrFail($id);
        $snapshot = $attempt->getRawOriginal('snapshot');
        $result = $attempt->getRawOriginal('result');
        $answer = $attempt->answers()->firstOrFail()->getRawOriginal('response');
        $certificate = Certificate::where('exam_attempt_id', $id)->firstOrFail();
        $payload = $certificate->getRawOriginal('payload');
        $signature = $certificate->signature;
        $verification = $this->browser()->getJson('/api/verify/'.$certificate->token)->assertOk()
            ->assertJsonPath('status', 'valid')->assertJsonPath('certificate.holder_name', 'Original Participant')->json();

        $updated = $this->browser($session)->putJson('/api/account', ['name' => 'Updated Participant', 'email' => 'updated-participant@example.test'])
            ->assertOk()->assertJsonPath('user.name', 'Updated Participant')->assertJsonPath('user.email', 'updated-participant@example.test');
        $previousSession = $session;
        $session = $updated->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($previousSession, $session);
        $this->browser($previousSession)->getJson('/api/dashboard')->assertUnauthorized();
        $this->browser($session)->getJson('/api/dashboard')->assertOk();
        $this->browser($session)->getJson('/api/results/'.$id)->assertOk()->assertJsonPath('attempt.score', 100)
            ->assertJsonPath('certificate.payload.holder_name', 'Original Participant');
        $this->assertSame($snapshot, $attempt->fresh()->getRawOriginal('snapshot'));
        $this->assertSame('Original Participant', $attempt->fresh()->snapshot['participant_name']);
        $this->assertSame($result, $attempt->fresh()->getRawOriginal('result'));
        $this->assertSame($answer, $attempt->answers()->firstOrFail()->getRawOriginal('response'));
        $this->assertSame($payload, $certificate->fresh()->getRawOriginal('payload'));
        $this->assertSame($signature, $certificate->fresh()->signature);
        $this->assertTrue(app(CertificateService::class)->signatureIsValid($certificate->fresh()));
        $this->assertSame($certificate->id, app(CertificateService::class)->issue($attempt->fresh())->id);
        $this->assertSame($verification, $this->browser()->getJson('/api/verify/'.$certificate->token)->assertOk()->json());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.profile_updated', 'user_id' => $user->id]);
        $this->assertSame(1, AuditLog::where('event', 'certificate.issued')->count());

        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser()->postJson('/api/login', ['email' => 'updated-participant@example.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_logout_preserves_saved_answers_and_resuming_never_extends_exam_or_question_deadlines(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->participant();
        $package = $this->package(120);
        [$session, $csrf] = $this->login($user);
        $started = $this->browser($session)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $started->json('attempt.id');
        $key = $started->json('sections.0.questions.0.key');
        $this->browser($session)->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a', 'marked' => true])->assertOk();
        $attempt = ExamAttempt::findOrFail($id);
        $examDeadline = $attempt->getRawOriginal('expires_at');
        $questionDeadline = $attempt->answers()->firstOrFail()->getRawOriginal('question_expires_at');

        $logout = $this->browser($session)->postJson('/api/logout')->assertOk();
        $this->assertNotSame($csrf, $logout->json('csrf_token'));
        $this->assertNotSame($session, $logout->getCookie(config('session.cookie'))->getValue());
        $this->assertDatabaseMissing('sessions', ['id' => $session]);
        $this->assertSame('a', $attempt->answers()->firstOrFail()->response);
        $this->assertSame('in_progress', $attempt->fresh()->status);
        $this->browser($session)->getJson('/api/dashboard')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->browser($session)->getJson('/api/attempts/'.$id)->assertUnauthorized();
        $this->browser($session)->get('/dashboard')->assertRedirect('/login')->assertHeader('Cache-Control', 'no-store, private');

        $this->travel(30)->seconds();
        [$resumedSession] = $this->login($user);
        $this->browser($resumedSession)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated()
            ->assertJsonPath('attempt.id', $id)->assertJsonPath('attempt.status', 'in_progress')
            ->assertJsonPath('answers.'.$key.'.response', 'a')->assertJsonPath('answers.'.$key.'.marked', true);
        $this->assertSame($examDeadline, $attempt->fresh()->getRawOriginal('expires_at'));
        $this->assertSame($questionDeadline, $attempt->answers()->firstOrFail()->getRawOriginal('question_expires_at'));
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_answers', 1);

        $this->travel(91)->seconds();
        $this->browser($resumedSession)->postJson('/api/attempts/'.$id.'/questions/'.$key.'/activate')
            ->assertUnprocessable()->assertJsonPath('code', 'question_expired');
        $this->browser($resumedSession)->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'b'])
            ->assertUnprocessable()->assertJsonPath('code', 'question_expired');
        $this->assertSame('a', $attempt->answers()->firstOrFail()->response);
        $this->assertSame($questionDeadline, $attempt->answers()->firstOrFail()->getRawOriginal('question_expires_at'));

        $this->browser($resumedSession)->postJson('/api/logout')->assertOk();
        $this->travelTo($attempt->expires_at->addSecond());
        [$expiredSession] = $this->login($user);
        $this->browser($expiredSession)->getJson('/api/attempts/'.$id)->assertOk()->assertJsonPath('attempt.status', 'completed');
        $this->browser($expiredSession)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->browser($expiredSession)->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'b'])->assertUnprocessable();
        $this->assertSame($examDeadline, $attempt->fresh()->getRawOriginal('expires_at'));
        $this->assertSame('a', $attempt->answers()->firstOrFail()->response);
        $this->assertSame(100.0, $attempt->fresh()->score);
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->travelBack();
    }

    public function test_participant_profile_access_cannot_target_another_user_or_unlock_admin_resources(): void
    {
        $user = $this->participant();
        $other = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $original = $other->fresh()->getAttributes();
        [$session] = $this->login($user);
        $this->browser($session)->getJson('/api/account?user_id='.$other->id)->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'participant');
        $this->browser($session)->putJson('/api/account', ['name' => 'Unauthorized edit', 'email' => 'unauthorized@example.test', 'user_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->browser($session)->putJson('/api/account', ['name' => $user->name, 'email' => $user->email, 'role' => 'superadmin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->browser($session)->getJson('/api/admin/settings')->assertForbidden();
        $this->browser($session)->getJson('/api/admin/users')->assertForbidden();
        $this->browser($session)->get('/admin')->assertForbidden();
        $this->browser($session)->get('/admin/profile')->assertForbidden();
        $this->assertSame($original, $other->fresh()->getAttributes());
        $this->assertSame('participant', $user->fresh()->role);
        $this->assertSame('Original Participant', $user->fresh()->name);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'account.profile_updated')->count());
    }
}
