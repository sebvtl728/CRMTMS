<?php

namespace App\Modules\Sessions\Gdpr;

use App\Modules\Sessions\Attendance\AttendanceScans;
use App\Modules\Sessions\Sessions\SessionKind;
use Illuminate\Support\Facades\DB;

/**
 * What the anonymisation of a person (Crm Anonymiser, in its transaction,
 * through Crm\Public\FicheLinks) clears in the module Sessions:
 *  - her enrolments: adaptation needs (situation de handicap) and notes;
 *    the enrolment itself stays (session, dates, state, hours, price share:
 *    statistics, BPF, invoicing);
 *  - the fundings she pays (funder), that target one of her enrolments, or
 *    of her dossiers as particulier: notes (amounts, states and agreement
 *    numbers stay);
 *  - her dossiers as particulier: notes;
 *  - her attendance (0.8.0): the reasons of an absence or a partial
 *    attendance (status and minutes stay: realised hours, invoicing); the
 *    monthly hours stay; the signed scans of the sessions stay (collective
 *    proof) except on an individual session (one trainee), where they go.
 * Dossiers of an entreprise she is the contact of keep their notes (they
 * are the entreprise's). Written in SQL: the journal never held the values.
 */
final class EnrolmentAnonymisation
{
    /**
     * @param  list<string>  $personIds  the person and the fiches merged into her
     */
    public static function scrub(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }
        $now = now();

        $enrolments = DB::table('enrolments')->whereIn('person_id', $personIds);
        $enrolments->clone()->where(fn ($q) => $q->whereNotNull('adaptation_needs')->orWhereNotNull('notes'))
            ->update(['adaptation_needs' => null, 'notes' => null, 'updated_at' => $now]);

        DB::table('attendances')->whereNotNull('reason')->whereIn('enrolment_id', $enrolments->clone()->select('id'))
            ->update(['reason' => null, 'updated_at' => $now]);

        // The signed sheets are a proof shared by the whole session: kept, except
        // on an individual session, where the only trainee they concern is her.
        AttendanceScans::neutraliseNames($enrolments->clone()->distinct()->pluck('training_session_id')->all());
        AttendanceScans::purgeOfSessions(DB::table('training_sessions')->where('kind', SessionKind::Individual->value)
            ->whereIn('id', $enrolments->clone()->select('training_session_id'))->pluck('id')->all());

        $ownDossiers = DB::table('dossiers')->whereIn('account_person_id', $personIds);
        DB::table('fundings')->whereNotNull('notes')
            ->where(fn ($q) => $q->whereIn('funder_person_id', $personIds)
                ->orWhereIn('enrolment_id', $enrolments->clone()->select('id'))
                ->orWhereIn('dossier_id', $ownDossiers->clone()->select('id')))
            ->update(['notes' => null, 'updated_at' => $now]);

        $ownDossiers->clone()->whereNotNull('notes')->update(['notes' => null, 'updated_at' => $now]);
    }
}
