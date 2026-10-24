<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Hash, Storage};
use Tests\TestCase;

class ParticipantAccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private function participant(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'participant', 'active' => true, 'password' => 'OldParticipant123!'], $attributes));
    }

    private function identity(User $user, array $attributes = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email], $attributes);
    }

    private function passwordData(array $attributes = []): array
    {
        return array_merge(['current_password' => 'OldParticipant123!', 'password' => 'NewParticipant123!', 'password_confirmation' => 'NewParticipant123!'], $attributes);
    }

    public function test_participant_account_returns_existing_and_new_fields_without_private_storage_or_secrets(): void
    {
        $user = $this->participant(['preferred_language' => 'en', 'last_login_at' => now()->subHour()]);
        $user->profile()->create(['country' => 'Indonesia', 'nationality' => 'Indonesian', 'native_language' => 'Indonesian', 'institution' => 'INBAF', 'phone' => '+62 812 3456', 'date_of_birth' => '1990-03-21', 'gender' => 'prefer_not_to_say']);
        $response = $this->actingAs($user)->getJson('/api/account')->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'participant')
            ->assertJsonPath('user.preferred_language', 'en')->assertJsonPath('user.profile.date_of_birth', '1990-03-21')
            ->assertJsonPath('user.profile.phone', '+62 812 3456')->assertJsonPath('user.avatar_url', null)
            ->assertJsonStructure(['user' => ['created_at', 'last_login_at', 'active'], 'photo_max_kb']);
        foreach (['password', 'remember_token', 'avatar_path', 'avatar_version'] as $field) $this->assertArrayNotHasKey($field, $response->json('user'));
    }

    public function test_profile_edit_saves_participant_fields_and_refreshes_bootstrap_without_changing_other_accounts_or_font(): void
    {
        $user = $this->participant(['text_size' => 'extra_large']);
        $other = $this->participant();
        $other->profile()->create(['phone' => 'unchanged']);
        $this->actingAs($user)->putJson('/api/account', [
            'name' => '  Renamed Learner  ', 'email' => 'LEARNER@EXAMPLE.TEST', 'phone' => '+62 812 999',
            'country' => 'Japan', 'nationality' => 'Japanese', 'native_language' => 'Japanese', 'institution' => 'Language Center',
            'date_of_birth' => '1985-12-06', 'gender' => 'female', 'preferred_language' => 'en',
        ])->assertOk()->assertJsonPath('user.name', 'Renamed Learner')->assertJsonPath('user.email', 'learner@example.test')
            ->assertJsonPath('user.profile.country', 'Japan')->assertJsonPath('user.profile.nationality', 'Japanese')
            ->assertJsonPath('user.profile.date_of_birth', '1985-12-06')->assertJsonPath('user.profile.gender', 'female')
            ->assertJsonPath('user.preferred_language', 'en')->assertJsonPath('user.text_size', 'extra_large');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.name', 'Renamed Learner')->assertJsonPath('user.profile.institution', 'Language Center');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame('unchanged', $other->profile->fresh()->phone);
        $this->assertSame(2, DB::table('participant_profiles')->count());
        $audit = AuditLog::where('user_id', $user->id)->where('event', 'account.profile_updated')->firstOrFail();
        $this->assertContains('profile.phone', $audit->data['changed_fields']);
        $this->assertContains('preferred_language', $audit->data['changed_fields']);
        $this->assertStringNotContainsString('+62 812 999', $audit->toJson());
    }

    public function test_absent_optional_fields_are_preserved_and_explicit_null_clears_them_without_duplicate_profiles(): void
    {
        $user = $this->participant(['preferred_language' => 'en']);
        $user->profile()->create(['phone' => '123', 'country' => 'Japan', 'date_of_birth' => '1975-01-02', 'gender' => 'male']);
        $this->actingAs($user)->putJson('/api/account', $this->identity($user, ['name' => 'Renamed']))
            ->assertOk()->assertJsonPath('user.profile.phone', '123')->assertJsonPath('user.profile.date_of_birth', '1975-01-02')
            ->assertJsonPath('user.preferred_language', 'en');
        $this->putJson('/api/account', $this->identity($user, ['phone' => null, 'date_of_birth' => null, 'gender' => null, 'preferred_language' => null]))
            ->assertOk()->assertJsonPath('user.profile.phone', null)->assertJsonPath('user.profile.date_of_birth', null)
            ->assertJsonPath('user.profile.gender', null)->assertJsonPath('user.preferred_language', null)->assertJsonPath('user.profile.country', 'Japan');
        $this->assertSame(1, DB::table('participant_profiles')->where('user_id', $user->id)->count());
    }

    public function test_invalid_profile_input_does_not_partially_change_the_account(): void
    {
        $user = $this->participant();
        $other = $this->participant();
        $this->actingAs($user);
        $invalid = [
            ['name', '   '], ['email', strtoupper($other->email)], ['phone', str_repeat('1', 33)],
            ['country', str_repeat('x', 121)], ['nationality', str_repeat('x', 121)], ['native_language', str_repeat('x', 121)],
            ['institution', str_repeat('x', 201)], ['date_of_birth', now()->addDay()->toDateString()], ['date_of_birth', '2020-02-31'],
            ['date_of_birth', '03/21/1990'], ['gender', 'invalid'], ['gender', ['male']], ['preferred_language', 'fr'],
        ];
        foreach ($invalid as [$field, $value]) {
            $this->putJson('/api/account', $this->identity($user, ['name' => 'Uncommitted', $field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertDatabaseCount('participant_profiles', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'account.profile_updated']);
    }

    public function test_account_rejects_readonly_fields_and_passwords_in_the_profile_endpoint(): void
    {
        $user = $this->participant();
        $this->actingAs($user);
        foreach (['role' => 'superadmin', 'active' => false, 'id' => 999, 'user_id' => 999, 'avatar_path' => '../private.jpg', 'profile' => ['user_id' => 999], 'created_at' => '2000-01-01', 'password' => 'NewParticipant123!', 'text_size' => 'normal'] as $field => $value) {
            $this->putJson('/api/account', $this->identity($user, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('participant', $user->fresh()->role);
        $this->assertTrue(Hash::check('OldParticipant123!', $user->fresh()->password));
    }

    public function test_participant_photo_uses_private_owner_storage_and_can_be_replaced_and_removed(): void
    {
        Storage::fake('local');
        $user = $this->participant();
        $this->actingAs($user)->post('/api/account', $this->identity($user, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->image('participant.png', 300, 240)]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['user' => ['avatar_url']]);
        $originalPath = $user->fresh()->avatar_path;
        $url = $user->fresh()->avatar_url;
        Storage::disk('local')->assertExists($originalPath);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $other = $this->participant();
        $this->actingAs($other)->withSession(['password_hash_web' => Auth::guard()->hashPasswordForCookie($other->password)])->get($url.'&user_id='.$user->id)->assertNotFound();
        $this->actingAs($user)->withSession(['password_hash_web' => Auth::guard()->hashPasswordForCookie($user->password)])->post('/api/account', $this->identity($user, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->image('replacement.jpg', 400, 400)]), ['Accept' => 'application/json'])->assertOk();
        $replacementPath = $user->fresh()->avatar_path;
        $this->assertNotSame($originalPath, $replacementPath);
        Storage::disk('local')->assertMissing($originalPath);
        $this->putJson('/api/account', $this->identity($user, ['remove_photo' => true]))->assertOk()->assertJsonPath('user.avatar_url', null);
        Storage::disk('local')->assertMissing($replacementPath);
        $this->get('/api/account/photo')->assertNotFound();
    }

    public function test_shared_photo_validation_rejects_spoofed_content_and_conflicting_actions_for_participants(): void
    {
        Storage::fake('local');
        $user = $this->participant();
        $this->actingAs($user)->post('/api/account', $this->identity($user, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->createWithContent('picture.png', '<?php echo "invalid";')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->post('/api/account', $this->identity($user, ['_method' => 'PUT', 'photo' => UploadedFile::fake()->image('picture.png'), 'remove_photo' => true]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_legacy_profile_password_uses_the_same_strict_rules_and_failed_changes_are_atomic(): void
    {
        $user = $this->participant();
        $this->actingAs($user)->putJson('/api/profile', $this->identity($user, ['name' => 'Uncommitted'] + $this->passwordData(['password' => 'weakpass', 'password_confirmation' => 'weakpass'])))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/profile', $this->identity($user, ['name' => 'Uncommitted'] + $this->passwordData(['current_password' => 'wrong'])))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertTrue(Hash::check('OldParticipant123!', $user->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'account.password_changed']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'account.profile_updated']);
    }

    public function test_current_and_legacy_password_endpoints_share_one_rate_limit(): void
    {
        $user = $this->participant();
        $this->actingAs($user);
        for ($i = 0; $i < 3; $i++) $this->putJson('/api/account/password', $this->passwordData(['current_password' => 'wrong']))->assertUnprocessable();
        for ($i = 0; $i < 2; $i++) $this->putJson('/api/profile', $this->identity($user, $this->passwordData(['current_password' => 'wrong'])))->assertUnprocessable();
        $this->putJson('/api/account/password', $this->passwordData())->assertTooManyRequests();
        $this->putJson('/api/profile', $this->identity($user, $this->passwordData()))->assertTooManyRequests();
        $this->assertTrue(Hash::check('OldParticipant123!', $user->fresh()->password));
    }

    public function test_interface_language_preference_is_saved_restored_and_does_not_reset_text_size(): void
    {
        $user = $this->participant(['text_size' => 'extra_large']);
        $this->actingAs($user)->putJson('/api/preferences', ['preferred_language' => 'en'])->assertOk()
            ->assertJsonPath('user.preferred_language', 'en')->assertJsonPath('user.text_size', 'extra_large');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.preferred_language', 'en');
        $this->putJson('/api/preferences', ['preferred_language' => 'id', 'text_size' => 'large'])->assertOk()
            ->assertJsonPath('user.preferred_language', 'id')->assertJsonPath('user.text_size', 'large');
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldParticipant123!'])->assertOk()
            ->assertJsonPath('user.preferred_language', 'id')->assertJsonPath('user.text_size', 'large');
    }

    public function test_invalid_or_admin_language_preferences_do_not_change_account_data(): void
    {
        $user = $this->participant(['preferred_language' => 'en', 'text_size' => 'large']);
        $this->actingAs($user);
        foreach ([['preferred_language' => 'fr'], ['preferred_language' => null], ['preferred_language' => ['en']]] as $data) {
            $this->putJson('/api/preferences', $data)->assertUnprocessable()->assertJsonValidationErrors('preferred_language');
        }
        $this->assertSame('en', $user->fresh()->preferred_language);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->withSession(['password_hash_web' => Auth::guard()->hashPasswordForCookie($admin->password)])->putJson('/api/preferences', ['preferred_language' => 'en'])->assertUnprocessable()->assertJsonValidationErrors('preferred_language');
        $this->putJson('/api/account', $this->identity($admin, ['preferred_language' => 'en']))->assertUnprocessable()->assertJsonValidationErrors('preferred_language');
        $this->assertNull($admin->fresh()->preferred_language);
    }

    public function test_locked_account_rechecks_active_status_and_role_before_updating(): void
    {
        $user = $this->participant();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['role' => 'superadmin']);
        $this->putJson('/api/account', $this->identity($user, ['name' => 'Uncommitted', 'phone' => '999']))->assertForbidden();
        $this->putJson('/api/account/password', $this->passwordData())->assertForbidden();
        DB::table('users')->where('id', $user->id)->update(['role' => 'participant', 'active' => false]);
        $this->putJson('/api/account', $this->identity($user, ['name' => 'Uncommitted']))->assertForbidden();
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertTrue(Hash::check('OldParticipant123!', $user->fresh()->password));
    }
}
