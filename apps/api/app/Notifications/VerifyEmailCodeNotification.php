<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirma tu correo — CharBook Library')
            ->greeting("¡Bienvenid@, {$notifiable->name}!")
            ->line('Para activar las funciones de tu cuenta, confirma tu dirección con este código:')
            ->line("**{$this->code}**")
            ->line('Vence en 30 minutos.')
            ->salutation('— Equipo CharBook');
    }
}
