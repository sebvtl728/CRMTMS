<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Mail\MailServiceProvider;
use App\Modules\Core\Mail\MailTemplates;
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

    /**
     * The organisme's text (« Modèles d'e-mails »: first paragraph as the
     * greeting, the others as lines; values escaped by the mail layout),
     * then what the system always adds: the button, the validity, the warning.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $text = app(MailTemplates::class)->render(MailServiceProvider::INVITATION, [
            'prenom_utilisateur' => explode(' ', trim($this->name))[0],
            'nom_utilisateur' => $this->name,
            'organisme' => $this->organisation,
            'application' => (string) config('app.name'),
        ]);
        // Plain text: Markdown is escaped (the mail layout renders Markdown), the
        // greeting is the first line only, the rest plain paragraphs.
        $lines = preg_split('/\R/', trim($text['body'])) ?: [];
        $greeting = trim((string) array_shift($lines));
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', implode("\n", $lines)) ?: []), fn (string $p) => $p !== ''));

        $message = (new MailMessage)->subject($text['subject'])->greeting(self::plain($greeting !== '' ? $greeting : 'Bonjour,'));
        foreach ($paragraphs as $paragraph) {
            $message->line(self::plain($paragraph));
        }

        return $message
            ->action('Activer mon compte', $this->url)
            ->line('Ce lien est valable '.Invitation::VALIDITY_HOURS.' heures et ne peut être utilisé qu\'une seule fois.')
            ->line('Si vous n\'attendiez pas cette invitation, ignorez ce message.')
            ->salutation('Cordialement,');
    }

    /**
     * Text shown as typed by the mail layout's Markdown: every Markdown
     * punctuation is backslash-escaped; « < », « > » and « & » are left to the
     * layout, which HTML-encodes them (escaping them twice would show « &lt; »).
     */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', $text);
    }
}
