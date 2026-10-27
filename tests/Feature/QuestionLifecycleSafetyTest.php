<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Competency, Question, QuestionGroup, QuestionVersion, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(?int $group = null): array
    {
        \App\Models\QuestionType::firstOrCreate(['code' => 'single_choice'], ['name' => 'Single choice', 'automatic' => true]);
        $competency = Competency::create(['code' => 'SAFE', 'name' => 'Reading', 'name_en' => 'Reading', 'active' => true]);
        return ['code' => 'SAFE-Q', 'title' => 'Reference question', 'competency_id' => $competency->id, 'question_group_id' => $group, 'type' => 'single_choice', 'prompt' => 'Select A', 'status' => 'active', 'weight' => 1, 'difficulty' => 'easy', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'answer_schema' => ['correct' => 'a'], 'allowed_test_types' => ['practice']];
    }

    public function test_used_group_cannot_be_removed_and_silently_detach_question_content(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $group = QuestionGroup::create(['title' => 'Required passage', 'kind' => 'passage', 'content' => 'Historical passage']);
        $question = Question::create($this->payload($group->id));
        $this->deleteJson('/api/admin/groups/'.$group->id)->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->assertSame($group->id, $question->fresh()->question_group_id);
        $this->assertDatabaseHas('question_groups', ['id' => $group->id, 'content' => 'Historical passage']);
        $this->assertSame(0, AuditLog::where('event', 'groups.archived')->count());
    }

    public function test_unused_group_can_still_be_removed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $group = QuestionGroup::create(['title' => 'Unused passage', 'kind' => 'passage']);
        $this->deleteJson('/api/admin/groups/'.$group->id)->assertOk();
        $this->assertDatabaseMissing('question_groups', ['id' => $group->id]);
    }

    public function test_repeated_question_archive_is_one_logical_version_and_audit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $question = Question::create($this->payload());
        QuestionVersion::create(['question_id' => $question->id, 'version' => 1, 'snapshot' => $question->toArray()]);
        $this->deleteJson('/api/admin/questions/'.$question->id)->assertOk();
        $this->deleteJson('/api/admin/questions/'.$question->id)->assertOk();
        $this->assertSame(2, $question->fresh()->version);
        $this->assertSame('archived', $question->fresh()->status);
        $this->assertDatabaseCount('question_versions', 2);
        $this->assertSame(1, AuditLog::where('event', 'questions.archived')->count());
    }

    public function test_group_removed_after_validation_returns_field_error_without_partial_question_creation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $group = QuestionGroup::create(['title' => 'Racing passage', 'kind' => 'passage']);
        $payload = $this->payload($group->id); $removed = false;
        DB::listen(function ($query) use ($group, &$removed): void {
            if (! $removed && str_contains($query->sql, 'question_groups') && str_contains(strtolower($query->sql), 'count(*)')) {
                $removed = true;
                DB::table('question_groups')->where('id', $group->id)->delete();
            }
        });
        $this->postJson('/api/admin/questions', $payload)->assertUnprocessable()->assertJsonValidationErrors('question_group_id');
        $this->assertTrue($removed);
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
    }
}
