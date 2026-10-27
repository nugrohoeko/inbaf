<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamAttempt, ExamPackage, Question, QuestionType, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionSchemaSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $type, array $schema, array $metadata = []): array
    {
        $competency = Competency::firstOrCreate(['code' => 'SCHEMA-QA'], ['name' => 'Grammar', 'name_en' => 'Grammar', 'active' => true]);
        QuestionType::firstOrCreate(['code' => $type], ['name' => $type, 'automatic' => true]);

        return ['code' => 'SCHEMA-QA', 'title' => 'Schema QA', 'competency_id' => $competency->id, 'type' => $type, 'prompt' => 'Respond.', 'difficulty' => 'easy', 'weight' => 1, 'status' => 'active', 'options' => [], 'answer_schema' => $schema, 'metadata' => $metadata];
    }

    public static function malformedConfigurations(): array
    {
        return [
            'accepted string' => ['short_answer', ['accepted' => 'betul'], [], 'answer_schema.accepted'],
            'nested accepted array' => ['short_answer', ['accepted' => [['betul']]], [], 'answer_schema.accepted'],
            'accepted object instead of list' => ['short_answer', ['accepted' => ['label' => 'betul']], [], 'answer_schema.accepted'],
            'nested short answer key' => ['short_answer', ['correct' => [['betul']]], [], 'answer_schema.correct'],
            'deep blank aliases' => ['fill_blank', ['correct' => ['gap' => [['betul']]]], [], 'answer_schema.correct'],
            'blanks string' => ['fill_blank', ['correct' => ['gap' => 'betul']], ['blanks' => 'gap'], 'metadata.blanks'],
            'blanks object instead of list' => ['fill_blank', ['correct' => ['gap' => 'betul']], ['blanks' => ['gap' => ['id' => 'gap']]], 'metadata.blanks'],
            'blank is string' => ['fill_blank', ['correct' => ['gap' => 'betul']], ['blanks' => ['gap']], 'metadata.blanks.0'],
            'blank options string' => ['dropdown', ['correct' => ['gap' => 'a']], ['blanks' => [['id' => 'gap', 'options' => 'a']]], 'metadata.blanks.0.options'],
            'blank options object instead of list' => ['dropdown', ['correct' => ['gap' => 'a']], ['blanks' => [['id' => 'gap', 'options' => ['a' => ['id' => 'a']]]]], 'metadata.blanks.0.options'],
            'blank option is string' => ['dropdown', ['correct' => ['gap' => 'a']], ['blanks' => [['id' => 'gap', 'options' => ['a']]]], 'metadata.blanks.0.options.0'],
            'matching source items string' => ['matching', ['correct' => ['left' => 'right']], ['left_items' => 'left', 'right_items' => [['id' => 'right']]], 'metadata.left_items'],
            'matching item identifier nested' => ['matching', ['correct' => ['left' => 'right']], ['left_items' => [['id' => ['left']]], 'right_items' => [['id' => 'right']]], 'metadata.left_items.0.id'],
        ];
    }

    #[DataProvider('malformedConfigurations')]
    public function test_malformed_configuration_is_rejected_by_crud_without_partial_question_or_version(string $type, array $schema, array $metadata, string $field): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $this->postJson('/api/admin/questions', $this->payload($type, $schema, $metadata))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_import_rejects_malformed_accepted_answers_before_writing_any_valid_row(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $data = $this->payload('short_answer', ['accepted' => ['betul']]);
        $headers = ['code', 'title', 'competency_id', 'type', 'difficulty', 'weight', 'status', 'prompt', 'instructions', 'options', 'answer_schema', 'metadata', 'question_group_id'];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, ',', '"', '');
        foreach ([['accepted' => ['betul']], ['accepted' => 'betul']] as $index => $schema) {
            $row = ['SCHEMA-IMPORT-'.$index, $data['title'], $data['competency_id'], 'short_answer', 'easy', 1, 'active', 'Respond.', '', '[]', json_encode($schema, JSON_THROW_ON_ERROR), '{}', ''];
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        $this->postJson('/api/admin/questions/import', ['file' => UploadedFile::fake()->createWithContent('questions.csv', $csv)])
            ->assertUnprocessable()->assertJsonValidationErrors('row_3');
        $this->assertDatabaseCount('questions', 0);
        $this->assertDatabaseCount('question_versions', 0);
    }

    public static function supportedLegacyAnswers(): array
    {
        return [
            'short alternative correct list' => ['short_answer', ['correct' => ['betul', 'benar']], [], 'BENAR', 1.0],
            'fill alias map' => ['fill_blank', ['correct' => ['gap' => ['makan', 'menyantap']]], [], ['gap' => 'MENYANTAP'], 1.0],
            'fill positional alias list' => ['fill_blank', ['correct' => [['makan', 'menyantap'], 'nasi']], [], ['menyantap', 'nasi'], 1.0],
            'dropdown map with option objects' => ['dropdown', ['correct' => ['gap' => 'a']], ['blanks' => [['id' => 'gap', 'options' => [['id' => 'a', 'text' => 'karena']]]]], ['gap' => 'a'], 1.0],
        ];
    }

    #[DataProvider('supportedLegacyAnswers')]
    public function test_supported_alias_and_mapping_shapes_keep_their_existing_grade(string $type, array $schema, array $metadata, mixed $response, float $expected): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'active' => true]));
        $this->postJson('/api/admin/questions', $this->payload($type, $schema, $metadata))->assertCreated();
        $this->assertSame($expected, app(\App\Services\ScoringService::class)->scoreQuestion(['type' => $type, 'answer_schema' => $schema, 'metadata' => $metadata], $response));
    }

    public static function malformedHistoricalKeys(): array
    {
        return [
            'accepted string' => ['short_answer', ['accepted' => 'betul'], [], 'betul', 'answer_schema.accepted'],
            'nested correct list' => ['short_answer', ['correct' => [['betul']]], [], '__invalid__', 'answer_schema.correct'],
            'nested fill alias' => ['fill_blank', ['correct' => ['gap' => [['betul']]]], [], ['gap' => '__invalid__'], 'answer_schema.correct'],
            'malformed blanks with empty response' => ['fill_blank', ['correct' => ['gap' => 'betul']], ['blanks' => 'gap'], null, 'metadata.blanks'],
        ];
    }

    #[DataProvider('malformedHistoricalKeys')]
    public function test_malformed_historical_snapshot_rolls_back_submit_instead_of_silently_assigning_zero(string $type, array $schema, array $metadata, mixed $response, string $field): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $question = Question::create($this->payload($type, $schema, $metadata));
        $package = ExamPackage::create(['code' => 'SCHEMA-PKG', 'name' => 'Historical malformed schema', 'test_type' => 'practice', 'certificate_enabled' => false]);
        $snapshotQuestion = $question->toArray() + ['key' => 'schema-question'];
        $attempt = ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addMinutes(10), 'snapshot' => ['test_type' => 'practice', 'package' => ['id' => $package->id, 'name' => $package->name, 'pass_score' => 60], 'rules' => ['lock_sections' => false], 'sections' => [['id' => 1, 'competency_id' => $question->competency_id, 'title' => 'Grammar', 'weight' => 1, 'questions' => [$snapshotQuestion]]]]]);
        $attempt->answers()->create(['question_id' => $question->id, 'question_key' => 'schema-question', 'response' => $response, 'requires_manual' => false]);
        $this->actingAs($user)->postJson('/api/attempts/'.$attempt->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('in_progress', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->submitted_at);
        $this->assertNull($attempt->fresh()->score);
        $this->assertNull($attempt->fresh()->result);
        $this->assertSame($response, $attempt->answers()->first()->response);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
