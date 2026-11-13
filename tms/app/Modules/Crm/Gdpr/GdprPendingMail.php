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
    public function __construct(public readonly int $count, public readonly Carbon $plannedAt, public readonly int $postponed = 0) {}

    public function build(): self
    {
        $people = $this->count === 1 ? '1 personne' : "{$this->count} personnes";
        $message = (new MailMessage)
            ->subject($this->count > 0 ? "RGPD : {$people} à anonymiser" : 'RGPD : anonymisations reportées')
            ->greeting('Bonjour,');
        if ($this->count > 0) {
            $message->line("{$people} ".($this->count === 1 ? 'a' : 'ont').' dépassé la durée de conservation de leurs données.')
                ->line('Sans action de votre part, '.($this->count === 1 ? 'elle sera anonymisée' : 'elles seront anonymisées').' à partir du '.$this->plannedAt->format('d/m/Y').'.')
                ->line('Vous pouvez conserver une fiche en indiquant un motif depuis l\'écran « À anonymiser ».');
        }
        if ($this->postponed > 0) {
            $message->line(($this->postponed === 1 ? '1 anonymisation prévue a été reportée' : "{$this->postponed} anonymisations prévues ont été reportées")
                .' car un import était en cours : elles seront faites à la prochaine exécution, la nuit prochaine.');
        }
        $message->action('Voir la liste', route('crm.gdpr.index'))
            ->salutation('Cordialement,');

        return $this->subject((string) $message->subject)->html((string) $message->render());
    }
}
