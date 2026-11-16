<?php

namespace App\Modules\Sessions\Fundings;

use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Enrolments\Enrolment;

/**
 * « Reste à financer » of a dossier (Review Focus 1), in integer cents:
 * price of the dossier (Σ of its lines HT) − Σ of its fundings not refused.
 * Per line and per enrolment as well, only for the lines and the enrolments
 * that a funding targets (refused ones included: their target shows what is
 * left; a line also when only its enrolments are targeted): a line against
 * its total HT and the fundings of the line and of its enrolments, an
 * enrolment against its share of a
 * line priced per trainee (price_share; a cancelled enrolment has no share
 * any more and is flagged). A negative remainder means the fundings exceed
 * the price. The list of the dossiers reads only the dossier remainder,
 * for a whole page at once (fundedOf).
 *
 * @phpstan-type Part array{price_cents: int, funded_cents: int, remaining_cents: int}
 * @phpstan-type Balance array{price_cents: int, funded_cents: int, remaining_cents: int, lines: array<string, Part>, enrolments: array<string, array{price_cents: int, funded_cents: int, remaining_cents: int, cancelled: bool}>}
 */
final class FundingBalance
{
    /**
     * @return Balance
     */
    public static function for(Dossier $dossier): array
    {
        $fundings = Funding::query()->where('dossier_id', $dossier->id)->get(['id', 'dossier_line_id', 'enrolment_id', 'amount', 'status']);
        $counted = $fundings->filter(fn (Funding $f) => $f->status->counts());
        $funded = fn ($subset) => Amounts::sum($subset->map(fn (Funding $f) => (string) $f->amount));

        $price = $dossier->priceCents();
        $fundedCents = $funded($counted);

        $enrolmentIds = $fundings->pluck('enrolment_id')->filter()->unique()->values()->all();
        $targeted = $enrolmentIds === [] ? collect() : Enrolment::query()->whereKey($enrolmentIds)->orderBy('created_at')->orderBy('id')->get(['id', 'dossier_line_id', 'status', 'price_share']);
        // enrolment id => its line: the fundings of the enrolments count for their line too.
        $lineOf = $targeted->pluck('dossier_line_id', 'id')->all();

        $lines = [];
        $lineIds = $fundings->pluck('dossier_line_id')->filter()->merge(array_values($lineOf))->unique()->values()->all();
        foreach ($dossier->lines->only($lineIds) as $line) {
            /** @var DossierLine $line */
            $linePrice = Amounts::cents((string) $line->total_ht);
            $lineFunded = $funded($counted->filter(fn (Funding $f) => $f->dossier_line_id === $line->id
                || ($f->enrolment_id !== null && ($lineOf[$f->enrolment_id] ?? null) === $line->id)));
            $lines[$line->id] = ['price_cents' => $linePrice, 'funded_cents' => $lineFunded, 'remaining_cents' => $linePrice - $lineFunded];
        }

        $enrolments = [];
        foreach ($targeted as $enrolment) {
            $share = $enrolment->price_share === null ? 0 : Amounts::cents((string) $enrolment->price_share);
            $shareFunded = $funded($counted->where('enrolment_id', $enrolment->id));
            $enrolments[$enrolment->id] = [
                'price_cents' => $share,
                'funded_cents' => $shareFunded,
                'remaining_cents' => $share - $shareFunded,
                'cancelled' => $enrolment->isCancelled(),
            ];
        }

        return [
            'price_cents' => $price,
            'funded_cents' => $fundedCents,
            'remaining_cents' => $price - $fundedCents,
            'lines' => $lines,
            'enrolments' => $enrolments,
        ];
    }

    /**
     * Σ of the fundings not refused of each dossier, in cents, read in one
     * query for a page of the list (dossiers without a funding: 0).
     *
     * @param  list<string>  $dossierIds
     * @return array<string, int> dossier id => funded cents
     */
    public static function fundedOf(array $dossierIds): array
    {
        $funded = array_fill_keys($dossierIds, 0);
        if ($dossierIds === []) {
            return $funded;
        }
        $rows = Funding::query()->whereIn('dossier_id', $dossierIds)
            ->where('status', '<>', FundingStatus::Refused->value)
            ->get(['dossier_id', 'amount'])->groupBy('dossier_id');
        foreach ($rows as $dossierId => $fundings) {
            $funded[(string) $dossierId] = Amounts::sum($fundings->map(fn (Funding $f) => (string) $f->amount));
        }

        return $funded;
    }

    /**
     * The list cell: « 900,00 € » left to fund, or the excess worded.
     */
    public static function cell(int $remainingCents): string
    {
        return $remainingCents < 0 ? self::message($remainingCents) : Amounts::display($remainingCents);
    }

    /**
     * « Reste à financer : 900,00 € » or « Financements supérieurs au prix : 100,00 € »;
     * a cancelled enrolment: « Financement à reporter : 500,00 € » (what it was given).
     */
    public static function message(int $remainingCents, bool $cancelled = false, int $fundedCents = 0): string
    {
        if ($cancelled) {
            return 'Financement à reporter : '.Amounts::display($fundedCents);
        }

        return $remainingCents < 0
            ? 'Financements supérieurs au prix : '.Amounts::display(-$remainingCents)
            : 'Reste à financer : '.Amounts::display($remainingCents);
    }
}
