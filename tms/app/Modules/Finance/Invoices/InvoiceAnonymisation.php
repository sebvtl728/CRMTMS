<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Sessions\Public\Billing;

/**
 * What the anonymisation of a person does to the invoicing (spec 0.9
 * § 4.7), called by the CRM Anonymiser in its transaction (FicheLinks):
 * the drafts addressed to her and the drafts of the dossiers where she is
 * a trainee (a funder's draft names its trainees) are deleted — they are
 * prepared again from what is due. A validated or sent invoice is an
 * accounting record kept 10 years: never changed (legal obligation).
 */
final class InvoiceAnonymisation
{
    /**
     * @param  list<string>  $personIds  the person and the fiches merged into her
     */
    public static function deleteDrafts(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }
        $dossiers = Billing::dossiersOfTrainees($personIds);
        $drafts = Invoice::query()->where('status', InvoiceStatus::Draft)
            ->where(fn ($q) => $q->where(fn ($r) => $r->where('recipient_type', 'person')->whereIn('recipient_id', $personIds))
                ->orWhereIn('dossier_id', $dossiers))
            // Locked: a draft validated meanwhile is read again and left out (never deleted).
            ->lockForUpdate()->get();
        $drafts->each(function (Invoice $draft): void {
            $draft->lines()->get()->each(fn (InvoiceLine $line) => $line->delete());
            $draft->delete();
        });
    }
}
