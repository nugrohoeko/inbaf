<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, User};
use App\Services\ProfilePhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeAccount($request->user());
        return response()->json(['user' => $request->user()->load('profile'), 'photo_max_kb' => config('profile.photo_max_kb')]);
    }

    public function update(Request $request, ProfilePhotoService $photos)
    {
        return $this->updateProfile($request, $photos);
    }

    public function legacyUpdate(Request $request, ProfilePhotoService $photos)
    {
        abort_unless($request->user()->role === 'participant', 403);
        return $this->updateProfile($request, $photos, true);
    }

    private function updateProfile(Request $request, ProfilePhotoService $photos, bool $legacy = false)
    {
        $this->authorizeAccount($request->user());
        $userId = $request->user()->id;
        $role = $request->user()->role;
        $name = $request->input('name');
        $email = $request->input('email');
        $request->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
        ]);
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($userId)],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('profile.photo_max_kb')],
            'remove_photo' => ['sometimes', 'boolean'],
        ];
        foreach (['id', 'user_id', 'role', 'active', 'avatar_path', 'avatar_version', 'created_at', 'last_login_at', 'text_size', 'email_verified_at', 'remember_token', 'profile', 'must_change_password', 'activation_pending', 'session_version', 'deleted_at'] as $field) {
            $rules[$field] = ['missing'];
        }
        $profileRules = [
            'nationality' => ['sometimes', 'nullable', 'string', 'max:120'],
            'native_language' => ['sometimes', 'nullable', 'string', 'max:120'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:200'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(['female', 'male', 'non_binary', 'prefer_not_to_say'])],
        ];
        if ($role === 'participant') {
            $rules += $profileRules;
            $rules['preferred_language'] = ['sometimes', 'nullable', Rule::in(['id', 'en'])];
        } else {
            foreach ([...array_keys($profileRules), 'preferred_language'] as $field) $rules[$field] = ['missing'];
        }
        $changingPassword = $legacy && $request->filled('password');
        if ($changingPassword) {
            $rules += $this->passwordRules();
        } elseif ($legacy) {
            $rules['password'] = ['nullable'];
            $rules['current_password'] = ['nullable', 'string'];
            $rules['password_confirmation'] = ['nullable', 'string'];
        } else {
            foreach (['password', 'current_password', 'password_confirmation'] as $field) $rules[$field] = ['missing'];
        }
        $data = $request->validate($rules);
        if ($request->hasFile('photo') && $request->boolean('remove_photo')) {
            throw ValidationException::withMessages(['photo' => 'Pilih ganti foto atau hapus foto, bukan keduanya.']);
        }
        $photo = $request->hasFile('photo') ? $photos->store($request->file('photo'), $userId) : null;
        $emailChanged = false;
        try {
            $user = DB::transaction(function () use ($data, $photo, $request, $photos, $userId, $role, $profileRules, $changingPassword, &$emailChanged) {
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $this->authorizeAccount($user);
                abort_unless($user->role === $role, 403);
                $oldPath = $user->avatar_path;
                $user->name = $data['name'];
                $emailChanged = $user->email !== $data['email'];
                if ($emailChanged) {
                    // The broker binds tokens to email addresses. Remove the old
                    // address token before it can be reused by another account.
                    \Illuminate\Support\Facades\Password::broker()->deleteToken($user);
                    $user->email_verified_at = null;
                    if (! $changingPassword) {
                        $user->session_version++;
                        $user->remember_token = Str::random(60);
                    }
                }
                $user->email = $data['email'];
                if ($role === 'participant' && array_key_exists('preferred_language', $data)) $user->preferred_language = $data['preferred_language'];
                $changed = array_values(array_diff(array_keys($user->getDirty()), ['password', 'remember_token', 'session_version']));
                if ($changingPassword) $this->changePassword($user, $data, $request);
                if ($photo || $request->boolean('remove_photo')) {
                    $user->avatar_path = $photo['path'] ?? null;
                    $user->avatar_version = $photo['version'] ?? null;
                    $changed[] = 'photo';
                }
                $user->save();
                if ($emailChanged && ! $changingPassword && config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                        ->where('user_id', $userId)->where('id', '<>', $request->session()->getId())->delete();
                }
                if ($role === 'participant') {
                    $profile = $user->profile()->firstOrNew();
                    $profile->fill(array_intersect_key($data, $profileRules));
                    foreach (array_keys($profile->getDirty()) as $field) {
                        if ($field !== 'user_id') $changed[] = 'profile.'.$field;
                    }
                    $profile->save();
                }
                AuditLog::create([
                    'user_id' => $userId, 'event' => 'account.profile_updated', 'subject_type' => User::class,
                    'subject_id' => (string) $userId, 'ip' => $request->ip(), 'data' => ['changed_fields' => $changed],
                ]);
                if ($oldPath && $oldPath !== $user->avatar_path) {
                    DB::afterCommit(function () use ($photos, $oldPath, $userId) {
                        try {
                            $photos->delete($oldPath, $userId);
                        } catch (Throwable $exception) {
                            // A cleanup failure must not remove the newly committed photo.
                            report($exception);
                        }
                    });
                }
                return $user;
            });
        } catch (Throwable $exception) {
            if ($photo) $photos->delete($photo['path'], $userId);
            throw $exception;
        }
        Auth::guard()->setUser($user);
        if ($changingPassword || $emailChanged) $this->refreshPasswordSession($request, $user);

        return response()->json(['user' => $user->load('profile'), 'csrf_token' => csrf_token(), 'message' => 'Profil berhasil diperbarui.']);
    }

    public function photo(Request $request, ProfilePhotoService $photos)
    {
        $user = $request->user();
        $this->authorizeAccount($user);
        abort_unless($photos->validPath($user->avatar_path, $user->id) && Storage::disk('local')->exists($user->avatar_path), 404);
        return Storage::disk('local')->response($user->avatar_path, 'profile.jpg', [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function password(Request $request)
    {
        $this->authorizeAccount($request->user());
        $data = $request->validate($this->passwordRules());
        $role = $request->user()->role;
        $user = DB::transaction(function () use ($request, $data, $role) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->authorizeAccount($user);
            abort_unless($user->role === $role, 403);
            $this->changePassword($user, $data, $request);
            return $user;
        });
        $this->refreshPasswordSession($request, $user);

        return response()->json(['user' => $user->load('profile'), 'csrf_token' => csrf_token(), 'message' => 'Kata sandi berhasil diubah. Sesi login lain telah diakhiri.']);
    }

    private function authorizeAccount(User $user): void
    {
        abort_unless($user->active && ! $user->activation_pending && ! $user->trashed() && in_array($user->role, ['superadmin', 'participant'], true), 403);
    }

    private function passwordRules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => [
                'required', 'string', 'max:72', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
                new \App\Rules\BcryptPassword,
            ],
        ];
    }

    private function changePassword(User $user, array $data, Request $request): void
    {
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini tidak sesuai.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.']);
        }
        $user->forceFill([
            'password' => $data['password'], 'remember_token' => Str::random(60),
            'must_change_password' => false, 'session_version' => $user->session_version + 1,
        ])->save();
        \Illuminate\Support\Facades\Password::broker()->deleteToken($user);
        Auth::guard()->setUser($user);
        Auth::logoutOtherDevices($data['password']);
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)->where('id', '<>', $request->session()->getId())->delete();
        }
        AuditLog::create([
            'user_id' => $user->id, 'event' => 'account.password_changed', 'subject_type' => User::class,
            'subject_id' => (string) $user->id, 'ip' => $request->ip(), 'data' => ['other_sessions_invalidated' => true],
        ]);
    }

    private function refreshPasswordSession(Request $request, User $user): void
    {
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
        $request->session()->put('user_session_version', (int) $user->session_version);
    }
}
