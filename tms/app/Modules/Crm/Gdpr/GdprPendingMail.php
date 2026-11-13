<?php

namespace App\Modules\Crm\Gdpr;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

/**
 * « N personnes à anonymiser » for the gdpr managers: a number and a link,
 * never a name.
 */
class GdprPendingMail extends Mailable
{
    public function __construct(public readonly int $count, public readonly Carbon $plannedAt) {}

    public function build(): self
    {
        $people = $this->count === 1 ? '1 personne' : "{$this->count} personnes";
        $message = (new MailMessage)
            ->subject("RGPD : {$people} à anonymiser")
            ->greeting('Bonjour,')
            ->line("{$people} ".($this->count === 1 ? 'a' : 'ont').' dépassé la durée de conservation de leurs données.')
            ->line('Sans action de votre part, '.($this->count === 1 ? 'elle sera anonymisée' : 'elles seront anonymisées').' à partir du '.$this->plannedAt->format('d/m/Y').'.')
            ->line('Vous pouvez conserver une fiche en indiquant un motif depuis l\'écran « À anonymiser ».')
            ->action('Voir la liste', route('crm.gdpr.index'))
            ->salutation('Cordialement,');

        return $this->subject((string) $message->subject)->html((string) $message->render());
    }
}
