<?php

namespace App\Modules\Crm\Brochures;

use Illuminate\Support\Facades\DB;

/**
 * What the anonymisation of a person (Gdpr\Anonymiser, in its transaction)
 * removes from the brochure sendings that concern her:
 * - made to her (she is the recipient): her address, the subject and the
 *   message (they name her);
 * - made from her fiche or from one of her opportunities as particulier
 *   (person_id, whoever the recipient): the subject and the message, which
 *   may name her, like the reports of the actions on her opportunities.
 * The sending itself stays (date, author, brochures, status: statistics); her
 * name in the history becomes « Anonyme ».
 */
final class BrochureSendingAnonymisation
{
    /**
     * @param  list<string>  $subjects  the person and the fiches merged into her
     * @return int sendings cleared
     */
    public static function scrub(array $subjects): int
    {
        $now = now();
        $cleared = DB::table('brochure_sendings')->whereIn('recipient_person_id', $subjects)
            ->update(['recipient_email' => null, 'subject' => null, 'message' => null, 'email_error' => null, 'updated_at' => $now]);

        return $cleared + DB::table('brochure_sendings')->whereIn('person_id', $subjects)
            ->whereNotIn('recipient_person_id', $subjects)
            ->update(['subject' => null, 'message' => null, 'email_error' => null, 'updated_at' => $now]);
    }
}
