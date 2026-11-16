<?php

namespace App\Modules\Sessions\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The closure periods of the organisme (Paris days, bounds included).
 */
final class ClosurePeriods
{
    /**
     * The closure covering the Paris day of $date (the earliest starting
     * when several overlap), null when the organisme is open.
     */
    public static function covering(CarbonImmutable $date): ?ClosurePeriod
    {
        $day = $date->setTimezone('Europe/Paris')->toDateString();

        return ClosurePeriod::query()->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)
            ->orderBy('starts_on')->orderBy('id')->first();
    }

    /**
     * Closure periods overlapping the days $from to $to (bounds included).
     *
     * @return Collection<int, ClosurePeriod>
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ClosurePeriod::query()->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, ClosurePeriod>
     */
    public static function all(): Collection
    {
        return ClosurePeriod::query()->orderByDesc('starts_on')->orderBy('id')->get();
    }

    /**
     * @param  array{starts_on: string, ends_on: string, label: string}  $data
     */
    public static function save(?ClosurePeriod $period, array $data): ClosurePeriod
    {
        return DB::transaction(function () use ($period, $data): ClosurePeriod {
            $period ??= new ClosurePeriod;
            $period->fill($data)->save();

            return $period;
        });
    }

    public static function delete(ClosurePeriod $period): void
    {
        DB::transaction(fn () => $period->delete());
    }
}
