<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'email_verified_at', 'text_size', 'preferred_language'])]
#[Hidden(['password', 'remember_token', 'avatar_path', 'avatar_version', 'session_version'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $attributes = ['text_size' => 'normal', 'session_version' => 1, 'must_change_password' => false, 'activation_pending' => false];

    protected $appends = ['avatar_url', 'account_status'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'activation_pending' => 'boolean',
            'session_version' => 'integer',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path && $this->avatar_version
            ? '/api/account/photo?v='.rawurlencode($this->avatar_version) : null);
    }

    protected function accountStatus(): Attribute
    {
        return Attribute::get(fn () => $this->trashed() ? 'deleted' : ($this->activation_pending ? 'pending_activation' : ($this->active ? 'active' : 'inactive')));
    }

    public function profile() { return $this->hasOne(ParticipantProfile::class); }
    public function attempts() { return $this->hasMany(ExamAttempt::class); }
    public function certificates() { return $this->hasMany(Certificate::class); }
    public function isAdmin(): bool { return $this->role === 'superadmin'; }
}
