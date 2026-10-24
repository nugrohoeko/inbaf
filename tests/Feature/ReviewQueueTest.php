<?php

namespace Tests\Feature;

use App\Models\{Competency, ExamPackage, ExamSection, Question, User};
use App\Services\AttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_queue_filters_before_pagination_and_removes_graded_rows(): void
    {
        $learner = User::factory()->create(['role' => 'participant', 'active' => true]);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $competency = Competency::create(['code' => 'writing', 'name' => 'Menulis', 'name_en' => 'Writing']);
        $ids = [];
        // Objective answers are created last so they would occupy the first descending page.
        foreach (['essay', 'short_answer', 'essay', ...array_fill(0, 25, 'single_choice')] as $index => $type) {
            $question = Question::create(['code' => 'REVIEW-'.$index, 'competency_id' => $competency->id, 'type' => $type, 'title' => 'Question '.$index, 'prompt' => 'Respond.', 'status' => 'active', 'metadata' => $type === 'short_answer' ? ['manual' => true] : [], 'answer_schema' => ['correct' => 'a']]);
            $ids[] = $question->id;
        }
        $package = ExamPackage::create(['code' => 'REVIEW-QUEUE', 'name' => 'Review Queue Test', 'published' => true, 'duration_minutes' => 30, 'certificate_enabled' => false, 'settings' => ['lock_sections' => false]]);
        ExamSection::create(['exam_package_id' => $package->id, 'competency_id' => $competency->id, 'title' => 'Writing', 'duration_minutes' => 30, 'question_count' => count($ids), 'question_ids' => $ids, 'randomize' => false]);
        $service = app(AttemptService::class);
        $attempt = $service->start($learner, $package);
        $manual = $attempt->answers()->where('requires_manual', true)->get();
        $this->assertCount(3, $manual);
        foreach ($manual->take(2) as $answer) $service->save($attempt, $answer->question_key, 'A meaningful answer for manual assessment.');
        $service->submit($attempt);
        $blank = $manual->last()->fresh();
        $this->assertSame(0.0, $blank->manual_score);
        $this->assertSame('Tidak ada jawaban yang dikirim.', $blank->feedback);

        // Subsequent bank edits cannot turn a snapshotted manual question into an objective one.
        Question::whereIn('id', $manual->pluck('question_id'))->update(['type' => 'single_choice', 'metadata' => '{}']);
        $page = $this->actingAs($admin)->getJson('/api/admin/reviews?per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonCount(1, 'data');
        $firstId = $page->json('data.0.id');
        $this->assertContains($page->json('data.0.question.type'), ['essay', 'short_answer']);
        $pageTwo = $this->getJson('/api/admin/reviews?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotSame($firstId, $pageTwo->json('data.0.id'));

        $this->postJson('/api/admin/reviews/'.$firstId, ['score' => 80, 'feedback' => 'Clear and relevant response.'])->assertOk();
        $this->getJson('/api/admin/reviews?per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('last_page', 1)
            ->assertJsonPath('data.0.id', $pageTwo->json('data.0.id'));
        $this->assertSame('pending_review', $attempt->fresh()->status);
    }
}
