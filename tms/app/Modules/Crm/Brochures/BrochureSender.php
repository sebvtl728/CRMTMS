<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a « Envoyer une plaquette » (the row and the exact versions) and
 * queues SendBrochure after the commit. The recipient must be one of
 * BrochureRecipients for the subject (the fiche, or the opportunity) and
 * have an e-mail address, frozen on the row.
 */
final class BrochureSender
{
    /**
     * @param  list<string>  $versionIds  validated (offered, within the limit)
     *
     * @throws ValidationException recipient not attached to the fiche or without e-mail
     */
    public function send(Organisation|Person $fiche, ?Opportunity $opportunity, array $versionIds, string $recipientId, string $subject, string $message, User $user): BrochureSending
    {
        $recipient = collect(BrochureRecipients::for($opportunity ?? $fiche, $user))->firstWhere('id', $recipientId);
        if ($recipient === null) {
            throw ValidationException::withMessages(['recipient_person_id' => 'Ce destinataire n\'est pas rattaché à cette fiche.']);
        }
        if ($recipient['email'] === null) {
            throw ValidationException::withMessages(['recipient_person_id' => 'Ce destinataire n\'a pas d\'adresse e-mail.']);
        }

        return DB::transaction(function () use ($fiche, $opportunity, $versionIds, $recipient, $subject, $message, $user): BrochureSending {
            $sending = BrochureSending::query()->create([
                'organisation_id' => $fiche instanceof Organisation ? $fiche->id : null,
                'person_id' => $fiche instanceof Person ? $fiche->id : null,
                'opportunity_id' => $opportunity?->id,
                'recipient_person_id' => $recipient['id'],
                'recipient_email' => $recipient['email'],
                'subject' => $subject,
                'message' => $message,
                'email_status' => BrochureSending::PENDING,
                'created_by' => $user->id,
            ]);
            foreach ($versionIds as $position => $versionId) {
                DB::table('brochure_sending_versions')->insert([
                    'tenant_id' => $sending->tenant_id,
                    'brochure_sending_id' => $sending->id,
                    'brochure_version_id' => $versionId,
                    'position' => $position,
                ]);
            }
            SendBrochure::dispatch($sending->id)->afterCommit();

            return $sending;
        });
    }
}
