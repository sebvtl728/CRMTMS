<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Crm\Support\CrmMailTemplates;
use Illuminate\Mail\Mailable;

/**
 * « Action confiée »: who hands it, type, subject, due date (Paris), purpose,
 * the optional message and a link to the action in « À faire ». Plain text
 * only. Never the report nor the next steps.
 */
class ActivityAssignedMail extends Mailable
{
    /** Built in the worker, for the organisme of the job: its address, not APP_URL. */
    private readonly string $url;

    public function __construct(private readonly Activity $activity, private readonly string $byName, private readonly ?string $note)
    {
        $this->url = TenantUrl::route('crm.todo', ['action' => $activity->id]);
    }

    public function build(): self
    {
        $tz = (string) config('app.timezone');
        $due = $this->activity->due_at?->setTimezone($tz);
        $purpose = trim((string) $this->activity->purpose);

        $text = app(MailTemplates::class)->render(CrmMailTemplates::ACTIVITY_ASSIGNED, [
            'auteur' => $this->byName,
            'type' => $this->activity->type->label ?? 'Action',
            'objet' => $this->activity->subject,
            'echeance' => $due === null ? '-' : $due->format('d/m/Y').' à '.$due->format('H:i'),
            'motif' => $purpose === '' ? '-' : $purpose,
            'message' => trim((string) $this->note),
            'lien' => $this->url,
        ]);
        // An empty message leaves no hole of blank lines.
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $text['body']));

        return $this->subject($text['subject'])->text('mail.plain-text', ['text' => $body]);
    }
}
