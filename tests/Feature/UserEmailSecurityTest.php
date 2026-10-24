<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Hash, Password, Storage};
use Tests\TestCase;

class UserEmailSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'participant', 'active' => true, ...$attributes]);
    }

    public function test_email_change_invalidates_old_address_token_before_another_account_can_reuse_it(): void
    {
        foreach (['participant', 'superadmin'] as $role) {
            Auth::forgetGuards();
            $this->flushSession();
            $user = $this->user(['role' => $role, 'email' => $role.'-old@example.test', 'email_verified_at' => now()]);
            $passwordHash = $user->password;
            $oldToken = Password::broker()->createToken($user);
            $oldSession = $this->app['session']->driver()->getId();
            $this->actingAs($user)->withSession(['user_session_version' => 1]);
            $response = $this->putJson('/api/account', ['name' => $user->name, 'email' => strtoupper($role).'-NEW@EXAMPLE.TEST'])
                ->assertOk()->assertJsonPath('user.email', $role.'-new@example.test')->assertJsonPath('user.email_verified_at', null)
                ->assertJsonMissingPath('user.session_version')->assertJsonMissingPath('user.remember_token');
            $fresh = $user->fresh();
            $this->assertSame(2, $fresh->session_version);
            $this->assertSame($passwordHash, $fresh->password);
            $this->assertNotNull($fresh->remember_token);
            $this->assertNotSame($oldSession, $this->app['session']->driver()->getId());
            $this->assertSame(2, $this->app['session']->driver()->get('user_session_version'));
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $role.'-old@example.test']);
            $replacement = $this->user(['email' => $role.'-old@example.test']);
            $this->assertFalse(Password::broker()->tokenExists($replacement, $oldToken));
            $this->postJson('/api/set-password/inspect', ['email' => $replacement->email, 'token' => $oldToken])
                ->assertUnprocessable()->assertJsonValidationErrors('token');
            $this->getJson('/api/account')->assertOk()->assertJsonPath('user.id', $user->id);
            $audit = AuditLog::where('user_id', $user->id)->where('event', 'account.profile_updated')->firstOrFail();
            $this->assertContains('email', $audit->data['changed_fields']);
            $this->assertNotContains('remember_token', $audit->data['changed_fields']);
            $this->assertNotContains('session_version', $audit->data['changed_fields']);
            $this->assertStringNotContainsString($oldToken, $audit->toJson());
            $this->assertStringNotContainsString($fresh->remember_token, $response->getContent());
        }
    }

    public function test_email_change_removes_other_database_sessions_and_keeps_current_account_access(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions', 'session.lottery' => [0, 100]]);
        $user = $this->user();
        $other = $this->user();
        $this->actingAs($user)->withSession(['user_session_version' => 1]);
        $this->getJson('/api/account')->assertOk();
        DB::table('sessions')->insert([
            ['id' => 'another-browser-session', 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()],
            ['id' => 'other-user-session', 'user_id' => $other->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()],
        ]);
        $this->putJson('/api/account', ['name' => $user->name, 'email' => 'database-new@example.test'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'another-browser-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session', 'user_id' => $other->id]);
        $this->getJson('/api/account')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(2, $this->app['session']->driver()->get('user_session_version'));
    }

    public function test_legacy_combined_email_and_password_change_rotates_once_and_keeps_uploaded_photo(): void
    {
        Storage::fake('local');
        $user = $this->user(['email' => 'combined-old@example.test']);
        $token = Password::broker()->createToken($user);
        $this->actingAs($user)->withSession(['user_session_version' => 1]);
        $response = $this->post('/api/profile', [
            '_method' => 'PUT', 'name' => 'Updated Participant', 'email' => 'combined-new@example.test',
            'current_password' => 'password', 'password' => 'PersonalPassword123!', 'password_confirmation' => 'PersonalPassword123!',
            'photo' => UploadedFile::fake()->image('portrait.png', 250, 250), 'country' => 'Japan',
        ], ['Accept' => 'application/json'])->assertOk();
        $fresh = $user->fresh();
        $this->assertSame(2, $fresh->session_version);
        $this->assertTrue(Hash::check('PersonalPassword123!', $fresh->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'combined-old@example.test']);
        $this->assertFalse(Password::broker()->tokenExists($fresh, $token));
        $this->assertSame('Japan', $fresh->profile->country);
        Storage::disk('local')->assertExists($fresh->avatar_path);
        $this->get($response->json('user.avatar_url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson('/api/account')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(2, $this->app['session']->driver()->get('user_session_version'));
    }

    public function test_unchanged_normalized_email_does_not_revoke_existing_sessions_or_tokens(): void
    {
        $user = $this->user(['email' => 'unchanged@example.test']);
        $token = Password::broker()->createToken($user);
        $this->actingAs($user)->withSession(['user_session_version' => 1]);
        $this->putJson('/api/account', ['name' => 'A New Name', 'email' => ' UNCHANGED@EXAMPLE.TEST '])->assertOk();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertTrue(Password::broker()->tokenExists($user->fresh(), $token));
    }
}
