<?php

namespace App\Services;

use App\Models\{AuditLog, User};
use App\Notifications\AccountCredentialNotification;
use Illuminate\Support\Facades\{DB, Password};
use InvalidArgumentException;
use Throwable;

class AccountCredentialService
{
    /** Send a one-use credential link. Neither the response nor audit contains the token. */
    public function send(User $user, string $purpose, ?int $actorId = null, ?string $ip = null): array
    {
        if (! in_array($purpose, ['invitation', 'reset'], true)) {
            throw new InvalidArgumentException('Unknown credential purpose.');
        }
        return DB::transaction(function () use ($user, $purpose, $actorId, $ip) {
            $subjectId = $user->id;
            // Account creation commits before its first email is sent. Lock and
            // reload the identity so an intervening edit cannot issue a token
            // for an obsolete email after that edit has revoked its tokens.
            $user = User::withTrashed()->whereKey($subjectId)->lockForUpdate()->first();
            $expires = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
            $result = ['sent' => false, 'status' => 'failed', 'purpose' => $purpose, 'expires_in_minutes' => $expires];
            if (! $user || $user->trashed()) {
                $result['message'] = 'Akun yang dihapus tidak dapat menerima tautan kata sandi.';
            } elseif (($purpose === 'invitation' && ! $user->activation_pending)
                || ($purpose === 'reset' && (! $user->active || $user->activation_pending))) {
                $result['message'] = 'Status akun telah berubah dan tidak memenuhi ketentuan pengiriman tautan ini.';
            // A logging transport would write a live credential into application logs.
            } elseif (! $this->deliveryConfigured((string) config('mail.default'))) {
                $result['status'] = 'not_configured';
                $result['message'] = 'Pengiriman email belum dikonfigurasi. Atur mailer SMTP sebelum mengirim tautan.';
            } elseif (Password::broker()->getRepository()->recentlyCreatedToken($user)) {
                $result['status'] = 'throttled';
                $result['message'] = 'Tautan baru saja dikirim. Tunggu satu menit sebelum mengirim ulang.';
            } else {
                $token = Password::broker()->createToken($user);
                try {
                    $user->notify(new AccountCredentialNotification($token, $purpose, $expires));
                    $result['sent'] = true;
                    $result['status'] = 'sent';
                    $result['message'] = 'Tautan kata sandi telah dikirim ke email pengguna.';
                } catch (Throwable $exception) {
                    Password::broker()->deleteToken($user);
                    // Transport exceptions may contain the entire message or link. Do not log them.
                    $result['message'] = 'Email gagal dikirim. Periksa konfigurasi pengiriman lalu coba lagi.';
                }
            }
            AuditLog::create([
                'user_id' => $actorId, 'event' => 'users.credentials.'.$purpose,
                'subject_type' => User::class, 'subject_id' => (string) $subjectId, 'ip' => $ip,
                'data' => ['status' => $result['status'], 'expires_in_minutes' => $expires],
            ]);

            return $result;
        });
    }

    private function deliveryConfigured(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) return false;
        $visited[] = $mailer;
        $settings = config('mail.mailers.'.$mailer, []);
        $transport = $settings['transport'] ?? null;
        if (app()->runningUnitTests() && $transport === 'array') return true;
        if (! $transport || in_array($transport, ['log', 'array'], true)) return false;
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $settings['mailers'] ?? [];
            return count($children) > 0 && collect($children)->every(fn ($child) => $this->deliveryConfigured($child, $visited));
        }

        return true;
    }
}
