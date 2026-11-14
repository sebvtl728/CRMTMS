<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;

/**
 * Success rate of the quotes: among the quotes SENT in a period (sent_at,
 * Paris calendar days), how many were accepted (at any time since). A
 * quote replaced by a new version (replaced_by_id) is left out: only the
 * new version counts, when it is sent. Within what the user sees (Responsable
 * DAS: his DAS only) and the filters of the « Devis » list, except the
 * status and the issue dates (the period replaces them).
 */
final class QuoteSuccessRate
{
    /** @var array<string, string> period key => French label */
    public const PERIODS = ['month' => 'Mois', 'quarter' => 'Trimestre', 'year' => 'Année'];

    /**
     * @param  CarbonImmutable  $from  first day (its Paris calendar day)
     * @param  CarbonImmutable  $to  last day, included (its Paris calendar day)
     * @param  array<array-key, mixed>  $filters  filters of the list (QuoteListQuery::filters input)
     * @return array{sent: int, accepted: int, rate: float|null} rate in %, one decimal; null when none was sent
     */
    public static function for(User $user, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $tz = (string) config('app.timezone');
        $start = CarbonImmutable::parse($from->setTimezone($tz)->toDateString(), $tz);
        $end = CarbonImmutable::parse($to->setTimezone($tz)->toDateString(), $tz)->addDay();

        $listFilters = QuoteListQuery::filters([...$filters, 'status' => null, 'issued_from' => null, 'issued_to' => null]);
        $row = QuoteListQuery::build($listFilters, $user)
            ->reorder()
            ->where('quotes.sent_at', '>=', $start->format('Y-m-d H:i:sP'))
            ->where('quotes.sent_at', '<', $end->format('Y-m-d H:i:sP'))
            ->whereNull('quotes.replaced_by_id')
            ->toBase()
            ->selectRaw('count(*) as sent, count(*) filter (where quotes.status = ?) as accepted', [QuoteStatus::Accepted->value])
            ->first();

        $sent = (int) ($row->sent ?? 0);
        $accepted = (int) ($row->accepted ?? 0);

        return [
            'sent' => $sent,
            'accepted' => $accepted,
            'rate' => $sent === 0 ? null : round($accepted * 100 / $sent, 1),
        ];
    }

    /**
     * The current month, quarter or year (Paris) around $now; an unknown key is the month.
     *
     * @return array{period: string, label: string, from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function period(mixed $key, CarbonImmutable $now): array
    {
        $key = is_string($key) && array_key_exists($key, self::PERIODS) ? $key : 'month';
        $local = $now->setTimezone((string) config('app.timezone'))->locale('fr');

        return match ($key) {
            'quarter' => [
                'period' => $key,
                'label' => ($local->quarter === 1 ? '1er' : $local->quarter.'e').' trimestre '.$local->year,
                'from' => $local->startOfQuarter(),
                'to' => $local->endOfQuarter(),
            ],
            'year' => ['period' => $key, 'label' => 'Année '.$local->year, 'from' => $local->startOfYear(), 'to' => $local->endOfYear()],
            default => [
                'period' => 'month',
                'label' => ucfirst($local->isoFormat('MMMM YYYY')),
                'from' => $local->startOfMonth(),
                'to' => $local->endOfMonth(),
            ],
        };
    }

    /**
     * The block above the list: the rate of the chosen period and the choices.
     *
     * @param  array<array-key, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function banner(User $user, mixed $periodKey, CarbonImmutable $now, array $filters): array
    {
        $period = self::period($periodKey, $now);

        return [
            ...self::for($user, $period['from'], $period['to'], $filters),
            'period' => $period['period'],
            'label' => $period['label'],
            'periods' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::PERIODS), self::PERIODS),
        ];
    }
}
