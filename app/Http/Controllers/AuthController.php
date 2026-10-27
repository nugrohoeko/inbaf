<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Competency, ExamPackage, ProficiencyLevel, QuestionType, SystemSetting, TestType, User};
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Auth, DB, Hash, RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function bootstrap(Request $request)
    {
        return response()->json([
            'user' => $request->user()?->load('profile'),
            'csrf_token' => csrf_token(),
            'settings' => $this->publicSettings(),
            'competencies' => Competency::where('active', true)->orderBy('sort_order')->get(),
            'levels' => ProficiencyLevel::orderBy('min_score')->get(),
            'question_types' => QuestionType::all(),
            'test_types' => TestType::where('active', true)->orderBy('id')->get(),
            'packages' => ParticipantController::availablePackages()->get()->map(fn ($package) => ParticipantController::packageData($package)),
        ]);
    }

    public function register(Request $request)
    {
        abort_unless(SystemSetting::getValue('registration_enabled', true), 403, 'Pendaftaran mandiri sedang ditutup.');
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => [
                'required', 'string', 'max:72', 'confirmed', Password::min(8),
                new \App\Rules\BcryptPassword,
            ],
            'must_change_password' => ['missing'], 'activation_pending' => ['missing'], 'session_version' => ['missing'], 'deleted_at' => ['missing'],
        ]);
        try {
            $user = DB::transaction(function () use ($data, $request) {
                $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password'], 'role' => 'participant', 'active' => true]);
                $user->profile()->create();
                AuditLog::create(['user_id' => $user->id, 'event' => 'auth.registered', 'ip' => $request->ip()]);
                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A competing registration may claim the email after validation.
            // The unique index remains authoritative and the transaction rolls
            // back every dependent write before returning a normal form error.
            if (! User::withTrashed()->where('email', $data['email'])->exists()) throw $exception;
            throw ValidationException::withMessages(['email' => 'Alamat email sudah digunakan.']);
        }
        Auth::login($user); $request->session()->regenerate();
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
        $request->session()->put('user_session_version', (int) ($user->session_version ?? 1));
        return response()->json(['user' => $user->load('profile'), 'csrf_token' => csrf_token()], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = Str::transliterate(Str::lower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        $user = User::where('email', Str::lower($data['email']))->first();
        if (! $user || ! $user->active || $user->activation_pending || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai, atau akun tidak aktif.']);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user, false); $request->session()->regenerate(); RateLimiter::clear($key);
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
        $request->session()->put('user_session_version', (int) $user->session_version);
        AuditLog::create(['user_id' => $user->id, 'event' => 'auth.login', 'ip' => $request->ip()]);
        return response()->json(['user' => $user->load('profile'), 'csrf_token' => csrf_token()]);
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['message' => 'Anda telah keluar.', 'csrf_token' => csrf_token()]);
    }

    public function preferences(Request $request)
    {
        $participant = $request->user()->role === 'participant';
        $data = $request->validate([
            'text_size' => ['required_without:preferred_language', 'string', 'in:small,normal,large,extra_large'],
            'preferred_language' => $participant ? ['sometimes', 'required_without:text_size', 'string', 'in:id,en'] : ['missing'],
            'must_change_password' => ['missing'], 'activation_pending' => ['missing'], 'session_version' => ['missing'], 'deleted_at' => ['missing'],
        ]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && in_array($user->role, ['superadmin', 'participant'], true), 403);
            abort_if(array_key_exists('preferred_language', $data) && $user->role !== 'participant', 403);
            $user->update($data);
            return $user;
        });
        Auth::guard()->setUser($user);

        return response()->json(['user' => $user->fresh('profile')]);
    }

    private function publicSettings(): array
    {
        $allowed = ['system_name', 'tagline', 'institution_name', 'institution_address', 'contact_email', 'footer', 'primary_color', 'default_locale', 'registration_enabled', 'score_scale', 'certificate_validity_months', 'certificate_template', 'signer_name', 'signer_title', 'certificate_signer_name', 'certificate_signer_title', 'logo_media_id', 'favicon_media_id', 'require_fullscreen', 'tab_switch_limit', 'session_policy', 'max_upload_mb'];
        return SystemSetting::whereIn('key', $allowed)->pluck('value', 'key')->all();
    }
}
