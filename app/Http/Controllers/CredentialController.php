<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class CredentialController extends Controller
{
    public function inspect(Request $request)
    {
        $data = $this->credentials($request);
        $user = User::where('email', $data['email'])->first();
        $this->assertToken($user, $data['token']);

        return response()->json([
            'valid' => true, 'activation' => (bool) $user->activation_pending,
            'expires_in_minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->credentials($request, true);
        $user = DB::transaction(function () use ($data, $request) {
            $user = User::where('email', $data['email'])->lockForUpdate()->first();
            // Lock the token too so concurrent submissions cannot both consume it.
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table', 'password_reset_tokens'))
                ->where('email', $data['email'])->lockForUpdate()->first();
            $this->assertToken($user, $data['token']);
            $activated = (bool) $user->activation_pending;
            $user->forceFill([
                'password' => $data['password'], 'remember_token' => Str::random(60),
                'must_change_password' => false, 'activation_pending' => false,
                'session_version' => $user->session_version + 1,
                // Resetting a deliberately disabled account never enables access.
                'active' => $activated ? true : $user->active,
                'email_verified_at' => $activated ? now() : $user->email_verified_at,
            ])->save();
            Password::broker()->deleteToken($user);
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->delete();
            }
            AuditLog::create([
                'user_id' => $user->id, 'event' => $activated ? 'account.activated' : 'account.password_reset',
                'subject_type' => User::class, 'subject_id' => (string) $user->id, 'ip' => $request->ip(),
                'data' => ['status' => 'success', 'other_sessions_invalidated' => true],
            ]);

            return $user;
        });
        if ($request->user()?->id === $user->id) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Kata sandi berhasil disimpan. Silakan masuk dengan kata sandi baru.', 'csrf_token' => csrf_token()]);
    }

    private function credentials(Request $request, bool $withPassword = false): array
    {
        if (is_string($request->input('email'))) $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        $rules = ['email' => ['required', 'email', 'max:254'], 'token' => ['required', 'string', 'min:32', 'max:255']];
        if ($withPassword) {
            $rules['password'] = [
                'required', 'string', 'max:72', 'confirmed', PasswordRule::min(8)->mixedCase()->letters()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && strlen($value) > 72) $fail('Kata sandi maksimal 72 byte.');
                },
            ];
        }

        return $request->validate($rules);
    }

    private function assertToken(?User $user, string $token): void
    {
        if (! $user || ! Password::broker()->tokenExists($user, $token)) {
            throw ValidationException::withMessages(['token' => 'Tautan tidak valid atau telah kedaluwarsa. Minta tautan baru kepada administrator.']);
        }
    }
}
