<?php

namespace Tests\Feature;

use App\Models\{ExamAttempt, ExamPackage, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(User $user, string $status, string $name): ExamAttempt
    {
        $package = ExamPackage::create(['code' => 'FEED-'.bin2hex(random_bytes(4)), 'name' => $name, 'test_type' => 'practice']);
        return ExamAttempt::create(['user_id' => $user->id, 'exam_package_id' => $package->id, 'test_type' => 'practice', 'status' => $status, 'started_at' => now(), 'expires_at' => null, 'snapshot' => ['package' => ['name' => $name], 'sections' => []]]);
    }

    public function test_feed_requires_authentication_and_empty_feed_has_no_fake_items(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'participant', 'active' => true]))->getJson('/api/notifications')->assertOk()->assertExactJson(['items' => []])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_participant_sees_only_own_activity_with_no_answers_or_identity_leaks(): void
    {
        $own = User::factory()->create(['role' => 'participant', 'active' => true]);
        $other = User::factory()->create(['role' => 'participant', 'active' => true]);
        $attempt = $this->attempt($own, 'in_progress', 'Own assessment');
        $foreign = $this->attempt($other, 'pending_review', 'Private foreign assessment');
        $response = $this->actingAs($own)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.url', '/exam/'.$attempt->id);
        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString($other->email, $response->getContent());
        $response->assertJsonMissingPath('items.0.snapshot')->assertJsonMissingPath('items.0.response');
    }

    public function test_admin_feed_uses_real_review_counts_and_participants_cannot_see_them(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'active' => true]);
        $attempt = $this->attempt($participant, 'pending_review', 'Manual assessment');
        $attempt->answers()->create(['question_key' => 'essay', 'requires_manual' => true, 'response' => 'Private learner text']);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $response = $this->actingAs($admin)->getJson('/api/notifications')->assertOk()->assertJsonPath('items.0.type', 'admin_reviews')->assertJsonPath('items.0.count', 1)->assertJsonPath('items.0.url', '/admin/reviews');
        $this->assertStringNotContainsString('Private learner text', $response->getContent());
        $attempt->answers()->update(['manual_score' => 80]);
        $this->getJson('/api/notifications')->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_feed_is_limited_and_preserves_safe_result_navigation(): void
    {
        $participant = User::factory()->create(['role' => 'participant', 'active' => true]);
        for ($i = 0; $i < 12; $i++) $this->attempt($participant, 'completed', 'Assessment '.$i);
        $items = $this->actingAs($participant)->getJson('/api/notifications')->assertOk()->assertJsonCount(10, 'items')->json('items');
        foreach ($items as $item) $this->assertStringStartsWith('/results/', $item['url']);
    }
}
