<?php

namespace App\Modules\Admissions\Booklet;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Admission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The monthly pages of the booklet: the months of the contract (up to the
 * current month of Paris on screen, all of them in the PDF), a « company »
 * page each month and a « centre » page in the months with séances (or
 * once something was typed on it), with their state; the centre
 * observations and the visa of the trainer; « Signé sur papier ».
 */
final class BookletMonths
{
    public const NOT_IN_CONTRACT = 'Ce mois n\'est pas dans le contrat.';

    public const FUTURE = 'Ce mois n\'a pas encore commencé.';

    public const VISA_FIRST = 'Retirez d\'abord le visa.';

    public const SIGNED = 'Cette part est déjà signée.';

    private const MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public static function label(CarbonImmutable $month): string
    {
        return self::MONTHS[(int) $month->format('n')].' '.$month->format('Y');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Paris')->startOfDay();
    }

    /**
     * The months of the contract, most recent first on screen ($wholeContract
     * false: up to the current month of Paris), oldest first in the PDF.
     *
     * @return list<array{month: string, label: string, places: list<string>, centre_minutes: int, pages: array<string, array{entries: array<string, ?string>, observations: array<string, ?string>, apprentice: array{status: string, at: ?string, name: ?string, drawing: ?string}, tutor: ?array{status: string, at: ?string, name: ?string, drawing: ?string}, visa: ?array{by: ?string, at: string}}>, links: array<string, ?array{email: ?string, sent_at: ?string, expires_on: string, email_status: string, open: bool}>}>
     */
    public static function months(Candidature $candidature, bool $wholeContract = false): array
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        if ($contract === null) {
            return [];
        }
        $centre = self::centreMinutes($candidature);
        [$start, $end] = self::range($contract);
        if (! $wholeContract) {
            $current = self::today()->startOfMonth();
            $end = $end->lessThan($current) ? $end : $current;
        }

        $rows = BookletMonth::query()->where('candidature_id', $candidature->id)->get()
            ->keyBy(fn (BookletMonth $m) => $m->month->format('Y-m').'|'.$m->place);
        $links = BookletLink::query()->where('candidature_id', $candidature->id)->orderBy('created_at')->get()
            ->keyBy(fn (BookletLink $l) => $l->month->format('Y-m').'|'.$l->signer);
        $visaNames = User::query()->whereIn('id', $rows->pluck('visa_by')->filter()->unique()->values())->pluck('name', 'id');

        $months = [];
        for ($m = $start; $m <= $end && count($months) < 48; $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $minutes = (int) ($centre[$key] ?? 0);
            $places = [BookletMonth::COMPANY];
            if ($minutes > 0 || $rows->has($key.'|'.BookletMonth::CENTRE)) {
                $places[] = BookletMonth::CENTRE;
            }
            $pages = [];
            foreach ($places as $place) {
                $row = $rows->get($key.'|'.$place);
                $pages[$place] = [
                    'entries' => self::weeks($row?->apprentice_entries),
                    'observations' => self::weeks($row?->observations),
                    'apprentice' => self::state($row, BookletLink::APPRENTICE, $links->get($key.'|'.BookletLink::APPRENTICE)),
                    'tutor' => $place === BookletMonth::COMPANY ? self::state($row, BookletLink::TUTOR, $links->get($key.'|'.BookletLink::TUTOR)) : null,
                    'visa' => $row?->visa_at === null ? null : ['by' => $row->visa_by === null ? null : ($visaNames[$row->visa_by] ?? null), 'at' => $row->visa_at->setTimezone('Europe/Paris')->format('d/m/Y')],
                ];
            }
            $months[] = [
                'month' => $key,
                'label' => self::label($m),
                'places' => $places,
                'centre_minutes' => $minutes,
                'pages' => $pages,
                'links' => array_map(fn (?BookletLink $l) => $l === null ? null : [
                    'email' => $l->email,
                    'sent_at' => $l->sent_at?->setTimezone('Europe/Paris')->format('d/m/Y H:i'),
                    'expires_on' => $l->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
                    'email_status' => $l->email_status,
                    'open' => self::isOpen($l),
                ], [
                    BookletLink::APPRENTICE => $links->get($key.'|'.BookletLink::APPRENTICE),
                    BookletLink::TUTOR => $links->get($key.'|'.BookletLink::TUTOR),
                ]),
            ];
        }

        return $wholeContract ? $months : array_reverse($months);
    }

    /**
     * Parts still to sign (neither online nor on paper) of the months
     * before the current month of Paris: the apprentice's and the tutor's.
     */
    public static function pending(Candidature $candidature): int
    {
        $current = self::today()->startOfMonth()->format('Y-m');
        $count = 0;
        foreach (self::months($candidature) as $month) {
            if ($month['month'] >= $current) {
                continue;
            }
            $company = $month['pages'][BookletMonth::COMPANY];
            $count += in_array($company['apprentice']['status'], ['signed', 'paper'], true) ? 0 : 1;
            $count += in_array($company['tutor']['status'] ?? null, ['signed', 'paper'], true) ? 0 : 1;
        }

        return $count;
    }

    /**
     * The page of a month (created when missing); the month must be in the
     * contract and started.
     */
    public static function page(Candidature $candidature, string $month, string $place): BookletMonth
    {
        if (! in_array($place, BookletMonth::PLACES, true)) {
            throw new UserFacingHttpException(404, 'Page inconnue.');
        }
        $first = self::checkMonth($candidature, $month);

        return BookletMonth::query()->firstOrCreate(
            ['candidature_id' => $candidature->id, 'month' => $first->toDateString(), 'place' => $place],
        );
    }

    /**
     * The first day of $month ('Y-m') when it is a started month of the
     * contract; 409 otherwise.
     */
    public static function checkMonth(Candidature $candidature, string $month): CarbonImmutable
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        if ($contract === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new UserFacingHttpException(409, self::NOT_IN_CONTRACT);
        }
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, 'Europe/Paris');
        [$start, $end] = self::range($contract);
        if ($first === null || $first->lessThan($start) || $first->greaterThan($end)) {
            throw new UserFacingHttpException(409, self::NOT_IN_CONTRACT);
        }
        if ($first->greaterThan(self::today()->startOfMonth())) {
            throw new UserFacingHttpException(409, self::FUTURE);
        }

        return $first;
    }

    /**
     * The trainer's observations of a centre page (not once visaed).
     *
     * @param  array<string, mixed>  $observations  w1, w2, w3, w45
     *
     * @throws ValidationException
     */
    public static function saveObservations(BookletMonth $page, array $observations, User $by): void
    {
        if ($page->place !== BookletMonth::CENTRE) {
            throw ValidationException::withMessages(['observations' => 'Les observations de la page entreprise sont celles du tuteur.']);
        }
        DB::transaction(function () use ($page, $observations, $by): void {
            $page = BookletMonth::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->visa_at !== null) {
                throw new UserFacingHttpException(409, self::VISA_FIRST);
            }
            $page->forceFill(['observations' => self::cleanWeeks($observations, 'observations'), 'updated_by' => $by->id])->save();
        });
    }

    public static function visa(BookletMonth $page, User $by): void
    {
        DB::transaction(function () use ($page, $by): void {
            $page = BookletMonth::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->visa_at === null) {
                $page->forceFill(['visa_by' => $by->id, 'visa_at' => now(), 'updated_by' => $by->id])->save();
            }
        });
    }

    public static function unvisa(BookletMonth $page, User $by): void
    {
        DB::transaction(function () use ($page, $by): void {
            $page = BookletMonth::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->visa_at !== null) {
                $page->forceFill(['visa_by' => null, 'visa_at' => null, 'updated_by' => $by->id])->save();
            }
        });
    }

    /**
     * « Signé sur papier »: the apprentice's part (all the pages of the
     * month) or the tutor's (the company page) is signed by hand; its open
     * link is closed.
     */
    public static function markPaper(Candidature $candidature, string $month, string $signer, User $by): void
    {
        if (! in_array($signer, BookletLink::SIGNERS, true)) {
            throw new UserFacingHttpException(404, 'Signataire inconnu.');
        }
        $first = self::checkMonth($candidature, $month);
        $places = $signer === BookletLink::TUTOR ? [BookletMonth::COMPANY] : self::placesOf($candidature, $month);

        DB::transaction(function () use ($candidature, $first, $places, $signer, $by): void {
            Candidature::query()->whereKey($candidature->id)->lockForUpdate()->first();
            foreach ($places as $place) {
                $page = BookletMonth::query()->lockForUpdate()->firstOrCreate(['candidature_id' => $candidature->id, 'month' => $first->toDateString(), 'place' => $place]);
                if ($page->{$signer.'_signed_at'} !== null) {
                    throw new UserFacingHttpException(409, self::SIGNED);
                }
                $page->forceFill([$signer.'_paper' => true, 'updated_by' => $by->id])->save();
            }
            BookletLink::query()->where('candidature_id', $candidature->id)->where('month', $first->toDateString())
                ->where('signer', $signer)->whereNull('closed_at')->update(['closed_at' => now()]);
        });
    }

    /**
     * The places of a month of the contract (company, plus centre).
     *
     * @return list<string>
     */
    public static function placesOf(Candidature $candidature, string $month): array
    {
        foreach (self::months($candidature) as $m) {
            if ($m['month'] === $month) {
                return $m['places'];
            }
        }

        return [BookletMonth::COMPANY];
    }

    /**
     * @param  array<string, mixed>  $weeks
     * @return array<string, ?string>
     *
     * @throws ValidationException
     */
    public static function cleanWeeks(array $weeks, string $field): array
    {
        $clean = [];
        foreach (array_keys(BookletMonth::WEEKS) as $week) {
            $value = $weeks[$week] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw ValidationException::withMessages(["{$field}.{$week}" => 'Texte invalide.']);
            }
            $value = $value === null ? null : trim($value);
            if ($value !== null && mb_strlen($value) > BookletMonth::MAX_ENTRY) {
                throw ValidationException::withMessages(["{$field}.{$week}" => '2 000 caractères au plus.']);
            }
            $clean[$week] = $value === '' ? null : $value;
        }

        return $clean;
    }

    public static function isOpen(BookletLink $link): bool
    {
        return $link->closed_at === null && $link->expires_at->isFuture();
    }

    /**
     * @param  array<string, mixed>|null  $weeks
     * @return array<string, ?string>
     */
    private static function weeks(?array $weeks): array
    {
        $out = [];
        foreach (array_keys(BookletMonth::WEEKS) as $week) {
            $out[$week] = is_string($weeks[$week] ?? null) ? $weeks[$week] : null;
        }

        return $out;
    }

    /**
     * @return array{status: string, at: ?string, name: ?string, drawing: ?string}
     */
    private static function state(?BookletMonth $row, string $signer, ?BookletLink $link): array
    {
        if ($row !== null && $row->{$signer.'_paper'}) {
            return ['status' => 'paper', 'at' => null, 'name' => null, 'drawing' => null];
        }
        $at = $row?->{$signer.'_signed_at'};
        if ($at instanceof CarbonImmutable) {
            return ['status' => 'signed', 'at' => $at->setTimezone('Europe/Paris')->format('d/m/Y à H:i'), 'name' => $row->{$signer.'_signer_name'}, 'drawing' => $row->{$signer.'_signature_path'}];
        }

        return ['status' => $link !== null && self::isOpen($link) ? 'sent' : 'none', 'at' => null, 'name' => null, 'drawing' => null];
    }

    /**
     * @return array<string, int> minutes in centre by month 'Y-m'
     */
    private static function centreMinutes(Candidature $candidature): array
    {
        if ($candidature->dossier_id === null) {
            return [];
        }

        return Admission::booklet($candidature->dossier_id, $candidature->person_id)['months'] ?? [];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function range(AlternanceContract $contract): array
    {
        return [
            CarbonImmutable::parse($contract->starts_on->toDateString(), 'Europe/Paris')->startOfMonth(),
            CarbonImmutable::parse($contract->ends_on->toDateString(), 'Europe/Paris')->startOfMonth(),
        ];
    }
}
