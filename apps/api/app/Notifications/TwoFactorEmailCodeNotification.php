<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorEmailCodeNotification extends Notification
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
            ->subject('Codigo de seguridad - CharBook Library')
            ->greeting("Hola, {$notifiable->name}")
            ->line('Usa este codigo para confirmar una accion de seguridad en tu cuenta:')
            ->line("**{$this->code}**")
            ->line('Expira en 10 minutos.')
            ->line('Si no solicitaste este codigo, ignora este correo y cambia tu contrasena.')
            ->salutation('- Equipo CharBook');
    }
}
