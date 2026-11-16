<?php

namespace App\Modules\Sessions\Gdpr;

use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The people the module keeps out of the RGPD retention
 * (Crm\Public\FicheLinks::registerRetentionHold), $threshold being now minus
 * the retention period of the organisme:
 *
 *  - a trainee with an enrolment not cancelled whose session ends on or
 *    after the threshold (or in the future);
 *  - the particulier contractant or the contact of a dossier created on or
 *    after the threshold;
 *  - the person funding a dossier (funding created on or after the threshold).
 *
 * Trashed sessions and dossiers count: their data is still held.
 */
final class RetentionHolds
{
    public static function enrolments(CarbonImmutable $threshold): Builder
    {
        return DB::table('enrolments')->select('enrolments.person_id')
            ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
            ->where('enrolments.status', '!=', EnrolmentStatus::Cancelled->value)
            ->where('training_sessions.ends_on', '>=', $threshold->toDateString());
    }

    public static function dossiers(CarbonImmutable $threshold): Builder
    {
        $contacts = DB::table('dossiers')->select('contact_person_id as person_id')
            ->whereNotNull('contact_person_id')->where('created_at', '>=', $threshold);

        return DB::table('dossiers')->select('account_person_id as person_id')
            ->whereNotNull('account_person_id')->where('created_at', '>=', $threshold)
            ->unionAll($contacts);
    }

    public static function fundings(CarbonImmutable $threshold): Builder
    {
        return DB::table('fundings')->select('funder_person_id as person_id')
            ->whereNotNull('funder_person_id')->where('created_at', '>=', $threshold);
    }
}
