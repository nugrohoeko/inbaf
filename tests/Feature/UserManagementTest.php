<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Certificate, ExamAttempt, ExamPackage, User};
use App\Notifications\AccountCredentialNotification;
use App\Services\{CertificateService, ProfilePhotoService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Hash, Notification, Password, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'superadmin', 'active' => true]);
    }

    private function participant(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'participant', 'active' => true]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'name' => 'Managed Learner', 'email' => 'managed@example.test', 'role' => 'participant',
            'status' => 'active', 'password_method' => 'manual',
            'password' => 'InitialPassword123!', 'password_confirmation' => 'InitialPassword123!',
        ];
    }

    private function edit(User $user, array $extra = []): array
    {
        return $extra + ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'status' => $user->active || $user->activation_pending ? 'active' : 'inactive'];
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'user_session_version' => (int) $user->session_version,
            'password_hash_web' => Auth::guard()->hashPasswordForCookie($user->password),
        ]);
    }

    private function history(User $user): array
    {
        $package = ExamPackage::create(['code' => 'HISTORY-'.Str::random(8), 'name' => 'Preserved Certification', 'published' => true, 'test_type' => 'certification']);
        $attempt = ExamAttempt::create([
            'user_id' => $user->id, 'exam_package_id' => $package->id, 'status' => 'completed',
            'started_at' => now()->subHour(), 'expires_at' => now(), 'submitted_at' => now(), 'score' => 85, 'level' => 'B2',
            'result' => ['total_score' => 85, 'passed' => true, 'manual_pending' => false],
            'snapshot' => ['test_type' => 'certification', 'participant_name' => $user->name, 'package' => ['name' => $package->name],
                'certificate_policy' => ['enabled' => true, 'pass_score' => 60, 'expiry_months' => 24]],
        ]);
        $certificate = app(CertificateService::class)->issue($attempt);
        $this->assertNotNull($certificate);

        return [$attempt, $certificate];
    }

    public function test_guests_and_participants_cannot_access_user_management_or_private_photos(): void
    {
        $target = $this->participant();
        $url = '/api/admin/users/'.$target->id;
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson('/api/admin/users', $this->payload())->assertUnauthorized();
        $this->get('/admin/users')->assertRedirect('/login');
        $this->asUser($target);
        $this->get('/admin/users')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'/photo')->assertForbidden();
        $this->postJson('/api/admin/users', $this->payload())->assertForbidden();
        $this->putJson($url, $this->edit($target))->assertForbidden();
        $this->postJson($url.'/status', ['status' => 'inactive'])->assertForbidden();
        $this->postJson($url.'/reset-password')->assertForbidden();
        $this->postJson($url.'/invite')->assertForbidden();
        $this->deleteJson($url, ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_can_create_both_roles_with_hashed_manual_passwords_and_appropriate_profiles(): void
    {
        $admin = $this->admin();
        $this->asUser($admin);
        foreach (['participant', 'superadmin'] as $role) {
            $response = $this->postJson('/api/admin/users', $this->payload([
                'email' => strtoupper($role).'@EXAMPLE.TEST', 'role' => $role, 'phone' => '+62 812 333',
                'country' => 'Japan', 'nationality' => 'Japanese',
            ]))->assertCreated()->assertJsonPath('user.role', $role)->assertJsonPath('user.must_change_password', true)
                ->assertJsonPath('user.activation_pending', false)->assertJsonPath('user.account_status', 'active')
                ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.session_version')->assertJsonMissingPath('user.remember_token');
            $user = User::findOrFail($response->json('user.id'));
            $this->assertSame($role.'@example.test', $user->email);
            $this->assertTrue(Hash::check('InitialPassword123!', $user->password));
            $this->assertSame($role === 'participant' ? 1 : 0, $user->profile()->count());
            $this->assertSame('+62 812 333', $user->phone);
            if ($role === 'participant') $this->assertSame('Japan', $user->profile->country);
            $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'users.created', 'subject_id' => (string) $user->id]);
        }
        $this->assertStringNotContainsString('InitialPassword123!', AuditLog::all()->toJson());
    }

    public function test_invited_creation_and_resending_integrate_with_secure_activation_endpoint(): void
    {
        Notification::fake();
        $this->asUser($this->admin());
        $payload = $this->payload(['password_method' => 'activation']);
        unset($payload['password'], $payload['password_confirmation']);
        $created = $this->postJson('/api/admin/users', $payload)->assertCreated()
            ->assertJsonPath('user.account_status', 'pending_activation')->assertJsonPath('user.active', false)
            ->assertJsonPath('delivery_status.sent', true);
        $user = User::findOrFail($created->json('user.id'));
        $token = null;
        Notification::assertSentTo($user, AccountCredentialNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;
            return $notification->purpose === 'invitation';
        });
        $this->postJson('/api/admin/users/'.$user->id.'/status', ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/admin/users/'.$user->id.'/invite')->assertTooManyRequests()->assertJsonPath('delivery_status.status', 'throttled');
        $this->postJson('/api/set-password', ['email' => $user->email, 'token' => $token, 'password' => 'PersonalPassword123!', 'password_confirmation' => 'PersonalPassword123!'])->assertOk();
        $this->assertTrue($user->fresh()->active);
        $this->assertFalse($user->fresh()->activation_pending);
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_email_delivery_failure_is_visible_without_creating_a_live_token_or_rolling_back_account(): void
    {
        config(['mail.default' => 'log']);
        $this->asUser($this->admin());
        $payload = $this->payload(['password_method' => 'activation']);
        unset($payload['password'], $payload['password_confirmation']);
        $response = $this->postJson('/api/admin/users', $payload)->assertCreated()->assertJsonPath('delivery_status.sent', false)->assertJsonPath('delivery_status.status', 'not_configured');
        $id = $response->json('user.id');
        $this->assertDatabaseHas('users', ['id' => $id, 'activation_pending' => true, 'active' => false]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $payload['email']]);
        $this->postJson('/api/admin/users/'.$id.'/invite')->assertStatus(503)->assertJsonPath('delivery_status.status', 'not_configured');
    }

    public function test_invalid_creation_and_case_insensitive_duplicates_never_create_partial_data(): void
    {
        $this->asUser($this->admin());
        $existing = $this->participant(['email' => 'duplicate@example.test']);
        $this->postJson('/api/admin/users', $this->payload(['email' => ' DUPLICATE@EXAMPLE.TEST ']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $invalid = [['role' => 'owner'], ['status' => 'pending_activation'], ['name' => '   '], ['password' => 'weakpass', 'password_confirmation' => 'weakpass'], ['password_confirmation' => 'mismatch'], ['phone' => str_repeat('1', 33)]];
        foreach ($invalid as $extra) $this->postJson('/api/admin/users', $this->payload($extra))->assertUnprocessable();
        foreach (['must_change_password' => false, 'activation_pending' => false, 'session_version' => 20, 'avatar_path' => '../../private', 'active' => true, 'deleted_at' => null] as $field => $value) {
            $this->postJson('/api/admin/users', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $existing->delete();
        $this->postJson('/api/admin/users', $this->payload(['email' => $existing->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('participant_profiles', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'users.created']);
    }

    public function test_failed_photo_storage_rolls_back_new_account_profile_and_audit_atomically(): void
    {
        $this->asUser($this->admin());
        $this->mock(ProfilePhotoService::class, function ($mock) {
            $mock->shouldReceive('store')->once()->andThrow(new \RuntimeException('Photo storage unavailable'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/api/admin/users', $this->payload(['photo' => UploadedFile::fake()->image('portrait.png')]), ['Accept' => 'application/json']);
            $this->fail('Photo storage failure must abort account creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Photo storage unavailable', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'managed@example.test']);
        $this->assertDatabaseCount('participant_profiles', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'users.created']);
    }

    public function test_search_filters_sorting_pagination_and_statistics_use_actual_non_deleted_accounts(): void
    {
        $this->asUser($this->admin(['name' => 'Owner']));
        $active = $this->participant(['name' => 'Alpha 100% Learner', 'email' => 'alpha@example.test']);
        $inactive = $this->participant(['name' => 'Beta Learner', 'active' => false]);
        $pending = $this->participant(['name' => 'Gamma Learner', 'active' => false, 'activation_pending' => true]);
        $deleted = $this->participant(['name' => 'Removed Learner']);$deleted->delete();
        $response = $this->getJson('/api/admin/users?role=participant&sort=name&direction=asc&per_page=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('last_page', 2)->assertJsonPath('data.0.id', $active->id)->assertJsonPath('data.1.id', $inactive->id)
            ->assertJsonPath('stats.total', 4)->assertJsonPath('stats.superadmins', 1)->assertJsonPath('stats.participants', 3)
            ->assertJsonPath('stats.active', 2)->assertJsonPath('stats.inactive', 1)->assertJsonPath('stats.pending_activation', 1)->assertJsonPath('stats.deleted', 1);
        $this->assertCount(2, $response->json('data'));
        $this->getJson('/api/admin/users?status=inactive')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/admin/users?status=pending_activation')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $pending->id);
        $this->getJson('/api/admin/users?status=deleted')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $deleted->id);
        $this->getJson('/api/admin/users?search='.urlencode('100%'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/admin/users?search=ALPHA@EXAMPLE.TEST')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/users?sort=password&per_page=1000')->assertUnprocessable()->assertJsonValidationErrors(['sort', 'per_page']);
    }

    public function test_profile_edit_and_role_changes_are_confirmed_audited_and_preserve_history(): void
    {
        $admin = $this->admin();$target = $this->participant(['name' => 'Historical Learner']);
        $target->profile()->create(['country' => 'Japan', 'phone' => '123']);
        [$attempt, $certificate] = $this->history($target);
        $snapshot = $attempt->getRawOriginal('snapshot');$payload = $certificate->getRawOriginal('payload');$signature = $certificate->signature;
        $this->asUser($admin);
        $url = '/api/admin/users/'.$target->id;
        $this->putJson($url, $this->edit($target, ['name' => 'Updated Learner', 'phone' => '+62 888', 'country' => 'Indonesia']))->assertOk()
            ->assertJsonPath('user.name', 'Updated Learner')->assertJsonPath('user.profile.country', 'Indonesia')->assertJsonPath('user.phone', '+62 888');
        $this->putJson($url, $this->edit($target, ['role' => 'superadmin']))->assertUnprocessable()->assertJsonValidationErrors('confirm_role_change');
        $this->assertSame('participant', $target->fresh()->role);
        $this->putJson($url, $this->edit($target, ['role' => 'superadmin', 'confirm_role_change' => true]))->assertOk()->assertJsonPath('user.role', 'superadmin');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'users.role_changed', 'subject_id' => (string) $target->id]);
        $this->assertSame(1, $target->profile()->count());
        $this->assertSame($snapshot, $attempt->fresh()->getRawOriginal('snapshot'));
        $this->assertSame($payload, $certificate->fresh()->getRawOriginal('payload'));
        $this->assertSame($signature, $certificate->fresh()->signature);
        $this->getJson('/api/verify/'.$certificate->token)->assertOk()->assertJsonPath('status', 'valid')->assertJsonPath('certificate.holder_name', 'Historical Learner');
    }

    public function test_self_and_last_active_admin_cannot_be_removed_demoted_or_disabled(): void
    {
        $admin = $this->admin();$this->asUser($admin);$url = '/api/admin/users/'.$admin->id;
        $this->postJson($url.'/status', ['status' => 'inactive'])->assertUnprocessable();
        $this->deleteJson($url, ['confirmed' => true])->assertUnprocessable();
        $this->putJson($url, $this->edit($admin, ['role' => 'participant', 'confirm_role_change' => true]))->assertUnprocessable();
        $this->putJson($url, $this->edit($admin, ['status' => 'inactive']))->assertUnprocessable();
        $this->postJson($url.'/reset-password')->assertUnprocessable();
        $this->putJson($url, $this->edit($admin, ['password' => 'SelfReplacement123!', 'password_confirmation' => 'SelfReplacement123!']))->assertUnprocessable();
        $this->admin();
        $this->deleteJson($url, ['confirmed' => true])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->active);
        $this->assertSame('superadmin', $admin->fresh()->role);
        $this->assertFalse($admin->fresh()->trashed());
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_deactivation_revokes_tokens_and_prevents_old_sessions_while_reactivation_keeps_history(): void
    {
        $admin = $this->admin();$target = $this->participant();$target->profile()->create();
        [$attempt] = $this->history($target);$token = Password::broker()->createToken($target);
        $this->asUser($admin)->postJson('/api/admin/users/'.$target->id.'/status', ['status' => 'inactive'])->assertOk()->assertJsonPath('user.active', false);
        $this->assertSame(2, $target->fresh()->session_version);
        $this->assertFalse(Password::broker()->tokenExists($target, $token));
        $this->asUser($target)->getJson('/api/dashboard')->assertForbidden();
        $this->asUser($admin)->postJson('/api/admin/users/'.$target->id.'/status', ['status' => 'active'])->assertOk()->assertJsonPath('user.active', true);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'user_id' => $target->id]);
        $this->assertSame(1, $target->profile()->count());
    }

    public function test_deletion_requires_confirmation_and_soft_deletes_without_invalidating_historical_certificates(): void
    {
        $target = $this->participant(['name' => 'Retained Name']);$target->profile()->create(['country' => 'Japan']);
        [$attempt, $certificate] = $this->history($target);$token = Password::broker()->createToken($target);
        $admin = $this->admin();$this->asUser($admin);$url = '/api/admin/users/'.$target->id;
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->deleteJson($url, ['confirmed' => true])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('participant_profiles', ['user_id' => $target->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('certificates', ['id' => $certificate->id]);
        $this->assertSame($target->id, $attempt->fresh()->user->id);
        $this->assertSame($target->id, $certificate->fresh()->user->id);
        $this->assertFalse(Password::broker()->tokenExists($target, $token));
        $this->getJson('/api/verify/'.$certificate->token)->assertOk()->assertJsonPath('status', 'valid')->assertJsonPath('certificate.holder_name', 'Retained Name');
        $this->getJson($url)->assertOk()->assertJsonPath('user.account_status', 'deleted')->assertJsonPath('user.attempts_count', 1)->assertJsonPath('user.certificates_count', 1);
        $this->postJson($url.'/reset-password')->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'users.deleted', 'subject_id' => (string) $target->id]);
    }

    public function test_reset_password_sends_a_link_revokes_existing_sessions_and_does_not_reveal_credentials(): void
    {
        Notification::fake();$admin = $this->admin();$target = $this->participant();$this->asUser($admin);
        $response = $this->postJson('/api/admin/users/'.$target->id.'/reset-password')->assertOk()->assertJsonPath('delivery_status.sent', true);
        $token = null;
        Notification::assertSentTo($target, AccountCredentialNotification::class, function ($notification) use (&$token) { $token = $notification->token;return $notification->purpose === 'reset'; });
        $this->assertSame(2, $target->fresh()->session_version);
        $this->assertTrue(Password::broker()->tokenExists($target, $token));
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString($token, AuditLog::all()->toJson());
        $this->asUser($target)->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_email_change_replaces_identity_and_revokes_the_previous_email_token(): void
    {
        $target = $this->participant(['email' => 'old-address@example.test']);$oldToken = Password::broker()->createToken($target);
        $this->asUser($this->admin())->putJson('/api/admin/users/'.$target->id, $this->edit($target, ['email' => 'NEW-ADDRESS@EXAMPLE.TEST']))
            ->assertOk()->assertJsonPath('user.email', 'new-address@example.test')->assertJsonPath('user.email_verified_at', null);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old-address@example.test']);
        $this->assertSame(2, $target->fresh()->session_version);
        $this->postJson('/api/set-password/inspect', ['email' => 'old-address@example.test', 'token' => $oldToken])->assertUnprocessable();
    }

    public function test_legacy_participant_endpoints_cannot_bypass_role_flags_or_session_revocation(): void
    {
        $admin = $this->admin();$this->asUser($admin);
        $legacy = ['name' => 'Legacy Learner', 'email' => 'LEGACY@EXAMPLE.TEST', 'password' => 'LegacyPassword123!', 'active' => true,
            'role' => 'superadmin', 'profile' => ['country' => 'Japan', 'phone' => '123']];
        $created = $this->postJson('/api/admin/participants', $legacy)->assertCreated()->assertJsonPath('role', 'participant')->assertJsonPath('email', 'legacy@example.test')
            ->assertJsonPath('profile.country', 'Japan')->assertJsonPath('must_change_password', true);
        $target = User::findOrFail($created->json('id'));
        $this->putJson('/api/admin/participants/'.$admin->id, $legacy)->assertNotFound();
        $this->deleteJson('/api/admin/participants/'.$admin->id)->assertNotFound();
        $this->putJson('/api/admin/participants/'.$target->id, $legacy + ['session_version' => 999])->assertUnprocessable()->assertJsonValidationErrors('session_version');
        $this->deleteJson('/api/admin/participants/'.$target->id)->assertOk();
        $this->assertFalse($target->fresh()->active);
        $this->assertFalse($target->fresh()->trashed());
        $this->assertSame(2, $target->fresh()->session_version);
    }

    public function test_profile_photos_are_private_validated_and_can_be_replaced_and_removed(): void
    {
        Storage::fake('local');$admin = $this->admin();$this->asUser($admin);
        $response = $this->post('/api/admin/users', $this->payload(['photo' => UploadedFile::fake()->image('portrait.png', 300, 240)]), ['Accept' => 'application/json'])->assertCreated();
        $target = User::findOrFail($response->json('user.id'));$path = $target->avatar_path;$url = $response->json('user.admin_avatar_url');
        Storage::disk('local')->assertExists($path);
        $this->assertNull($response->json('user.avatar_path'));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->asUser($this->participant())->getJson($url)->assertForbidden();
        $this->asUser($admin);
        $this->post('/api/admin/users/'.$target->id, $this->edit($target, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->image('replacement.jpg')]), ['Accept' => 'application/json'])->assertOk();
        $replacement = $target->fresh()->avatar_path;$this->assertNotSame($path, $replacement);Storage::disk('local')->assertMissing($path);Storage::disk('local')->assertExists($replacement);
        $this->putJson('/api/admin/users/'.$target->id, $this->edit($target, ['remove_photo' => true]))->assertOk()->assertJsonPath('user.admin_avatar_url', null);
        Storage::disk('local')->assertMissing($replacement);
        $this->post('/api/admin/users', $this->payload(['email' => 'spoof@example.test', 'photo' => UploadedFile::fake()->createWithContent('avatar.png', '<?php echo "invalid";')]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertDatabaseMissing('users', ['email' => 'spoof@example.test']);
    }

    public function test_detail_activity_redacts_unexpected_sensitive_audit_data(): void
    {
        $admin = $this->admin();$target = $this->participant();
        AuditLog::create(['user_id' => $admin->id, 'event' => 'users.updated', 'subject_type' => User::class, 'subject_id' => (string) $target->id,
            'data' => ['status' => 'success', 'changed_fields' => ['name'], 'password' => 'SHOULD-NOT-APPEAR', 'token' => 'SECRET-RESET-TOKEN', 'profile' => ['private' => 'PRIVATE-DATA']]]);
        $response = $this->asUser($admin)->getJson('/api/admin/users/'.$target->id)->assertOk()->assertJsonPath('activity.0.actor.id', $admin->id)->assertJsonPath('activity.0.data.status', 'success');
        foreach (['SHOULD-NOT-APPEAR', 'SECRET-RESET-TOKEN', 'PRIVATE-DATA'] as $secret) $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_browsing_users_does_not_consume_the_credential_delivery_rate_limit(): void
    {
        Notification::fake();$admin = $this->admin();$target = $this->participant();$this->asUser($admin);
        for ($i = 0; $i < 12; $i++) $this->getJson('/api/admin/users')->assertOk();
        $this->postJson('/api/admin/users/'.$target->id.'/reset-password')->assertOk()->assertJsonPath('delivery_status.sent', true);
        Notification::assertSentTo($target, AccountCredentialNotification::class);
    }

    public function test_credential_delivery_rate_limit_does_not_block_listing_or_user_creation(): void
    {
        Notification::fake();$this->asUser($this->admin());
        for ($i = 0; $i < 10; $i++) {
            $target = $this->participant();
            $this->postJson('/api/admin/users/'.$target->id.'/reset-password')->assertOk();
        }
        $extra = $this->participant();
        $this->postJson('/api/admin/users/'.$extra->id.'/reset-password')->assertTooManyRequests();
        Notification::assertNotSentTo($extra, AccountCredentialNotification::class);
        $this->getJson('/api/admin/users')->assertOk();
        $this->postJson('/api/admin/users', $this->payload())->assertCreated();
    }
}
