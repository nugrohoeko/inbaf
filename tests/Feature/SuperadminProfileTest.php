<?php

namespace Tests\Feature;

use App\Models\{AuditLog, User};
use App\Services\ProfilePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Hash, Storage};
use Tests\TestCase;

class SuperadminProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'superadmin', 'active' => true, 'password' => 'OldPassword123!'], $attributes));
    }

    private function passwordData(array $overrides = []): array
    {
        return array_merge(['current_password' => 'OldPassword123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'], $overrides);
    }

    public function test_account_endpoints_require_an_active_supported_account(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();
        $this->putJson('/api/account', [])->assertUnauthorized();
        $this->getJson('/api/account/photo')->assertUnauthorized();
        $this->putJson('/api/account/password', [])->assertUnauthorized();
        $participant = User::factory()->create(['role' => 'participant', 'active' => true]);
        $this->actingAs($participant)->getJson('/api/account')->assertOk()->assertJsonPath('user.id', $participant->id);
        $this->putJson('/api/account', [])->assertUnprocessable();
        $this->getJson('/api/account/photo')->assertNotFound();
        $this->putJson('/api/account/password', [])->assertUnprocessable();
        $this->actingAs($this->admin(['active' => false]))->getJson('/api/account')->assertForbidden();
    }

    public function test_profile_returns_read_only_account_information_without_private_storage_or_passwords(): void
    {
        $admin = $this->admin(['last_login_at' => now()->subHour()]);
        $response = $this->actingAs($admin)->getJson('/api/account')->assertOk()
            ->assertJsonPath('user.id', $admin->id)->assertJsonPath('user.role', 'superadmin')
            ->assertJsonPath('user.avatar_url', null)->assertJsonPath('photo_max_kb', 2048);
        $this->assertNotNull($response->json('user.created_at'));
        $this->assertNotNull($response->json('user.last_login_at'));
        foreach (['password', 'remember_token', 'avatar_path', 'avatar_version'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('user'));
        }
    }

    public function test_legacy_participant_profile_cannot_bypass_superadmin_password_rules(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/profile', [
            'name' => 'Unexpected edit', 'email' => 'unexpected@example.test',
            'current_password' => 'OldPassword123!', 'password' => 'weakpass', 'password_confirmation' => 'weakpass',
        ])->assertForbidden();
        $this->assertSame($admin->name, $admin->fresh()->name);
        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertTrue(Hash::check('OldPassword123!', $admin->fresh()->password));
    }

    public function test_profile_update_is_limited_to_own_name_email_and_photo(): void
    {
        $admin = $this->admin(['text_size' => 'extra_large']);
        $other = $this->admin();
        $this->actingAs($admin)->putJson('/api/account', ['name' => '  Updated Admin  ', 'email' => 'UPDATED@EXAMPLE.TEST'])
            ->assertOk()->assertJsonPath('user.name', 'Updated Admin')->assertJsonPath('user.email', 'updated@example.test')
            ->assertJsonPath('user.role', 'superadmin')->assertJsonPath('user.text_size', 'extra_large')->assertJsonStructure(['csrf_token', 'message']);
        $this->assertNull($admin->fresh()->email_verified_at);
        $this->assertSame($other->email, $other->fresh()->email);
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.profile_updated', 'user_id' => $admin->id]);

        foreach (['role' => 'participant', 'id' => $other->id, 'user_id' => $other->id, 'last_login_at' => now()->toIso8601String(), 'avatar_path' => '../private.txt'] as $field => $value) {
            $this->putJson('/api/account', ['name' => 'Changed', 'email' => 'new@example.test', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('Updated Admin', $admin->fresh()->name);
        $this->assertSame('superadmin', $admin->fresh()->role);
    }

    public function test_profile_validation_preserves_data_on_duplicate_email_or_invalid_names(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->actingAs($admin)->putJson('/api/account', ['name' => 'Updated', 'email' => strtoupper($other->email)])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/account', ['name' => '   ', 'email' => 'valid@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/api/account', ['name' => ['invalid'], 'email' => 'valid@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertSame($admin->name, $admin->fresh()->name);
    }

    public function test_upload_reencodes_pixels_to_a_private_unique_avatar_and_owner_only_endpoint(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $image = UploadedFile::fake()->image('original.jpg', 1000, 800);
        $photo = UploadedFile::fake()->createWithContent('avatar.jpg', file_get_contents($image->getRealPath()).'<?php echo "malicious-payload"; ?>');
        $response = $this->actingAs($admin)->post('/api/account', ['_method' => 'PUT', 'name' => $admin->name, 'email' => $admin->email, 'photo' => $photo], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['user' => ['avatar_url'], 'csrf_token']);
        $path = $admin->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('~^profile-photos/'.$admin->id.'/[a-f0-9-]{36}\.jpg$~', $path);
        Storage::disk('local')->assertExists($path);
        $encoded = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('malicious-payload', $encoded);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($encoded)[2]);
        $this->assertSame([768, 768], array_slice(getimagesizefromstring($encoded), 0, 2));
        $this->get($response->json('user.avatar_url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $other = $this->admin();
        $this->actingAs($other)->withSession(['password_hash_web' => Auth::guard()->hashPasswordForCookie($other->password)])
            ->getJson($response->json('user.avatar_url').'&user_id='.$admin->id)->assertNotFound();
        $this->assertArrayNotHasKey('avatar_path', $response->json('user'));
    }

    public function test_replacement_and_removal_delete_only_the_owners_previous_photo_after_save(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $other = $this->admin();
        $photos = app(ProfilePhotoService::class);
        $first = $photos->store(UploadedFile::fake()->image('first.png', 100, 100), $admin->id);
        $untouched = $photos->store(UploadedFile::fake()->image('other.png', 100, 100), $other->id);
        $admin->forceFill(['avatar_path' => $first['path'], 'avatar_version' => $first['version']])->save();
        $response = $this->actingAs($admin)->post('/api/account', ['_method' => 'PUT', 'name' => $admin->name, 'email' => $admin->email, 'photo' => UploadedFile::fake()->image('second.png', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        $second = $admin->fresh()->avatar_path;
        $this->assertNotSame($first['path'], $second);
        $this->assertStringNotContainsString($first['version'], $response->json('user.avatar_url'));
        Storage::disk('local')->assertMissing($first['path']);
        Storage::disk('local')->assertExists($untouched['path']);
        $this->putJson('/api/account', ['name' => $admin->name, 'email' => $admin->email, 'remove_photo' => true])
            ->assertOk()->assertJsonPath('user.avatar_url', null);
        Storage::disk('local')->assertMissing($second);
        Storage::disk('local')->assertExists($untouched['path']);
        $this->getJson('/api/account/photo')->assertNotFound();
        $photos->delete($untouched['path'], $admin->id);
        Storage::disk('local')->assertExists($untouched['path']);
    }

    public function test_photo_validation_rejects_unsafe_oversized_or_invalid_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->actingAs($admin);
        $files = [
            UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('avatar.php', '<?php echo "bad";'),
            UploadedFile::fake()->create('large.jpg', 2049, 'image/jpeg'),
            UploadedFile::fake()->image('wide.png', 4097, 1),
            UploadedFile::fake()->createWithContent('invalid.png', 'not an image'),
        ];
        foreach ($files as $file) {
            $this->post('/api/account', ['_method' => 'PUT', 'name' => $admin->name, 'email' => $admin->email, 'photo' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertNull($admin->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_password_validation_requires_current_complex_confirmed_and_different_password(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $cases = [
            [['current_password' => 'incorrect'], 'current_password'],
            [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
            [['password_confirmation' => 'WrongConfirmation123!'], 'password'],
            [['password' => 'OldPassword123!', 'password_confirmation' => 'OldPassword123!'], 'password'],
            [['password' => 'Ab1!'.str_repeat('é', 35), 'password_confirmation' => 'Ab1!'.str_repeat('é', 35)], 'password'],
        ];
        foreach ($cases as [$changes, $field]) {
            $this->putJson('/api/account/password', $this->passwordData($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertTrue(Hash::check('OldPassword123!', $admin->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'account.password_changed']);
    }

    public function test_password_change_hashes_password_rotates_session_and_csrf_and_logs_no_secrets(): void
    {
        $admin = $this->admin(['text_size' => 'large']);
        $this->actingAs($admin)->getJson('/api/account')->assertOk();
        $sessionId = session()->getId();
        $token = csrf_token();
        $this->putJson('/api/account/password', $this->passwordData())->assertOk()
            ->assertJsonPath('user.id', $admin->id)->assertJsonPath('user.role', 'superadmin')->assertJsonPath('user.text_size', 'large')
            ->assertJsonStructure(['csrf_token', 'message']);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, csrf_token());
        $this->assertTrue(Hash::check('NewPassword123!', $admin->fresh()->password));
        $this->assertFalse(Hash::check('OldPassword123!', $admin->fresh()->password));
        $this->assertSame(Auth::guard()->hashPasswordForCookie($admin->fresh()->password), session('password_hash_web'));
        $audit = AuditLog::where('event', 'account.password_changed')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['other_sessions_invalidated' => true], $audit->data);
        $this->assertStringNotContainsString('Password123', $audit->toJson());
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'OldPassword123!'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'NewPassword123!'])->assertOk();
        $this->getJson('/api/account')->assertOk();
    }

    public function test_password_change_deletes_other_database_sessions_but_keeps_other_users_sessions(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $admin = $this->admin();
        $other = $this->admin();
        DB::table('sessions')->insert([
            ['id' => 'other-admin-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'unrelated-user-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $this->actingAs($admin)->putJson('/api/account/password', $this->passwordData())->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-admin-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-user-session', 'user_id' => $other->id]);
        $this->getJson('/api/account')->assertOk();
    }

    public function test_password_change_is_rate_limited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/account/password', $this->passwordData(['current_password' => 'wrong']))->assertUnprocessable();
        }
        $this->putJson('/api/account/password', $this->passwordData())->assertTooManyRequests();
        $this->assertTrue(Hash::check('OldPassword123!', $admin->fresh()->password));
    }

    public function test_only_a_successful_login_sets_last_login_and_initializes_session_password_hash(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertNull($admin->fresh()->last_login_at);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'OldPassword123!'])->assertOk();
        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertSame(Auth::guard()->hashPasswordForCookie($admin->fresh()->password), session('password_hash_web'));
        $this->getJson('/api/bootstrap')->assertJsonPath('user.last_login_at', $admin->fresh()->last_login_at->toJSON());
    }
}
