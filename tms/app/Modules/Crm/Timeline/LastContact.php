<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps last_contact_at (list column, GDPR retention clock) in step with the
 * notes. Written without touching updated_at nor the journal: the note is
 * the trace.
 */
final class LastContact
{
    public static function record(string $subjectType, string $subjectId, CarbonInterface $at): void
    {
        if ($subjectType === (new Person)->getMorphClass()) {
            self::set('people', [$subjectId], $at);
            $organisations = DB::table('affiliations')->where('person_id', $subjectId)
                ->whereNull('ended_on')->whereNull('deleted_at')->pluck('organisation_id')->all();
            self::set('organisations', $organisations, $at);
        } elseif ($subjectType === (new Organisation)->getMorphClass()) {
            self::set('organisations', [$subjectId], $at);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private static function set(string $table, array $ids, CarbonInterface $at): void
    {
        if ($ids === []) {
            return;
        }

        DB::table($table)->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('last_contact_at')->orWhere('last_contact_at', '<', $at))
            ->update(['last_contact_at' => $at]);
    }
}
