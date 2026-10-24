<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_require_an_authenticated_active_account(): void
    {
        $this->putJson('/api/preferences', ['text_size' => 'large'])->assertUnauthorized();

        $user = User::factory()->create(['active' => false]);
        $this->actingAs($user)->putJson('/api/preferences', ['text_size' => 'large'])->assertForbidden();
        $this->assertSame('normal', $user->fresh()->text_size);
    }

    public function test_each_supported_size_updates_only_the_signed_in_users_preference(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $other = User::factory()->create(['text_size' => 'extra_large']);

        foreach (['large', 'extra_large', 'normal'] as $size) {
            $this->actingAs($user)->putJson('/api/preferences', [
                'text_size' => $size, 'user_id' => $other->id, 'role' => 'superadmin',
            ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.text_size', $size);

            $this->assertSame($size, $user->fresh()->text_size);
            $this->assertSame('participant', $user->fresh()->role);
            $this->assertSame('extra_large', $other->fresh()->text_size);
        }
    }

    public function test_invalid_or_missing_sizes_do_not_change_the_saved_preference(): void
    {
        $user = User::factory()->create(['active' => true, 'text_size' => 'large']);
        $this->actingAs($user);

        foreach ([[], ['text_size' => null], ['text_size' => 'giant'], ['text_size' => ['large']]] as $input) {
            $this->putJson('/api/preferences', $input)->assertUnprocessable()->assertJsonValidationErrors('text_size');
            $this->assertSame('large', $user->fresh()->text_size);
        }
    }

    public function test_saved_size_is_restored_by_bootstrap_and_a_new_login(): void
    {
        $user = User::factory()->create(['active' => true]);
        $this->actingAs($user)->putJson('/api/preferences', ['text_size' => 'extra_large'])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.text_size', 'extra_large');
        $this->postJson('/api/logout')->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.text_size', 'extra_large');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.text_size', 'extra_large');
    }

    public function test_new_registrations_return_the_normal_default(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New Learner', 'email' => 'preferences@example.test',
            'password' => 'SecureTestPass123!', 'password_confirmation' => 'SecureTestPass123!',
        ])->assertCreated()->assertJsonPath('user.text_size', 'normal');

        $this->assertDatabaseHas('users', ['email' => 'preferences@example.test', 'text_size' => 'normal']);
    }
}
