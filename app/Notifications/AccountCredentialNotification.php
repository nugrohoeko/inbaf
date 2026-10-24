<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountCredentialNotification extends Notification
{
    public function __construct(public string $token, public string $purpose, public int $expiresInMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return rtrim(config('app.url'), '/').'/set-password?'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->email,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->purpose === 'invitation';

        return (new MailMessage)
            ->subject($invitation ? 'Aktifkan akun INBAF Anda' : 'Atur ulang kata sandi INBAF')
            ->greeting('Halo '.$notifiable->name.',')
            ->line($invitation ? 'Administrator telah membuat akun INBAF untuk Anda. Tentukan kata sandi pribadi untuk mengaktifkan akun.' : 'Administrator meminta pengaturan ulang kata sandi akun INBAF Anda.')
            ->action($invitation ? 'Aktifkan akun dan buat kata sandi' : 'Buat kata sandi baru', $this->url($notifiable))
            ->line('Tautan ini berlaku selama '.$this->expiresInMinutes.' menit dan hanya dapat digunakan satu kali.')
            ->line('Jika Anda tidak mengenali permintaan ini, abaikan email ini dan hubungi administrator.');
    }
}
