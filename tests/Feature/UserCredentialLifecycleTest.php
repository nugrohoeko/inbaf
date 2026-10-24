<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use App\Notifications\AccountCredentialNotification;
use App\Services\AccountCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, File, Hash, Notification, Password};
use Tests\TestCase;

class UserCredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function passwordData(array $extra = []): array
    {
        return $extra + ['password' => 'PrivatePassword123!', 'password_confirmation' => 'PrivatePassword123!'];
    }

    private function invitation(): User
    {
        return User::factory()->create(['active' => false, 'activation_pending' => true, 'must_change_password' => true]);
    }

    public function test_invitation_is_hashed_email_bound_and_consumed_once_when_user_sets_a_password(): void
    {
        Notification::fake();
        $user = $this->invitation();
        $result = app(AccountCredentialService::class)->send($user, 'invitation');
        $this->assertTrue($result['sent']);
        $token = null;
        Notification::assertSentTo($user, AccountCredentialNotification::class, function ($notification) use (&$token, $user) {
            $token = $notification->token;
            $this->assertSame('invitation', $notification->purpose);
            $this->assertStringStartsWith(config('app.url').'/set-password?', $notification->url($user));
            $mail = $notification->toMail($user);
            $this->assertStringNotContainsString('password', json_encode($mail->introLines));
            return true;
        });
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotSame($token, $stored->token);
        $this->assertTrue(Hash::check($token, $stored->token));
        $this->assertStringNotContainsString($token, json_encode($result));
        $this->assertStringNotContainsString($token, AuditLog::all()->toJson());
        $credentials = ['email' => $user->email, 'token' => $token];
        $this->postJson('/api/set-password/inspect', $credentials)->assertOk()->assertJsonPath('activation', true)
            ->assertJsonMissing(['email' => $user->email]);
        $this->postJson('/api/set-password', $this->passwordData($credentials))->assertOk();
        $this->assertTrue($user->fresh()->active);
        $this->assertFalse($user->fresh()->activation_pending);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('PrivatePassword123!', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/set-password', $this->passwordData($credentials))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PrivatePassword123!'])->assertOk();
    }

    public function test_pending_accounts_cannot_log_in_even_if_marked_active(): void
    {
        $user = User::factory()->create(['active' => true, 'activation_pending' => true]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_reset_does_not_reactivate_an_administratively_disabled_account(): void
    {
        $user = User::factory()->create(['active' => false, 'activation_pending' => false]);
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/set-password', $this->passwordData(['email' => $user->email, 'token' => $token]))->assertOk();
        $this->assertFalse($user->fresh()->active);
        $this->assertFalse($user->fresh()->activation_pending);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'PrivatePassword123!'])->assertUnprocessable();
    }

    public function test_expired_wrong_email_and_deleted_account_tokens_are_rejected(): void
    {
        $user = $this->invitation();
        $other = $this->invitation();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/set-password/inspect', ['email' => $other->email, 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('token');
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/api/set-password', $this->passwordData(['email' => $user->email, 'token' => $token]))->assertUnprocessable()->assertJsonValidationErrors('token');
        $token = Password::broker()->createToken($user);
        $user->delete();
        $this->postJson('/api/set-password/inspect', ['email' => $user->email, 'token' => $token])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/set-password', $this->passwordData(['email' => $user->email, 'token' => $token]))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertFalse(User::withTrashed()->find($user->id)->active);
    }

    public function test_password_policy_and_confirmation_are_enforced_without_consuming_valid_token(): void
    {
        $user = $this->invitation();
        $token = Password::broker()->createToken($user);
        foreach (['weakpass', 'NoNumbers!!', 'NoSymbol123', str_repeat('A', 73).'1!'] as $password) {
            $this->postJson('/api/set-password', ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/set-password', $this->passwordData(['email' => $user->email, 'token' => $token, 'password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->assertFalse($user->fresh()->active);
    }

    public function test_credential_delivery_is_throttled_and_never_claims_success_for_a_logging_mailer(): void
    {
        Notification::fake();
        $user = $this->invitation();
        $service = app(AccountCredentialService::class);
        $this->assertTrue($service->send($user, 'invitation')['sent']);
        $this->assertSame('throttled', $service->send($user, 'invitation')['status']);
        Notification::assertSentToTimes($user, AccountCredentialNotification::class, 1);
        $other = $this->invitation();
        config(['mail.default' => 'log']);
        $result = $service->send($other, 'invitation');
        $this->assertFalse($result['sent']);
        $this->assertSame('not_configured', $result['status']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $other->email]);
        config(['mail.default' => 'failover']);
        $this->assertSame('not_configured', $service->send($other, 'invitation')['status']);
    }

    public function test_transport_failure_is_audited_and_removes_undelivered_token(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('A transport failure containing sensitive message data'));
        $user = $this->invitation();
        $result = app(AccountCredentialService::class)->send($user, 'invitation');
        $this->assertFalse($result['sent']);
        $this->assertSame('failed', $result['status']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $audit = AuditLog::where('event', 'users.credentials.invitation')->firstOrFail();
        $this->assertSame('failed', $audit->data['status']);
        $this->assertStringNotContainsString('sensitive', $audit->toJson());
    }

    public function test_credential_delivery_reloads_the_locked_current_email_instead_of_a_stale_user_instance(): void
    {
        Notification::fake();$stale = $this->invitation();$oldEmail = $stale->email;
        DB::table('users')->where('id', $stale->id)->update(['email' => 'current-address@example.test', 'session_version' => 2]);
        $result = app(AccountCredentialService::class)->send($stale, 'invitation');
        $this->assertTrue($result['sent']);
        Notification::assertSentTo($stale, AccountCredentialNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->email === 'current-address@example.test' && str_contains($notification->url($notifiable), 'email=current-address%40example.test');
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'current-address@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
    }

    public function test_stale_pending_instances_cannot_issue_new_invitation_tokens_after_revocation_or_deletion(): void
    {
        Notification::fake();
        foreach (['activated', 'disabled', 'deleted'] as $state) {
            $stale = $this->invitation();$token = Password::broker()->createToken($stale);
            Password::broker()->deleteToken($stale);
            DB::table('users')->where('id', $stale->id)->update([
                'active' => $state === 'activated', 'activation_pending' => false, 'session_version' => 2,
                'deleted_at' => $state === 'deleted' ? now() : null,
            ]);
            $result = app(AccountCredentialService::class)->send($stale, 'invitation');
            $this->assertFalse($result['sent']);
            $this->assertSame('failed', $result['status']);
            $this->assertFalse(Password::broker()->tokenExists($stale, $token));
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $stale->email]);
            $this->assertDatabaseHas('audit_logs', ['event' => 'users.credentials.invitation', 'subject_id' => (string) $stale->id]);
        }
        Notification::assertNothingSent();
    }

    public function test_stale_active_instance_cannot_send_a_reset_after_the_account_is_disabled(): void
    {
        Notification::fake();$stale = User::factory()->create(['active' => true, 'activation_pending' => false]);
        DB::table('users')->where('id', $stale->id)->update(['active' => false, 'session_version' => 2]);
        $result = app(AccountCredentialService::class)->send($stale, 'reset');
        $this->assertFalse($result['sent']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $stale->email]);
        Notification::assertNothingSent();
    }

    public function test_inspection_requests_do_not_consume_the_password_submission_limit(): void
    {
        $user = $this->invitation();$token = Password::broker()->createToken($user);
        $credentials = ['email' => $user->email, 'token' => $token];
        for ($i = 0; $i < 12; $i++) $this->postJson('/api/set-password/inspect', $credentials)->assertOk();
        $this->postJson('/api/set-password', $this->passwordData($credentials))->assertOk();
        $this->assertTrue($user->fresh()->active);
    }

    public function test_manual_password_is_gated_for_both_roles_until_own_password_changes(): void
    {
        foreach (['participant', 'superadmin'] as $role) {
            Auth::forgetGuards();
            $this->flushSession();
            $user = User::factory()->create(['role' => $role, 'active' => true, 'must_change_password' => true]);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.must_change_password', true);
            $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id);
            $this->getJson('/api/account')->assertOk();
            $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
            $target = '/profile?tab=password&required=1';
            $this->get($role === 'superadmin' ? '/admin/users' : '/tests')->assertRedirect($target);
            $this->putJson('/api/account/password', $this->passwordData(['current_password' => 'wrong']))->assertUnprocessable();
            $this->assertTrue($user->fresh()->must_change_password);
            $this->putJson('/api/account/password', $this->passwordData(['current_password' => 'password']))->assertOk()->assertJsonPath('user.must_change_password', false);
            $this->getJson('/api/dashboard')->assertOk();
            $this->assertSame(2, $user->fresh()->session_version);
            $this->postJson('/api/logout')->assertOk();
        }
    }

    public function test_version_change_invalidates_sessions_even_without_password_hash_change(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $this->actingAs($user)->withSession(['user_session_version' => 1]);
        DB::table('users')->where('id', $user->id)->update(['session_version' => 2, 'role' => 'superadmin']);
        $this->getJson('/api/dashboard')->assertUnauthorized()->assertJsonPath('code', 'SESSION_INVALIDATED');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
    }

    public function test_file_sessions_revoke_other_devices_and_keep_the_password_change_session(): void
    {
        $directory = storage_path('framework/testing/credential-sessions-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        config(['session.driver' => 'file', 'session.files' => $directory, 'session.lottery' => [0, 100]]);
        $this->withCredentials();
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        $browser = function (?string $session = null) {
            Auth::forgetGuards();
            $this->app->forgetInstance('auth.driver');
            $this->app['session']->driver()->flush();
            $this->defaultCookies = $session ? [config('session.cookie') => $session] : [];
            return $this;
        };
        try {
            $first = $browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->getCookie(config('session.cookie'))->getValue();
            $other = $browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->getCookie(config('session.cookie'))->getValue();
            $changed = $browser($first)->putJson('/api/account/password', $this->passwordData(['current_password' => 'password']))->assertOk();
            $current = $changed->getCookie(config('session.cookie'))->getValue();
            $browser($current)->getJson('/api/dashboard')->assertOk();
            $browser($other)->getJson('/api/dashboard')->assertUnauthorized();
            $browser($first)->getJson('/api/dashboard')->assertUnauthorized();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_legacy_session_cannot_adopt_version_after_administrative_security_changes(): void
    {
        $user = User::factory()->create(['active' => true, 'session_version' => 2]);
        $this->actingAs($user)->getJson('/api/account')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_new_security_flags_cannot_be_injected_into_self_service_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'participant', 'active' => true]);
        foreach (['must_change_password' => false, 'activation_pending' => false, 'session_version' => 999, 'deleted_at' => null] as $field => $value) {
            $this->actingAs($user)->putJson('/api/account', ['name' => $user->name, 'email' => $user->email, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->putJson('/api/profile', ['name' => $user->name, 'email' => $user->email, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->putJson('/api/preferences', ['text_size' => 'large', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->postJson('/api/logout')->assertOk();
            $this->postJson('/api/register', $this->passwordData(['name' => 'Injected', 'email' => 'flags@example.test', $field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertFalse($user->fresh()->activation_pending);
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
