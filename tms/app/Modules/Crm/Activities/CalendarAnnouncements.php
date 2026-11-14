<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Crm\Activities\Events\ActivityChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Announces (ActivityChanged, once committed) the meetings and calls whose
 * calendar event depends on a fiche or an opportunity that changed without
 * the action itself being saved: fiche or opportunity sent to the trash or
 * restored, fiches merged (raw SQL move), opportunity moved to another DAS
 * or account. Calendars (Outlook) then read the current state again.
 *
 * Only the actions a calendar may still hold: dated, with a responsible,
 * to do or due from yesterday on (what a connection sends).
 */
final class CalendarAnnouncements
{
    public const COLUMNS = ['organisation_id', 'person_id', 'opportunity_id'];

    private const CHUNK = 500;

    /**
     * @param  string  $column  one of COLUMNS
     * @param  string|list<string|null>  $ids
     */
    public static function announceFor(string $column, string|array $ids): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Colonne inconnue : {$column}.");
        }
        $ids = array_values(array_unique(array_filter((array) $ids, fn (?string $id) => $id !== null && $id !== '')));
        $types = $ids === [] ? [] : WeekPlanning::types();
        if ($types === []) {
            return;
        }
        $since = CarbonImmutable::now()->subDay()->format('Y-m-d H:i:sP');

        Activity::query()
            ->whereIn('type_id', array_keys($types))
            ->whereNotNull('due_at')->whereNotNull('responsible_user_id')
            ->where(fn ($q) => $q->whereNull('done_at')->orWhereRaw('due_at >= ?::timestamptz', [$since]))
            ->where(function ($q) use ($column, $ids): void {
                $q->whereIn($column, $ids);
                if ($column !== 'opportunity_id') {
                    // The actions of the fiche's opportunities show its name too.
                    $q->orWhereIn('opportunity_id', DB::table('opportunities')->select('id')->whereIn($column, $ids));
                }
            })
            ->select(['id', 'responsible_user_id'])
            ->lazyById(self::CHUNK)
            ->each(fn (Activity $activity) => ActivityChanged::dispatch($activity->id, $activity->responsible_user_id));
    }
}
