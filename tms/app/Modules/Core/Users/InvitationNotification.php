<?php

namespace App\Modules\Core\Users;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Activation e-mail. Sent synchronously: the plain token must never be
 * serialized into a queue payload.
 */
class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly string $organisation,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Activez votre compte '.config('app.name'))
            ->greeting('Bonjour '.$this->name.',')
            ->line('Vous êtes invité à rejoindre l\'espace '.$this->organisation.'.')
            ->line('Choisissez votre mot de passe pour activer votre compte.')
            ->action('Activer mon compte', $this->url)
            ->line('Ce lien est valable '.Invitation::VALIDITY_HOURS.' heures et ne peut être utilisé qu\'une seule fois.')
            ->line('Si vous n\'attendiez pas cette invitation, ignorez ce message.')
            ->salutation('Cordialement,');
    }
}
