<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\DossierLine;

/**
 * Shares of a training line priced per trainee (spec lot 2a § 10): its
 * total HT split in equal parts, to the cent, between the enrolments that
 * share the price (enrolled, confirmed and abandoned: an abandon keeps its
 * share), the remainder of the division going one cent each to the first
 * enrolments in order of enrolment (created_at, id): the sum is exact.
 * 1 000,00 € for 3 → 333,34 + 333,33 + 333,33.
 *
 * Waitlisted and cancelled enrolments, and every enrolment of a line priced
 * for the group, carry no share (null). Integer cents only, never a float.
 * Called inside the transaction that changed the line or its enrolments
 * (the dossier row locked by the caller).
 */
final class PriceShares
{
    public static function recompute(DossierLine $line): void
    {
        $enrolments = Enrolment::query()
            ->where('dossier_line_id', $line->id)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $perTrainee = $line->kind === DossierLine::TRAINING && $line->pricing_mode === DossierLine::PER_TRAINEE;
        $sharing = $perTrainee
            ? $enrolments->filter(fn (Enrolment $e) => in_array($e->status, EnrolmentStatus::SHARING_THE_PRICE, true))->values()
            : collect();

        $shares = self::split(Amounts::cents((string) $line->total_ht), $sharing->count());
        $byId = [];
        foreach ($sharing as $i => $enrolment) {
            $byId[$enrolment->id] = Amounts::decimal($shares[$i]);
        }

        foreach ($enrolments as $enrolment) {
            $share = $byId[$enrolment->id] ?? null;
            if ($enrolment->price_share !== $share) {
                $enrolment->price_share = $share;
                $enrolment->save();
            }
        }
    }

    /**
     * $cents in $count equal parts, the remainder one cent each to the first.
     *
     * @return list<int>
     */
    public static function split(int $cents, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $base = intdiv($cents, $count);
        $remainder = $cents - $base * $count;

        $shares = [];
        for ($i = 0; $i < $count; $i++) {
            $shares[] = $base + ($i < $remainder ? 1 : 0);
        }

        return $shares;
    }
}
