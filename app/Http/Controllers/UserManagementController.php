<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, User};
use App\Services\{AccountCredentialService, ProfilePhotoService};
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Password, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:254', 'role' => ['nullable', Rule::in(['superadmin', 'participant'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending_activation', 'deleted'])],
            'sort' => ['nullable', Rule::in(['name', 'email', 'role', 'created_at', 'last_login_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])], 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = ($data['status'] ?? '') === 'deleted' ? User::onlyTrashed() : User::query();
        if (! empty($data['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['search']))).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (! empty($data['role'])) $query->where('role', $data['role']);
        match ($data['status'] ?? '') {
            'active' => $query->where('active', true)->where('activation_pending', false),
            'inactive' => $query->where('active', false)->where('activation_pending', false),
            'pending_activation' => $query->where('activation_pending', true), default => null,
        };
        $rows = $query->with('profile')->withCount(['attempts', 'certificates'])
            ->orderBy($data['sort'] ?? 'created_at', $data['direction'] ?? 'desc')->orderBy('id')
            ->paginate($data['per_page'] ?? 20)->through(fn (User $user) => $this->present($user));

        return response()->json(array_merge($rows->toArray(), ['stats' => $this->stats(), 'mail_delivery' => config('mail.default')]));
    }

    public function show(Request $request, string $id)
    {
        $user = User::withTrashed()->with('profile')->withCount(['attempts', 'certificates'])->findOrFail($id);
        $activity = AuditLog::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->whereIn('subject_type', [User::class, 'User'])->where('subject_id', (string) $user->id));
        })->with('user:id,name')->latest('id')->paginate(50, ['*'], 'activity_page');
        $safeKeys = ['status', 'changed_fields', 'fields', 'previous_role', 'new_role', 'from_status', 'to_status', 'delivery_status', 'purpose', 'other_sessions_invalidated', 'retained_attempts', 'retained_certificates'];
        return response()->json([
            'user' => $this->present($user),
            'activity' => $activity->getCollection()->map(fn ($entry) => [
                'id' => $entry->id, 'event' => $entry->event, 'created_at' => $entry->created_at,
                'actor' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
                'data' => array_intersect_key($entry->data ?? [], array_flip($safeKeys)),
            ]),
            'activity_current_page' => $activity->currentPage(), 'activity_last_page' => $activity->lastPage(),
        ]);
    }

    public function store(Request $request, ProfilePhotoService $photos, AccountCredentialService $credentials)
    {
        return $this->write($request, $photos, $credentials);
    }

    public function update(Request $request, string $id, ProfilePhotoService $photos, AccountCredentialService $credentials)
    {
        return $this->write($request, $photos, $credentials, $id);
    }

    private function write(Request $request, ProfilePhotoService $photos, AccountCredentialService $credentials, ?string $id = null)
    {
        $request->merge(['email' => is_string($request->email) ? Str::lower(trim($request->email)) : $request->email]);
        $rules = [
            'name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($id)],
            'role' => ['required', Rule::in(['superadmin', 'participant'])], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'phone' => 'nullable|string|max:32', 'country' => 'nullable|string|max:120', 'nationality' => 'nullable|string|max:120',
            'native_language' => 'nullable|string|max:120', 'institution' => 'nullable|string|max:200',
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('profile.photo_max_kb')],
            'remove_photo' => 'sometimes|boolean', 'confirm_role_change' => 'sometimes|boolean',
            'password_method' => [$id ? 'sometimes' : 'required', Rule::in(['manual', 'activation'])],
        ];
        $manual = (! $id && $request->input('password_method') === 'manual') || ($id && $request->filled('password'));
        $rules['password'] = $manual ? ['required', 'string', 'max:72', 'confirmed', PasswordRule::min(8)->mixedCase()->letters()->numbers()->symbols(), new \App\Rules\BcryptPassword] : ['prohibited'];
        $rules['password_confirmation'] = $manual ? ['required', 'string'] : ['prohibited'];
        foreach (['id', 'user_id', 'activation_pending', 'must_change_password', 'session_version', 'avatar_path', 'avatar_version', 'deleted_at', 'email_verified_at', 'remember_token', 'active'] as $field) $rules[$field] = ['missing'];
        $data = $request->validate($rules);
        if ($request->hasFile('photo') && $request->boolean('remove_photo')) throw ValidationException::withMessages(['photo' => 'Pilih unggah atau hapus foto, bukan keduanya.']);
        $photo = null;
        try {
            $user = DB::transaction(function () use ($request, $data, $photos, $manual, $id, &$photo) {
                // A retried transaction must discard the file staged by its rolled-back attempt.
                if ($photo) { $photos->delete($photo['path'], $this->photoOwner($photo['path'])); $photo = null; }
                $this->lockActor($request);
                $user = $id ? User::whereKey($id)->lockForUpdate()->firstOrFail() : new User;
                $oldStatus = $id ? $user->account_status : null;
                $oldRole = $user->role;
                $roleChanged = $id && $oldRole !== $data['role'];
                $active = $data['status'] === 'active';
                // Editing a pending account does not activate it without proving its email token.
                $pending = $id ? ($user->activation_pending && $active) : (! $manual && $active);
                $newActive = $active && ! $pending;
                if ($id) {
                    $this->guardAdministrativeAccess($request, $user, $data['role'], $newActive, $pending);
                    if ($manual && $user->id === $request->user()->id) throw ValidationException::withMessages(['password' => 'Gunakan halaman profil dan password saat ini untuk mengganti password akun sendiri.']);
                    if ($roleChanged && ! $request->boolean('confirm_role_change')) throw ValidationException::withMessages(['confirm_role_change' => 'Konfirmasikan perubahan role sebelum menyimpan.']);
                }
                $emailChanged = $id && $user->email !== $data['email'];
                $revoke = $id && ($roleChanged || $emailChanged || $user->active !== $newActive || $user->activation_pending !== $pending || $manual);
                $oldPhoto = $user->avatar_path;
                // Delete tokens for the original address before applying an email change.
                if ($revoke) $this->revoke($request, $user);
                $user->forceFill(['name' => trim($data['name']), 'email' => $data['email'], 'role' => $data['role'], 'active' => $newActive, 'activation_pending' => $pending]);
                if (array_key_exists('phone', $data)) $user->phone = $data['phone'];
                if ($emailChanged) $user->email_verified_at = null;
                if (! $id || $manual) {
                    $user->password = $manual ? $data['password'] : Str::random(64);
                    $user->must_change_password = $manual;
                }
                $fields = array_diff(array_keys($user->getDirty()), ['password', 'remember_token', 'session_version']);
                $user->save();
                if ($user->role === 'participant') {
                    $profile = $user->profile()->firstOrNew();
                    $profile->fill(array_intersect_key($data, array_flip(['country', 'nationality', 'native_language', 'institution', 'phone'])));
                    $fields = [...$fields, ...array_map(fn ($field) => 'profile.'.$field, array_keys($profile->getDirty()))];
                    $profile->save();
                }
                if ($request->hasFile('photo')) {
                    $photo = $photos->store($request->file('photo'), $user->id);
                    $user->forceFill(['avatar_path' => $photo['path'], 'avatar_version' => $photo['version']])->save();
                    $fields[] = 'photo';
                } elseif ($request->boolean('remove_photo')) {
                    $user->forceFill(['avatar_path' => null, 'avatar_version' => null])->save();$fields[] = 'photo';
                }
                if ($oldPhoto && $oldPhoto !== $user->avatar_path) DB::afterCommit(function () use ($photos, $oldPhoto, $user) {
                    try { $photos->delete($oldPhoto, $user->id); } catch (Throwable $exception) { report($exception); }
                });
                $this->audit($request, $user, $id ? 'users.updated' : 'users.created', ['changed_fields' => array_values(array_unique($fields))]);
                if ($roleChanged) $this->audit($request, $user, 'users.role_changed', ['previous_role' => $oldRole, 'new_role' => $user->role]);
                if ($id && $oldStatus !== $user->account_status) $this->audit($request, $user, $newActive ? 'users.activated' : 'users.deactivated', ['from_status' => $oldStatus, 'to_status' => $user->account_status]);
                return $user;
            }, 3);
        } catch (Throwable $exception) {
            if ($photo) $photos->delete($photo['path'], (int) ($id ?? $this->photoOwner($photo['path'])));
            if ($exception instanceof QueryException && (str_contains($exception->getMessage(), 'users.email') || str_contains($exception->getMessage(), 'users_email_unique'))) throw ValidationException::withMessages(['email' => 'Email sudah digunakan.']);
            throw $exception;
        }
        if ($user->id === $request->user()->id) {
            Auth::guard()->setUser($user);$request->session()->put('user_session_version', (int) $user->session_version);
        }
        $delivery = null;
        if (! $id && $user->activation_pending) $delivery = $credentials->send($user, 'invitation', $request->user()->id, $request->ip());
        return response()->json(['user' => $this->present($user->fresh('profile')->loadCount(['attempts', 'certificates'])), 'message' => $id ? 'Data pengguna berhasil diperbarui.' : 'Pengguna berhasil ditambahkan.', 'delivery_status' => $delivery], $id ? 200 : 201);
    }

    public function status(Request $request, string $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $user = DB::transaction(function () use ($request, $data, $id) {
            $this->lockActor($request);$user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'active' && $user->activation_pending) throw ValidationException::withMessages(['status' => 'Akun menunggu verifikasi email. Kirim ulang tautan aktivasi.']);
            $active = $data['status'] === 'active';
            $this->guardAdministrativeAccess($request, $user, $user->role, $active, false);
            $old = $user->account_status;
            if ($user->active !== $active || $user->activation_pending) {
                $this->revoke($request, $user);$user->forceFill(['active' => $active, 'activation_pending' => false])->save();
                $this->audit($request, $user, $active ? 'users.activated' : 'users.deactivated', ['from_status' => $old, 'to_status' => $user->account_status]);
            }
            return $user;
        }, 3);
        return response()->json(['user' => $this->present($user->load('profile')->loadCount(['attempts', 'certificates'])), 'message' => 'Status akun berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $user = DB::transaction(function () use ($request, $id) {
            $this->lockActor($request);$user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->guardAdministrativeAccess($request, $user, 'participant', false, false, true);
            $this->revoke($request, $user);$user->forceFill(['active' => false, 'activation_pending' => false])->save();
            $this->audit($request, $user, 'users.deleted', ['retained_attempts' => $user->attempts()->count(), 'retained_certificates' => $user->certificates()->count()]);
            $user->delete();return $user;
        }, 3);
        return response()->json(['message' => 'Akun dihapus dari akses aktif. Riwayat ujian dan sertifikat tetap disimpan.', 'user' => $this->present($user)]);
    }

    public function resetPassword(Request $request, string $id, AccountCredentialService $credentials)
    {
        return $this->sendLink($request, $id, $credentials, 'reset');
    }

    public function invite(Request $request, string $id, AccountCredentialService $credentials)
    {
        return $this->sendLink($request, $id, $credentials, 'invitation');
    }

    private function sendLink(Request $request, string $id, AccountCredentialService $credentials, string $purpose)
    {
        $delivery = DB::transaction(function () use ($request, $id, $credentials, $purpose) {
            $this->lockActor($request);$user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($user->id === $request->user()->id) throw ValidationException::withMessages(['user' => 'Gunakan halaman profil untuk mengganti password akun Anda sendiri.']);
            if ($purpose === 'invitation' && ! $user->activation_pending) throw ValidationException::withMessages(['user' => 'Akun ini tidak menunggu aktivasi.']);
            if ($purpose === 'reset' && (! $user->active || $user->activation_pending)) throw ValidationException::withMessages(['user' => 'Reset password tersedia untuk akun aktif. Gunakan aktivasi untuk akun yang masih menunggu.']);
            $result = $credentials->send($user, $purpose, $request->user()->id, $request->ip());
            // Token creation is kept intact here; revocation after delivery must not delete that new token.
            if ($result['sent'] && $purpose === 'reset') {
                $user->session_version++;$user->remember_token = Str::random(60);$user->save();$this->deleteSessions($request, $user);
            }
            return $result;
        }, 3);
        return response()->json(['message' => $delivery['message'], 'delivery_status' => $delivery], $delivery['sent'] ? 200 : ($delivery['status'] === 'throttled' ? 429 : 503));
    }

    public function photo(Request $request, string $id, ProfilePhotoService $photos)
    {
        $user = User::withTrashed()->findOrFail($id);
        abort_unless($photos->validPath($user->avatar_path, $user->id) && Storage::disk('local')->exists($user->avatar_path), 404);
        return Storage::disk('local')->response($user->avatar_path, 'profile.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    /** Existing participant endpoints use the same security/retention rules. */
    public function legacyWrite(Request $request, ?string $id = null)
    {
        if ($id) User::where('role', 'participant')->findOrFail($id);
        $profile = is_array($request->input('profile')) ? $request->input('profile') : [];
        $input = array_merge($request->all(), array_intersect_key($profile, array_flip(['country', 'nationality', 'native_language', 'institution', 'phone'])), [
            'role' => 'participant', 'status' => $request->boolean('active') ? 'active' : 'inactive',
            'password_method' => 'manual', 'password_confirmation' => $request->input('password'),
        ]);
        unset($input['active'], $input['profile']);
        if ($id && ! $request->filled('password')) unset($input['password'], $input['password_confirmation']);
        $request->replace($input);
        $response = $this->write($request, app(ProfilePhotoService::class), app(AccountCredentialService::class), $id);
        return response()->json($response->getData(true)['user'], $response->getStatusCode());
    }

    public function legacyDeactivate(Request $request, string $id)
    {
        User::where('role', 'participant')->findOrFail($id);$request->merge(['status' => 'inactive']);return $this->status($request, $id);
    }

    private function lockActor(Request $request): void
    {
        // One deterministic lock order serializes every operation affecting the active admin set.
        User::where('role', 'superadmin')->orderBy('id')->lockForUpdate()->get(['id']);
        $actor = User::whereKey($request->user()->id)->lockForUpdate()->first();
        abort_unless($actor && $actor->isAdmin() && $actor->active && ! $actor->activation_pending && ! $actor->must_change_password
            && (int) $actor->session_version === (int) $request->user()->session_version, 403);
    }

    private function guardAdministrativeAccess(Request $request, User $user, string $role, bool $active, bool $pending, bool $deleting = false): void
    {
        $removingAccess = $deleting || $role !== 'superadmin' || ! $active || $pending;
        if ($user->id === $request->user()->id && $removingAccess) throw ValidationException::withMessages(['role' => 'Anda tidak dapat menghapus, menonaktifkan, atau menurunkan role akun sendiri.']);
        if ($user->isAdmin() && $user->active && ! $user->activation_pending && $removingAccess
            && User::where('role', 'superadmin')->where('active', true)->where('activation_pending', false)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Superadmin aktif terakhir harus tetap tersedia.']);
        }
    }

    private function revoke(Request $request, User $user): void
    {
        Password::broker()->getRepository()->delete($user);
        $user->session_version = ((int) $user->session_version) + 1;$user->remember_token = Str::random(60);
        $this->deleteSessions($request, $user);
    }

    private function deleteSessions(Request $request, User $user): void
    {
        if (config('session.driver') === 'database') DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($user->id === $request->user()->id, fn ($q) => $q->where('id', '<>', $request->session()->getId()))->delete();
    }

    private function audit(Request $request, User $user, string $event, array $data = []): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'subject_type' => User::class, 'subject_id' => (string) $user->id, 'event' => $event, 'ip' => $request->ip(), 'data' => ['status' => 'success', ...$data]]);
    }

    private function stats(): array
    {
        return ['total' => User::count(), 'superadmins' => User::where('role', 'superadmin')->count(), 'participants' => User::where('role', 'participant')->count(),
            'active' => User::where('active', true)->where('activation_pending', false)->count(), 'inactive' => User::where('active', false)->where('activation_pending', false)->count(),
            'pending_activation' => User::where('activation_pending', true)->count(), 'deleted' => User::onlyTrashed()->count()];
    }

    private function present(User $user): array
    {
        $row = $user->toArray();unset($row['avatar_url']);
        $row['phone'] = $user->role === 'participant' ? $user->profile?->phone : $user->phone;
        $row['admin_avatar_url'] = $user->avatar_path && $user->avatar_version ? '/api/admin/users/'.$user->id.'/photo?v='.rawurlencode($user->avatar_version) : null;
        return $row;
    }

    private function photoOwner(string $path): int { return (int) explode('/', $path)[1]; }
}
