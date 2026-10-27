<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Competency, Question, QuestionGroup, QuestionType, QuestionVersion, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionGroupRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        QuestionType::create(['code' => 'single_choice', 'name' => 'Single choice', 'automatic' => true]);
    }

    private function payload(?int $group = null, string $code = 'GROUP-RACE-Q'): array
    {
        $competency = Competency::firstOrCreate(['code' => 'GROUP-RACE'], ['name' => 'Reading', 'name_en' => 'Reading', 'active' => true]);

        return ['code' => $code, 'title' => 'Reference question', 'competency_id' => $competency->id, 'question_group_id' => $group, 'type' => 'single_choice', 'prompt' => 'Select A', 'status' => 'active', 'weight' => 1, 'difficulty' => 'easy', 'options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'answer_schema' => ['correct' => 'a'], 'metadata' => []];
    }

    private function upload(array $questions): UploadedFile
    {
        $headers = ['code', 'title', 'competency_id', 'type', 'difficulty', 'weight', 'status', 'prompt', 'instructions', 'options', 'answer_schema', 'metadata', 'question_group_id'];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($questions as $question) {
            $values = array_map(function ($field) use ($question) {
                $value = $question[$field] ?? '';

                return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            }, $headers);
            fputcsv($stream, $values, ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('questions.csv', $csv);
    }

    public function test_import_rechecks_a_group_removed_after_validation_and_rolls_back_every_row(): void
    {
        $group = QuestionGroup::create(['title' => 'Racing passage', 'kind' => 'passage']);
        $file = $this->upload([$this->payload(null, 'SAFE-IMPORT'), $this->payload($group->id, 'RACING-IMPORT')]);
        $removed = false;
        DB::listen(function ($query) use ($group, &$removed): void {
            if ($removed || ! str_contains($query->sql, 'question_groups') || ! str_contains(strtolower($query->sql), 'count(*)')) return;
            $removed = true;
            DB::table('question_groups')->where('id', $group->id)->delete();
        });
        $this->postJson('/api/admin/questions/import', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('question_group_id');
        $this->assertTrue($removed);
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_import_can_create_multiple_questions_sharing_a_group_and_captures_every_version(): void
    {
        $group = QuestionGroup::create(['title' => 'Shared passage', 'kind' => 'passage']);
        $file = $this->upload([$this->payload($group->id, 'SHARED-FIRST'), $this->payload($group->id, 'SHARED-SECOND')]);
        $this->postJson('/api/admin/questions/import', ['file' => $file])->assertCreated()->assertJsonPath('count', 2);
        $this->assertSame(2, Question::where('question_group_id', $group->id)->count());
        $this->assertDatabaseCount('question_versions', 2);
        $this->assertSame(1, AuditLog::where('event', 'questions.imported')->count());
    }

    private function afterSourceRead(Question $source, callable $change): void
    {
        $changed = false;
        DB::listen(function ($query) use ($source, $change, &$changed): void {
            if ($changed || ! preg_match('/\bfrom ["`]?questions["`]?\b/i', $query->sql)
                || str_contains(strtolower($query->sql), 'count(*)') || ! in_array((string) $source->id, array_map('strval', $query->bindings), true)) return;
            $changed = true;
            $change();
        });
    }

    public function test_duplicate_rejects_a_group_removed_after_the_source_pointer_read_without_partial_copy(): void
    {
        $group = QuestionGroup::create(['title' => 'Detached passage', 'kind' => 'passage']);
        $source = Question::create($this->payload($group->id));
        QuestionVersion::create(['question_id' => $source->id, 'version' => 1, 'snapshot' => $source->toArray()]);
        $this->afterSourceRead($source, function () use ($source, $group): void {
            DB::table('questions')->where('id', $source->id)->update(['question_group_id' => null]);
            DB::table('question_groups')->where('id', $group->id)->delete();
        });
        $this->postJson('/api/admin/questions/'.$source->id.'/duplicate')->assertUnprocessable()->assertJsonValidationErrors('question_group_id');
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseCount('question_versions', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_rejects_a_changed_group_instead_of_copying_a_stale_parent(): void
    {
        $first = QuestionGroup::create(['title' => 'Original passage', 'kind' => 'passage']);
        $second = QuestionGroup::create(['title' => 'Replacement passage', 'kind' => 'passage']);
        $source = Question::create($this->payload($first->id));
        $this->afterSourceRead($source, fn () => DB::table('questions')->where('id', $source->id)->update(['question_group_id' => $second->id]));
        $this->postJson('/api/admin/questions/'.$source->id.'/duplicate')->assertStatus(409);
        $this->assertSame($second->id, $source->fresh()->question_group_id);
        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseCount('question_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_duplicate_uses_current_source_content_after_the_pointer_read_when_the_group_is_unchanged(): void
    {
        $group = QuestionGroup::create(['title' => 'Stable passage', 'kind' => 'passage']);
        $source = Question::create($this->payload($group->id));
        $this->afterSourceRead($source, fn () => DB::table('questions')->where('id', $source->id)->update(['title' => 'Current source title', 'prompt' => 'Current source prompt']));
        $copy = $this->postJson('/api/admin/questions/'.$source->id.'/duplicate')->assertCreated()
            ->assertJsonPath('title', 'Current source title (copy)')->assertJsonPath('prompt', 'Current source prompt')
            ->assertJsonPath('question_group_id', $group->id)->assertJsonPath('status', 'draft')->json('id');
        $this->assertNotSame($source->id, $copy);
        $this->assertDatabaseHas('question_versions', ['question_id' => $copy, 'version' => 1]);
        $this->assertSame(1, AuditLog::where('event', 'questions.duplicated')->count());
    }

    public function test_question_group_id_rejects_arrays_before_database_writes(): void
    {
        $group = QuestionGroup::create(['title' => 'Scalar reference', 'kind' => 'passage']);
        $payload = $this->payload($group->id);
        $payload['question_group_id'] = [$group->id];
        $this->postJson('/api/admin/questions', $payload)->assertUnprocessable()->assertJsonValidationErrors('question_group_id');
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
    }
}
