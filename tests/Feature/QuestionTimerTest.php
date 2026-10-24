<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Competency;
use App\Models\ExamAttempt;
use App\Models\ExamPackage;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_is_owned_immutable_and_uses_snapshot_duration_and_late_writes_preserve_last_answer(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $package, $question] = $this->fixture(5);
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $start->json('attempt.id');
        $key = $start->json('sections.0.questions.0.key');
        $question->update(['duration_seconds' => 120]);
        $path = '/api/attempts/'.$id.'/questions/'.$key.'/activate';
        $activation = $this->postJson($path)->assertOk();
        $deadline = $activation->json('question_expires_at');
        $this->assertSame(now()->addSeconds(5)->toISOString(), $deadline);
        $this->assertSame($deadline, $activation->json('answer.question_expires_at'));
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->postJson($path)->assertForbidden();
        $this->actingAs($user)->postJson('/api/attempts/'.$id.'/questions/foreign-key/activate')->assertUnprocessable();
        $this->travel(2)->seconds();
        $this->postJson($path)->assertOk()->assertJsonPath('question_expires_at', $deadline)
            ->assertJsonPath('question_started_at', $activation->json('question_started_at'));
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertOk();
        $this->travel(3)->seconds();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'b'])->assertUnprocessable()
            ->assertJsonPath('code', 'question_expired')->assertJsonPath('expired_question_key', $key)->assertJsonPath('answer.response', 'a');
        $this->postJson($path)->assertUnprocessable()->assertJsonPath('code', 'question_expired');
        $attempt = ExamAttempt::findOrFail($id);
        $this->assertSame('a', $attempt->answers()->firstOrFail()->response);
        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame(1, AuditLog::where('event', 'question.activated')->count());
        $this->getJson('/api/attempts/'.$id)->assertOk()->assertJsonPath('answers.'.$key.'.question_expires_at', $deadline);
        $this->travelBack();
    }

    public function test_question_deadlines_cannot_outlive_locked_section_or_attempt_and_future_sections_cannot_activate(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $package] = $this->fixture(600, 2, 1);
        $section = $package->sections()->firstOrFail()->replicate();
        $section->position = 1;
        $section->save();
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $start->json('attempt.id');
        $first = $start->json('sections.0.questions.0.key');
        $second = $start->json('sections.1.questions.0.key');
        $this->postJson('/api/attempts/'.$id.'/questions/'.$first.'/activate')->assertOk()
            ->assertJsonPath('question_expires_at', $start->json('sections.0.ends_at'));
        $this->postJson('/api/attempts/'.$id.'/questions/'.$second.'/activate')->assertUnprocessable()->assertJsonPath('code', 'section_not_started');
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $first, 'response' => 'a'])->assertOk();
        $this->travel(60)->seconds();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $first, 'response' => 'b'])->assertUnprocessable()
            ->assertJsonPath('code', 'section_closed')->assertJsonPath('expired_question_key', $first)
            ->assertJsonPath('answer.response', 'a')->assertJsonPath('server_time', now()->toISOString());
        $this->postJson('/api/attempts/'.$id.'/questions/'.$second.'/activate')->assertOk()
            ->assertJsonPath('question_expires_at', $start->json('attempt.expires_at'));
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $second, 'response' => 'a'])->assertOk();
        $this->assertSame('a', ExamAttempt::findOrFail($id)->answers()->where('question_key', $first)->firstOrFail()->response);
        $this->travel(60)->seconds();
        $this->postJson('/api/attempts/'.$id.'/questions/'.$second.'/activate')->assertUnprocessable();
        $this->assertSame('completed', ExamAttempt::findOrFail($id)->status);
        $this->travelBack();
    }

    public function test_first_save_starts_a_bounded_question_clock_and_unlimited_questions_remain_unlimited(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $package] = $this->fixture(3);
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $start->json('attempt.id');
        $key = $start->json('sections.0.questions.0.key');
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertOk()
            ->assertJsonPath('answer.question_expires_at', now()->addSeconds(3)->toISOString());
        $this->travel(3)->seconds();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'b'])->assertUnprocessable()->assertJsonPath('code', 'question_expired');
        $this->travelBack();

        [$user, $package] = $this->fixture(null);
        $start = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $start->json('attempt.id');
        $key = $start->json('sections.0.questions.0.key');
        $this->postJson('/api/attempts/'.$id.'/questions/'.$key.'/activate')->assertOk()->assertJsonPath('question_expires_at', null);
        $this->travel(5)->minutes();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => 'a'])->assertOk()->assertJsonPath('answer.question_expires_at', null);
        $this->travelBack();
    }

    private function fixture(?int $seconds, int $minutes = 60, int $sectionMinutes = 60): array
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $suffix = bin2hex(random_bytes(4));
        $competency = Competency::create(['code' => 'grammar-'.$suffix, 'name' => 'Tata Bahasa', 'name_en' => 'Grammar', 'active' => true]);
        $question = Question::create(['code' => 'Q-'.$suffix, 'competency_id' => $competency->id, 'type' => 'single_choice', 'title' => 'Timed question', 'prompt' => 'Pilih sapaan yang benar.', 'status' => 'active', 'duration_seconds' => $seconds, 'options' => [['id' => 'a', 'text' => 'Selamat pagi'], ['id' => 'b', 'text' => 'Goodbye']], 'answer_schema' => ['correct' => 'a']]);
        $package = ExamPackage::create(['code' => 'TIMER-'.$suffix, 'name' => 'Timed Assessment', 'published' => true, 'duration_minutes' => $minutes, 'attempts_limit' => 1, 'certificate_enabled' => false, 'settings' => ['lock_sections' => true]]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Grammar', 'duration_minutes' => $sectionMinutes, 'question_count' => 1, 'question_ids' => [$question->id], 'randomize' => false]);

        return [$user, $package, $question];
    }
}
