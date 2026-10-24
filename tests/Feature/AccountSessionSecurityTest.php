<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Persist separate browsers in the isolated SQLite test database.
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->withCredentials();
    }

    private function browser(?string $sessionId = null): static
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->driver()->flush();
        $this->defaultCookies = $sessionId ? [config('session.cookie') => $sessionId] : [];

        return $this;
    }

    private function login(User $user): array
    {
        $response = $this->browser()->postJson('/api/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk();

        return [$response->getCookie(config('session.cookie'))->getValue(), $response->json('csrf_token')];
    }

    public function test_private_pages_and_api_responses_are_not_reusable_from_cache(): void
    {
        foreach (['/dashboard', '/admin', '/admin/profile', '/admin/change-password', '/profile', '/exam/1', '/tests', '/results', '/certificates', '/architecture'] as $path) {
            $this->browser()->get($path)->assertRedirect('/login')
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $this->browser()->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->browser()->getJson('/api/bootstrap')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->browser()->getJson('/api/dashboard')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');

        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$sessionId] = $this->login($user);
        $this->browser($sessionId)->get('/admin')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_logout_destroys_the_database_session_and_replayed_cookie_cannot_authenticate(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$sessionId, $csrfToken] = $this->login($user);
        $this->assertDatabaseHas('sessions', ['id' => $sessionId, 'user_id' => $user->id]);

        $logout = $this->browser($sessionId)->postJson('/api/logout')->assertOk();
        $this->assertNotSame($csrfToken, $logout->json('csrf_token'));
        $this->assertNotSame($sessionId, $logout->getCookie(config('session.cookie'))->getValue());
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

        $this->browser($sessionId)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
        $this->browser($sessionId)->getJson('/api/admin/settings')->assertUnauthorized();
        $this->browser($sessionId)->get('/admin')->assertRedirect('/login');
        $this->assertSame('superadmin', $user->fresh()->role);
        $this->assertTrue($user->fresh()->active);
    }

    public function test_password_hash_change_invalidates_even_an_unused_previous_browser_session(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$firstBrowser] = $this->login($user);
        [$secondBrowser] = $this->login($user);
        $this->assertNotSame($firstBrowser, $secondBrowser);

        foreach ([$firstBrowser, $secondBrowser] as $sessionId) {
            $stored = base64_decode(DB::table('sessions')->where('id', $sessionId)->value('payload'));
            $payload = json_decode(config('session.encrypt') ? app('encrypter')->decrypt($stored) : $stored, true);
            $this->assertNotEmpty($payload['password_hash_web'] ?? null);
        }

        // Models a password changed on another device. The old browser has not
        // made any request since login, so its initial hash must already exist.
        $user->update(['password' => 'ReplacementPassword123!']);
        foreach ([$firstBrowser, $secondBrowser] as $sessionId) {
            $this->browser($sessionId)->getJson('/api/admin/settings')->assertUnauthorized();
            $this->browser($sessionId)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
        }

        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'ReplacementPassword123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_a_disabled_account_cannot_reuse_a_previously_authenticated_session(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$sessionId, $originalCsrf] = $this->login($user);
        $user->update(['active' => false]);

        $invalidated = $this->browser($sessionId)->getJson('/api/admin/settings')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotEmpty($invalidated->json('csrf_token'));
        $this->assertNotSame($originalCsrf, $invalidated->json('csrf_token'));
        $invalidated->assertSessionHas('_token', $invalidated->json('csrf_token'));
        $freshSession = $invalidated->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($sessionId, $freshSession);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->browser($freshSession)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null)
            ->assertJsonPath('csrf_token', $invalidated->json('csrf_token'));
        $this->browser($sessionId)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
    }

    public function test_security_version_revocation_returns_the_fresh_csrf_token_for_the_replacement_guest_session(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$oldSession, $oldCsrf] = $this->login($user);
        // Enable the real CSRF middleware without changing the isolated DB configuration.
        $this->app['env'] = 'local';
        DB::table('users')->where('id', $user->id)->update(['session_version' => 2]);

        $invalidated = $this->browser($oldSession)->getJson('/api/admin/settings')->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_INVALIDATED')->assertHeader('Cache-Control', 'no-store, private');
        $newCsrf = $invalidated->json('csrf_token');
        $this->assertNotEmpty($newCsrf);
        $this->assertNotSame($oldCsrf, $newCsrf);
        $invalidated->assertSessionHas('_token', $newCsrf);
        $freshSession = $invalidated->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($oldSession, $freshSession);
        $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
        $this->browser($freshSession)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null)->assertJsonPath('csrf_token', $newCsrf);
        $this->browser($freshSession)->postJson('/api/login', ['email' => $user->email, 'password' => 'password'], ['X-CSRF-TOKEN' => $oldCsrf])
            ->assertStatus(419);
        $this->browser($freshSession)->postJson('/api/login', ['email' => $user->email, 'password' => 'password'], ['X-CSRF-TOKEN' => $newCsrf])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_password_change_rotates_the_current_session_and_removes_other_database_sessions(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'superadmin']);
        [$currentSession, $csrfToken] = $this->login($user);
        [$otherSession] = $this->login($user);

        $changed = $this->browser($currentSession)->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'ReplacementPassword123!',
            'password_confirmation' => 'ReplacementPassword123!',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'superadmin');
        $newSession = $changed->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($currentSession, $newSession);
        $this->assertNotSame($csrfToken, $changed->json('csrf_token'));
        $this->assertDatabaseMissing('sessions', ['id' => $currentSession]);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSession]);
        $this->assertDatabaseHas('sessions', ['id' => $newSession, 'user_id' => $user->id]);

        $this->browser($newSession)->getJson('/api/admin/settings')->assertOk();
        $this->browser($otherSession)->getJson('/api/admin/settings')->assertUnauthorized();
        $this->browser($currentSession)->getJson('/api/admin/settings')->assertUnauthorized();
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'ReplacementPassword123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_existing_participant_password_form_preserves_its_new_session_and_rejects_other_sessions(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'participant']);
        [$currentSession, $csrfToken] = $this->login($user);
        [$otherSession] = $this->login($user);

        $changed = $this->browser($currentSession)->putJson('/api/profile', [
            'name' => $user->name, 'email' => $user->email,
            'current_password' => 'password',
            'password' => 'UpdatedParticipant123!',
            'password_confirmation' => 'UpdatedParticipant123!',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'participant');
        $newSession = $changed->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($currentSession, $newSession);
        $this->assertNotEmpty($changed->json('csrf_token'));
        $this->assertNotSame($csrfToken, $changed->json('csrf_token'));
        $this->assertDatabaseMissing('sessions', ['id' => $currentSession]);

        $this->browser($newSession)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->browser($newSession)->getJson('/api/dashboard')->assertOk();
        $this->browser($otherSession)->getJson('/api/dashboard')->assertUnauthorized();
        $this->browser($currentSession)->getJson('/api/dashboard')->assertUnauthorized();
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'UpdatedParticipant123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_participant_shared_password_endpoint_keeps_its_rotated_session_and_invalidates_other_browsers_without_leaking_secrets(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'participant', 'text_size' => 'extra_large']);
        $other = User::factory()->create(['active' => true, 'role' => 'participant']);
        [$currentSession, $csrfToken] = $this->login($user);
        [$otherSession] = $this->login($user);
        [$unrelatedSession] = $this->login($other);
        $this->assertDatabaseHas('sessions', ['id' => $currentSession, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherSession, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $unrelatedSession, 'user_id' => $other->id]);

        $changed = $this->browser($currentSession)->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'ParticipantReplacement123!',
            'password_confirmation' => 'ParticipantReplacement123!',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'participant')
            ->assertJsonPath('user.text_size', 'extra_large');
        $newSession = $changed->getCookie(config('session.cookie'))->getValue();
        $wireCookie = $changed->getCookie(config('session.cookie'), false);
        $this->assertNotSame($newSession, $wireCookie->getValue());
        $this->assertTrue($wireCookie->isHttpOnly());
        $this->assertNotSame($currentSession, $newSession);
        $this->assertNotSame($csrfToken, $changed->json('csrf_token'));
        $this->assertDatabaseMissing('sessions', ['id' => $currentSession]);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSession]);
        $this->assertDatabaseHas('sessions', ['id' => $newSession, 'user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $unrelatedSession, 'user_id' => $other->id]);
        $this->assertTrue(Hash::check('ParticipantReplacement123!', $user->fresh()->password));
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
        foreach (['password', 'remember_token', 'avatar_path', 'avatar_version'] as $field) {
            $changed->assertJsonMissingPath('user.'.$field);
        }
        $audit = AuditLog::where('event', 'account.password_changed')->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(['other_sessions_invalidated' => true], $audit->data);
        $this->assertStringNotContainsString('ParticipantReplacement123!', $changed->getContent().$audit->toJson());
        $this->assertStringNotContainsString($user->fresh()->password, $changed->getContent().$audit->toJson());

        $this->browser($newSession)->getJson('/api/account')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->browser($newSession)->getJson('/api/dashboard')->assertOk();
        $this->browser($newSession)->getJson('/api/admin/settings')->assertForbidden();
        foreach ([$currentSession, $otherSession] as $invalidated) {
            $this->browser($invalidated)->getJson('/api/account')->assertUnauthorized();
            $this->browser($invalidated)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
        }
        $this->browser($unrelatedSession)->getJson('/api/dashboard')->assertOk();
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser()->postJson('/api/login', ['email' => $user->email, 'password' => 'ParticipantReplacement123!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }
}
