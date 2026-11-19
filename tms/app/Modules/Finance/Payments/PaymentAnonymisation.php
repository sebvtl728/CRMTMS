<?php

namespace App\Modules\Finance\Payments;

/**
 * Anonymising a person (spec 0.9.1 § 4.6): the payments she made are
 * accounting records kept 10 years (amount, date, method, allocations
 * stay); their free texts that may describe her (notes, reference: a
 * cheque number, a transfer label) are cleared, journaled without value.
 */
final class PaymentAnonymisation
{
    /**
     * @param  list<string>  $personIds  the person and the fiches merged into her
     */
    public static function clear(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }
        Payment::query()->where('payer_type', 'person')->whereIn('payer_id', $personIds)
            ->where(fn ($q) => $q->whereNotNull('notes')->orWhereNotNull('reference'))
            ->get()->each(fn (Payment $p) => $p->fill(['notes' => null, 'reference' => null])->save());
    }
}
