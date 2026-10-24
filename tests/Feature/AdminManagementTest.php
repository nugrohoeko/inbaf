<?php

namespace Tests\Feature;

use App\Models\{Competency, Question, QuestionType, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin', 'active' => true]);
    }

    private function questionPayload(): array
    {
        $competency = Competency::create(['code' => 'grammar', 'name' => 'Tata Bahasa', 'name_en' => 'Grammar', 'active' => true]);
        QuestionType::create(['code' => 'single_choice', 'name' => 'Single choice', 'automatic' => true]);
        return ['code' => 'Q-TEST', 'title' => 'Struktur kalimat', 'competency_id' => $competency->id, 'type' => 'single_choice', 'difficulty' => 'easy', 'weight' => 1, 'status' => 'active', 'prompt' => '<p>Pilih kalimat yang benar.</p>', 'options' => [['id' => 'a', 'text' => 'Saya belajar.'], ['id' => 'b', 'text' => 'Saya belajar di.']], 'answer_schema' => ['correct' => 'a'], 'metadata' => []];
    }

    public function test_participants_cannot_read_or_mutate_admin_resources(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'active' => true]);
        $this->actingAs($participant)->getJson('/api/admin/questions')->assertForbidden();
        $this->actingAs($participant)->putJson('/api/admin/settings', ['registration_enabled' => false])->assertForbidden();
        $this->actingAs($participant)->postJson('/api/admin/participants', ['role' => 'superadmin'])->assertForbidden();
    }

    public function test_question_edits_create_versions_and_sanitize_rich_content(): void
    {
        $this->actingAs($this->admin());
        $data = $this->questionPayload();
        $data['prompt'] = '<p onclick="alert(1)">Pilih.</p><script>secret()</script>';
        $id = $this->postJson('/api/admin/questions', $data)->assertCreated()->json('id');
        $this->assertSame('<p>Pilih.</p>', Question::findOrFail($id)->prompt);
        $data['title'] = 'Updated';
        $this->putJson('/api/admin/questions/'.$id, $data)->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseCount('question_versions', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'questions.updated', 'subject_id' => (string) $id]);
    }

    public function test_active_question_requires_a_valid_key_and_archive_preserves_history(): void
    {
        $this->actingAs($this->admin());
        $data = $this->questionPayload(); $data['answer_schema'] = ['correct' => 'missing'];
        $this->postJson('/api/admin/questions', $data)->assertUnprocessable()->assertJsonValidationErrors('answer_schema');
        $data['answer_schema']['correct'] = 'a';
        $id = $this->postJson('/api/admin/questions', $data)->assertCreated()->json('id');
        $this->deleteJson('/api/admin/questions/'.$id)->assertOk();
        $this->assertDatabaseHas('questions', ['id' => $id, 'status' => 'archived']);
        $this->assertDatabaseCount('question_versions', 2);
    }

    public function test_settings_are_validated_and_unknown_secrets_never_persist(): void
    {
        $this->actingAs($this->admin());
        $this->putJson('/api/admin/settings', ['primary_color' => 'url(javascript:x)'])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['registration_enabled' => false, 'database_password' => 'never-save'])->assertOk()->assertJsonPath('registration_enabled', false);
        $this->assertDatabaseMissing('system_settings', ['key' => 'database_password']);
        $this->postJson('/api/register', ['name' => 'Another User', 'email' => 'another@example.test', 'password' => 'A-secure-password', 'password_confirmation' => 'A-secure-password'])->assertForbidden();
    }

    public function test_media_uploads_are_private_and_unrelated_participants_cannot_stream(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin());
        $file = UploadedFile::fake()->image('stimulus.png', 200, 100);
        $id = $this->postJson('/api/admin/media', ['file' => $file])->assertCreated()->json('id');
        $this->get('/api/media/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]));
        $this->getJson('/api/media/'.$id)->assertForbidden();
        $this->postJson('/api/admin/media', ['file' => $file])->assertForbidden();
    }

    public function test_excel_import_validates_all_rows_before_creating_questions(): void
    {
        $this->actingAs($this->admin());
        $data = $this->questionPayload();
        $headers = ['code','title','competency_id','type','difficulty','weight','status','prompt','instructions','options','answer_schema','metadata','question_group_id'];
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $row = ['Q-XLSX-1','Excel question',$data['competency_id'],'single_choice','easy',1,'active','Pilih kalimat yang benar.','',json_encode($data['options']),json_encode($data['answer_schema']),'{}',''];
        $invalid = $row; $invalid[0] = 'Q-XLSX-2'; $invalid[3] = 'unknown_type';
        $sheet->fromArray([$headers,$row,$invalid], null, 'A1');
        $makeUpload = function () use ($book) {
            ob_start(); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
            return UploadedFile::fake()->createWithContent('questions.xlsx', ob_get_clean());
        };
        $this->postJson('/api/admin/questions/import', ['file' => $makeUpload()])->assertUnprocessable();
        $this->assertDatabaseCount('questions', 0);
        $sheet->setCellValue('D3','single_choice');
        $this->postJson('/api/admin/questions/import', ['file' => $makeUpload()])->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseCount('questions', 2);
        $this->assertDatabaseCount('question_versions', 2);
        $book->disconnectWorksheets();
    }

    public function test_level_thresholds_are_unique_and_keep_a_zero_score_classification(): void
    {
        $this->actingAs($this->admin());
        $level = ['code' => 'A1', 'name' => 'Beginner', 'min_score' => 10, 'description' => 'Internal level', 'sort_order' => 0];
        $this->postJson('/api/admin/levels', $level)->assertUnprocessable();
        $level['min_score'] = 0;
        $id = $this->postJson('/api/admin/levels', $level)->assertCreated()->json('id');
        $this->postJson('/api/admin/levels', [...$level, 'code' => 'A2'])->assertUnprocessable();
        $this->putJson('/api/admin/levels/'.$id, [...$level, 'min_score' => 10])->assertUnprocessable();
    }
}
