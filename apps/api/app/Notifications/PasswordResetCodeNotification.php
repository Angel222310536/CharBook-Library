<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification
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
            ->subject('Recupera tu contraseña — CharBook Library')
            ->greeting("Hola, {$notifiable->name}")
            ->line('Recibimos una solicitud para restablecer tu contraseña.')
            ->line('Tu código de verificación es:')
            ->line("**{$this->code}**")
            ->line('Este código expira en 20 minutos.')
            ->line('Si no fuiste tú, ignora este correo y tu contraseña no se cambiará.')
            ->salutation('— Equipo CharBook');
    }
}
