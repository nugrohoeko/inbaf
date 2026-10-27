<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Password};
use Tests\TestCase;

class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_passwords_that_bcrypt_would_truncate_without_creating_an_account(): void
    {
        foreach ([str_repeat('A', 72).'suffix', str_repeat('界', 25)] as $password) {
            $this->postJson('/api/register', ['name' => 'Registration QA', 'email' => 'registration-qa@example.test', 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('participant_profiles', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_registration_accepts_the_72_byte_boundary_and_always_assigns_a_participant(): void
    {
        $password = str_repeat('界', 24);
        $this->postJson('/api/register', ['name' => 'Registration QA', 'email' => 'registration-qa@example.test', 'password' => $password, 'password_confirmation' => $password, 'role' => 'superadmin', 'active' => false])
            ->assertCreated()->assertJsonPath('user.role', 'participant')->assertJsonPath('user.active', true);
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
    }

    public function test_null_bytes_are_validation_errors_on_every_password_write_path_without_consuming_tokens(): void
    {
        $password = "ValidPassword123!\0suffix";
        $data = ['password' => $password, 'password_confirmation' => $password];
        $this->postJson('/api/register', $data + ['name' => 'Registration QA', 'email' => 'null-password@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
        $participant = User::factory()->create(['role' => 'participant', 'active' => true]);
        $original = $participant->password;
        $token = Password::broker()->createToken($participant);
        $this->postJson('/api/set-password', $data + ['email' => $participant->email, 'token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Password::broker()->tokenExists($participant, $token));
        $this->actingAs($participant)->putJson('/api/account/password', $data + ['current_password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/profile', $data + ['name' => $participant->name, 'email' => $participant->email, 'current_password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($original, $participant->fresh()->password);
        $admin = User::factory()->create(['role' => 'superadmin', 'active' => true]);
        $this->actingAs($admin)->postJson('/api/admin/users', $data + ['name' => 'Admin created QA', 'email' => 'null-password-admin@example.test', 'role' => 'participant', 'status' => 'active', 'password_method' => 'manual'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'null-password-admin@example.test']);
    }

    public function test_an_email_claimed_after_validation_returns_a_form_error_and_rolls_back_the_losing_registration(): void
    {
        $claimed = false;
        $email = 'racing-registration@example.test';
        DB::listen(function ($query) use (&$claimed, $email): void {
            if ($claimed || ! str_contains(strtolower($query->sql), 'count(*)') || ! in_array($email, $query->bindings, true)) return;
            $claimed = true;
            $winner = User::factory()->create(['name' => 'Concurrent winner', 'email' => $email, 'role' => 'participant', 'active' => true]);
            $winner->profile()->create();
            \App\Models\AuditLog::create(['user_id' => $winner->id, 'event' => 'auth.registered']);
        });
        $password = 'RegistrationPassword123!';
        $this->postJson('/api/register', ['name' => 'Losing registration', 'email' => $email, 'password' => $password, 'password_confirmation' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue($claimed);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('participant_profiles', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('users', ['email' => $email, 'name' => 'Concurrent winner']);
    }
}
