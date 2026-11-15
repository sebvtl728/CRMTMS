<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Crm\Support\CrmMailTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

/**
 * « À faire aujourd'hui »: the overdue and today's actions of a user, each
 * with its title, its fiche and its due time; a link to the « À faire »
 * page. No report, no place.
 */
class DailyDigestMail extends Mailable
{
    /**
     * Private on purpose: public properties of a Mailable are passed to the
     * view and would replace the prepared rows of the same name.
     *
     * @param  Collection<int, Activity>  $overdue
     * @param  Collection<int, Activity>  $today
     */
    public function __construct(private readonly string $name, private readonly Collection $overdue, private readonly Collection $today)
    {
        // Built in the worker, for the organisme of the job: its address, not APP_URL.
        $this->url = TenantUrl::route('crm.todo');
    }

    private readonly string $url;

    public function build(): self
    {
        $count = $this->overdue->count() + $this->today->count();
        $tz = (string) config('app.timezone');
        $line = fn (Activity $a): array => [
            'subject' => $a->subject,
            'fiche' => $a->ficheName(),
            'date' => $a->due_at?->setTimezone($tz)->format('d/m/Y'),
            'time' => $a->due_at?->setTimezone($tz)->format('H:i'),
        ];

        // The organisme's text (« Modèles d'e-mails »): introduction before {actions}, signature after.
        $text = app(MailTemplates::class)->render(CrmMailTemplates::DAILY_DIGEST, [
            'prenom_utilisateur' => explode(' ', trim($this->name))[0],
            'nom_utilisateur' => $this->name,
            'nombre_actions' => $count <= 1 ? "{$count} action" : "{$count} actions",
            'organisme' => app(TenantContext::class)->current()->name ?? '',
        ]);
        [$intro, $signature] = array_pad(explode('{actions}', $text['body'], 2), 2, '');

        return $this->subject($text['subject'])
            ->view('mail.crm-daily-digest', [
                'intro' => trim($intro),
                'signature' => trim($signature),
                'overdue' => $this->overdue->map($line)->all(),
                'today' => $this->today->map($line)->all(),
                'url' => $this->url,
            ]);
    }
}
