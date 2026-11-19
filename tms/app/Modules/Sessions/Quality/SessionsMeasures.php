<?php

namespace App\Modules\Sessions\Quality;

use App\Modules\Qualite\Public\Measures;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * Qualiopi indicator 12: the abandon rate of the enrolments of the sessions
 * started in the last 12 months (Paris days; waiting list and cancelled
 * enrolments left out).
 */
final class SessionsMeasures
{
    public static function register(): void
    {
        Measures::register(12, 'sessions.abandons', fn () => self::abandons());
    }

    /** @return array<string, mixed> */
    public static function abandons(): array
    {
        $today = CarbonImmutable::now('Europe/Paris')->startOfDay();
        $from = $today->subYear();
        $counts = Enrolment::query()
            ->whereIn('status', [EnrolmentStatus::Enrolled->value, EnrolmentStatus::Confirmed->value, EnrolmentStatus::Abandoned->value])
            ->whereIn('training_session_id', TrainingSession::query()->select('id')
                ->whereBetween('starts_on', [$from->toDateString(), $today->toDateString()]))
            ->selectRaw('count(*) as total, count(*) filter (where status = ?) as abandoned', [EnrolmentStatus::Abandoned->value])
            ->toBase()->first();
        $total = (int) ($counts->total ?? 0);
        $abandoned = (int) ($counts->abandoned ?? 0);
        $rate = $total === 0 ? null : (int) round($abandoned * 100 / $total);

        return [
            'label' => 'Taux d\'abandon (12 derniers mois)',
            'value' => $rate === null ? '—' : $rate.' %',
            'detail' => sprintf('%d abandon%s sur %d inscription%s (sessions commencées depuis le %s)',
                $abandoned, $abandoned > 1 ? 's' : '', $total, $total > 1 ? 's' : '', $from->format('d/m/Y')),
            'tone' => 'neutral',
        ];
    }
}
