<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\CrmMailTemplates;

/**
 * What a fiche page needs to open « Envoyer une plaquette »: the people it
 * can go to, each with the subject and message proposed by the organisme's
 * « brochure » e-mail template (still editable in the window). Null without
 * the right (crm.activities.update), on a trashed or anonymised fiche, or
 * when nobody can receive it.
 */
final class BrochureSendProps
{
    /**
     * @return array{recipients: list<array{id: string, name: string, email: string|null, subject: string, body: string}>, delivers_nowhere: bool}|null
     */
    public static function for(Organisation|Person|Opportunity $subject, User $user): ?array
    {
        $fiche = $subject instanceof Opportunity ? ($subject->organisation ?? $subject->person) : $subject;
        if ($fiche === null || $fiche->trashed() || ($subject instanceof Opportunity && $subject->trashed())
            || ($fiche instanceof Person && $fiche->anonymised_at !== null) || ! $user->can(Permissions::ACTIVITIES_UPDATE)) {
            return null;
        }

        $recipients = BrochureRecipients::for($subject, $user);
        if ($recipients === []) {
            return null;
        }

        $templates = app(MailTemplates::class);
        $organisme = app(TenantContext::class)->current()->name ?? '';

        return [
            'recipients' => array_map(function (array $r) use ($templates, $user, $organisme): array {
                $text = $templates->render(CrmMailTemplates::BROCHURE, ['destinataire' => $r['name'], 'commercial' => $user->name, 'organisme' => $organisme]);

                return [...$r, 'subject' => $text['subject'], 'body' => $text['body']];
            }, $recipients),
            'delivers_nowhere' => TenantMailer::deliversNowhere(null),
        ];
    }
}
