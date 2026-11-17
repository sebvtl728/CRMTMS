<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Collection;

/**
 * The days a session shows on a calendar: its séance days (cancelled ones
 * aside), and the working days (PublicHolidays, closures, weekends left out)
 * of its interval modules; a session with neither séances nor interval
 * modules (séances still to plan): the working days of its dates. Never a
 * Saturday, a Sunday, a holiday nor a closure without a séance there.
 */
final class SessionDays
{
    /**
     * @param  Collection<int, TrainingSession>  $sessions
     * @param  Collection<int, ClosurePeriod>  $closures  overlapping [$from, $to]
     * @return array<string, list<string>> session id => days within [$from, $to], in order
     */
    public static function for(Collection $sessions, string $from, string $to, Collection $closures): array
    {
        $ids = $sessions->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $seanceDays = [];
        foreach (Seance::query()->whereIn('training_session_id', $ids)->where('status', Seance::PLANNED)
            ->whereBetween('date', [$from, $to])->select(['training_session_id', 'date'])->distinct()->get() as $s) {
            $seanceDays[$s->training_session_id][] = $s->date->toDateString();
        }
        $withSeances = array_flip(Seance::query()->whereIn('training_session_id', $ids)->distinct()->pluck('training_session_id')->all());
        $modules = SessionModule::query()->whereIn('training_session_id', $ids)->where('dating_mode', DatingMode::Interval->value)->get()->groupBy('training_session_id');
        $working = array_flip(WorkingDays::between($from, $to, $closures));
        $workingBetween = function (string $start, string $end) use ($working, $from, $to): array {
            $days = [];
            foreach ($working as $day => $_) {
                if ($day >= max($start, $from) && $day <= min($end, $to)) {
                    $days[] = $day;
                }
            }

            return $days;
        };

        $out = [];
        foreach ($sessions as $session) {
            $start = $session->starts_on->toDateString();
            $end = $session->ends_on->toDateString();
            $days = $seanceDays[$session->id] ?? [];
            foreach ($modules[$session->id] ?? [] as $module) {
                $days = [...$days, ...$workingBetween($module->starts_on?->toDateString() ?? $start, $module->ends_on?->toDateString() ?? $end)];
            }
            if (! isset($withSeances[$session->id]) && ! isset($modules[$session->id])) {
                $days = $workingBetween($start, $end);
            }
            $days = array_values(array_unique($days));
            sort($days);
            $out[$session->id] = $days;
        }

        return $out;
    }
}
