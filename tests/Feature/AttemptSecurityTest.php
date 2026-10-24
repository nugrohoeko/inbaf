<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamAttempt, ExamPackage, ExamSection, MediaAsset, Question, QuestionGroup, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $settings = []): array
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $competency = Competency::create(['code' => 'comp-'.Str::random(8), 'name' => 'Tata Bahasa', 'name_en' => 'Grammar']);
        $question = Question::create(['code' => 'q-'.Str::random(8), 'competency_id' => $competency->id, 'type' => 'single_choice', 'title' => 'Sample', 'prompt' => 'Choose a greeting.', 'status' => 'active', 'options' => [['id' => 'a', 'text' => 'Selamat pagi']], 'answer_schema' => ['correct' => 'a']]);
        $package = ExamPackage::create(['code' => 'pkg-'.Str::random(8), 'name' => 'Secure Exam', 'published' => true, 'duration_minutes' => 10, 'attempts_limit' => 5, 'certificate_enabled' => false, 'settings' => $settings]);
        $section = ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'First session', 'duration_minutes' => 5, 'question_count' => 1, 'question_ids' => [$question->id], 'randomize' => false]);
        return [$user, $package, $question, $section];
    }

    public function test_access_codes_are_enforced_and_redacted_across_participant_responses(): void
    {
        [$user, $package] = $this->fixture(['access_code' => 'PRIVATE-CODE', 'lock_sections' => false]);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('packages.0.requires_access_code', true)->assertDontSee('PRIVATE-CODE');
        $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertUnprocessable();
        $this->postJson('/api/packages/'.$package->id.'/attempts', ['access_code' => 'WRONG'])->assertUnprocessable();
        $response = $this->postJson('/api/packages/'.$package->id.'/attempts', ['access_code' => 'PRIVATE-CODE'])->assertCreated()->assertDontSee('PRIVATE-CODE');
        $id = $response->json('attempt.id');
        $this->getJson('/api/dashboard')->assertOk()->assertDontSee('PRIVATE-CODE');
        $this->postJson('/api/attempts/'.$id.'/submit')->assertOk()->assertDontSee('PRIVATE-CODE');
        $this->getJson('/api/results')->assertOk()->assertDontSee('PRIVATE-CODE');
        $this->getJson('/api/results/'.$id)->assertOk()->assertDontSee('PRIVATE-CODE');
    }

    public function test_answer_bearing_options_metadata_and_audio_transcripts_are_not_exposed(): void
    {
        [$user, $package, $question] = $this->fixture(['lock_sections' => false]);
        $group = QuestionGroup::create(['title' => 'Audio stimulus', 'kind' => 'audio', 'content' => 'SECRET_TRANSCRIPT', 'metadata' => ['max_plays' => 2]]);
        $question->update(['question_group_id' => $group->id, 'options' => [['id' => 'a', 'text' => 'Selamat pagi', 'match_id' => 'SECRET_KEY']], 'metadata' => ['matches' => [['id' => 'a', 'text' => 'Choice', 'correct' => 'SECRET_KEY']], 'max_plays' => 2]]);
        $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated()
            ->assertDontSee('SECRET_TRANSCRIPT')->assertDontSee('SECRET_KEY')->assertDontSee('answer_schema')
            ->assertJsonPath('sections.0.questions.0.metadata.max_plays', 2)
            ->assertJsonPath('sections.0.questions.0.group.metadata.max_plays', 2);
    }

    public function test_server_rejects_future_and_closed_section_answers(): void
    {
        [$user, $package, $question, $first] = $this->fixture(['lock_sections' => true]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $question->competency_id, 'title' => 'Second session', 'position' => 2, 'duration_minutes' => 5, 'question_count' => 1, 'question_ids' => [$question->id], 'randomize' => false]);
        $response = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $response->json('attempt.id'); $firstKey = $response->json('sections.0.questions.0.key'); $secondKey = $response->json('sections.1.questions.0.key');
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $secondKey, 'response' => 'a'])->assertUnprocessable();
        $this->travel(6)->minutes();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $firstKey, 'response' => 'a'])->assertUnprocessable();
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $secondKey, 'response' => 'a'])->assertOk();
        $this->travelBack();
    }

    public function test_speaking_responses_cannot_reference_another_users_recording(): void
    {
        [$user, $package, $question] = $this->fixture(['lock_sections' => false]);
        $question->update(['type' => 'speaking']);
        $response = $this->actingAs($user)->postJson('/api/packages/'.$package->id.'/attempts')->assertCreated();
        $id = $response->json('attempt.id'); $key = $response->json('sections.0.questions.0.key');
        $other = User::factory()->create();
        $media = MediaAsset::create(['path' => 'recordings/private.webm', 'original_name' => 'private.webm', 'mime' => 'audio/webm', 'size' => 20, 'kind' => 'audio', 'metadata' => ['user_id' => $other->id, 'exam_attempt_id' => $id, 'question_key' => $key]]);
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => ['media_asset_id' => $media->id]])->assertUnprocessable();
        $media->update(['metadata' => ['user_id' => $user->id, 'exam_attempt_id' => $id, 'question_key' => $key]]);
        $this->putJson('/api/attempts/'.$id.'/answers', ['question_key' => $key, 'response' => ['media_asset_id' => $media->id, 'url' => 'https://invalid.test']])->assertOk()->assertJsonPath('answer.response.url', url('/api/media/'.$media->id));
    }
}
