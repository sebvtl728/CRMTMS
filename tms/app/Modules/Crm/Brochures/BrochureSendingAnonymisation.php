<?php

namespace App\Modules\Crm\Brochures;

use Illuminate\Support\Facades\DB;

/**
 * What the anonymisation of a person (Gdpr\Anonymiser, in its transaction)
 * removes from the brochure sendings made to her: her address, the subject
 * and the message (they name her). The sending itself stays (date, author,
 * brochures, status: statistics); her name in the history becomes « Anonyme ».
 */
final class BrochureSendingAnonymisation
{
    /**
     * @param  list<string>  $subjects  the person and the fiches merged into her
     * @return int sendings cleared
     */
    public static function scrub(array $subjects): int
    {
        return DB::table('brochure_sendings')->whereIn('recipient_person_id', $subjects)
            ->update(['recipient_email' => null, 'subject' => null, 'message' => null, 'email_error' => null, 'updated_at' => now()]);
    }
}
