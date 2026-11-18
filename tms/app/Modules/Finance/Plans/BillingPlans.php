<?php

namespace App\Modules\Finance\Plans;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The billing plan of a dossier (spec 0.9 § 4.2, § 4 bis 4 and 7):
 *
 *  - rhythm: « end » (one invoice at the end, default), « monthly » (each
 *    closed month), « schedule » (instalments {due_on, percent}: whole
 *    percents of 1 to 100 summing to 100, distinct days, none before the
 *    start of the dossier's first session; sorted by date);
 *  - basis: « realised » (default) or « planned » (a flat price; never
 *    with « monthly », billed at the realised hours only);
 *  - unjustified_absences: « not_billed », « bill_contractor », or null for
 *    the organisme's default (FinanceSettings) — for a dossier not yet
 *    invoiced only: the lock stores the rule then applied, so a later change
 *    of the organisme's default never changes what an invoiced dossier owes;
 *  - locked once the first invoice of the dossier is validated (lock(),
 *    called by the validation): rhythm, basis and schedule no longer change
 *    (409, a credit note first); the absences rule still does, by hand on
 *    the dossier (the due amount is computed again, as after a change of
 *    attendance; « the organisme's rule » takes it as it is then).
 *
 * A dossier without a row has the default plan; the row is created at the
 * first save (or lock), one per dossier (unique), under a row lock.
 *
 * @phpstan-type Plan array{rhythm: string, basis: string, unjustified_absences: ?string, absences: string, schedule: list<array{due_on: string, percent: int}>, locked: bool}
 */
final class BillingPlans
{
    public const END = 'end';

    public const MONTHLY = 'monthly';

    public const SCHEDULE = 'schedule';

    public const RHYTHMS = [self::END, self::MONTHLY, self::SCHEDULE];

    public const REALISED = 'realised';

    public const PLANNED = 'planned';

    public const BASES = [self::REALISED, self::PLANNED];

    public const NOT_BILLED = 'not_billed';

    public const BILL_CONTRACTOR = 'bill_contractor';

    public const ABSENCES = [self::NOT_BILLED, self::BILL_CONTRACTOR];

    public const MAX_INSTALMENTS = 24;

    /** The monthly rhythm bills the closed months at the realised hours, never on the planned basis. */
    public const MONTHLY_REALISED = 'Le rythme mensuel se facture au réalisé.';

    public const LOCKED = 'Le rythme ne change plus après une facture validée : faites d\'abord un avoir.';

    /** Refusal of the guards Finance registers in Sessions (who pays directly, trash). */
    public const HAS_INVOICES = 'Ce dossier a des factures validées : faites d\'abord un avoir.';

    /**
     * The plan of the dossier, the default one when it has none
     * (« absences »: the rule applied, its own or the organisme's).
     *
     * @return Plan
     */
    public function for(string $dossierId): array
    {
        $plan = Str::isUuid($dossierId) ? BillingPlan::query()->where('dossier_id', $dossierId)->first() : null;

        return self::shape($plan, FinanceSettings::absencesDefault());
    }

    /**
     * Validates and saves the plan of the dossier (French messages).
     *
     * @param  array<string, mixed>  $data  rhythm, basis, unjustified_absences, schedule
     * @return Plan
     *
     * @throws ValidationException
     * @throws UserFacingHttpException 409 when the plan is locked and rhythm, basis or schedule change
     */
    public function save(string $dossierId, array $data): array
    {
        $dossier = Billing::dossier($dossierId);
        if ($dossier === null) {
            throw new NotFoundHttpException;
        }
        $values = $this->validated($data, self::startsOn($dossier['sessions']));

        $plan = DB::transaction(function () use ($dossierId, $values): BillingPlan {
            $plan = $this->lockedRow($dossierId);
            if ($plan->locked_at !== null
                && ($plan->rhythm !== $values['rhythm'] || $plan->basis !== $values['basis'] || $plan->schedule !== $values['schedule'])) {
                throw new UserFacingHttpException(409, self::LOCKED);
            }
            // Invoiced: the organisme's rule is taken as it is now, frozen like at the lock.
            if ($plan->locked_at !== null) {
                $values['unjustified_absences'] ??= FinanceSettings::absencesDefault();
            }
            $plan->fill([...$values, 'updated_by' => Auth::id()]);
            if ($plan->isDirty(['rhythm', 'basis', 'unjustified_absences', 'schedule'])) {
                $plan->save();
            }

            return $plan;
        });

        return self::shape($plan, FinanceSettings::absencesDefault());
    }

    /**
     * Freezes the plan of the dossier (its first invoice is validated),
     * creating the default one when it has none, with the absences rule then
     * applied (the organisme's default resolved); already locked: unchanged.
     * Call inside the validation transaction.
     */
    public function lock(string $dossierId): void
    {
        DB::transaction(function () use ($dossierId): void {
            $plan = $this->lockedRow($dossierId);
            if ($plan->locked_at === null) {
                $plan->forceFill([
                    'locked_at' => CarbonImmutable::now(),
                    'unjustified_absences' => $plan->unjustified_absences ?? FinanceSettings::absencesDefault(),
                ])->save();
            }
        });
    }

    public function isLocked(string $dossierId): bool
    {
        return Str::isUuid($dossierId)
            && BillingPlan::query()->where('dossier_id', $dossierId)->whereNotNull('locked_at')->exists();
    }

    /**
     * The row of the dossier, created with the default plan when missing
     * (without racing: unique dossier_id), locked for the transaction.
     */
    private function lockedRow(string $dossierId): BillingPlan
    {
        BillingPlan::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->id(),
            'dossier_id' => $dossierId,
            'rhythm' => self::END,
            'basis' => self::REALISED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BillingPlan::query()->where('dossier_id', $dossierId)->lockForUpdate()->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{rhythm: string, basis: string, unjustified_absences: ?string, schedule: list<array{due_on: string, percent: int}>|null}
     *
     * @throws ValidationException
     */
    private function validated(array $data, ?string $startsOn): array
    {
        $data = self::normalise($data);
        $schedule = $data['rhythm'] === self::SCHEDULE;

        $validator = Validator::make($data, [
            'rhythm' => ['required', Rule::in(self::RHYTHMS)],
            'basis' => ['required', Rule::in(self::BASES)],
            'unjustified_absences' => ['nullable', Rule::in(self::ABSENCES)],
            ...($schedule ? [
                'schedule' => ['required', 'array', 'max:'.self::MAX_INSTALMENTS],
                'schedule.*' => ['array'],
                'schedule.*.due_on' => ['required', 'date_format:Y-m-d'],
                'schedule.*.percent' => ['required', 'integer', 'between:1,100'],
            ] : []),
        ], self::messages());

        $validator->after(function ($v) use ($data, $schedule, $startsOn): void {
            if ($data['rhythm'] === self::MONTHLY && $data['basis'] === self::PLANNED && ! $v->errors()->has('basis')) {
                $v->errors()->add('basis', self::MONTHLY_REALISED);
            }
            if (! $schedule || $v->errors()->isNotEmpty()) {
                return;
            }
            $seen = [];
            foreach ($data['schedule'] as $i => $instalment) {
                $day = (string) $instalment['due_on'];
                if ($startsOn !== null && $day < $startsOn) {
                    $v->errors()->add("schedule.{$i}.due_on", 'Une échéance ne peut pas précéder le début de la formation ('.CarbonImmutable::parse($startsOn)->format('d/m/Y').').');
                } elseif (isset($seen[$day])) {
                    $v->errors()->add("schedule.{$i}.due_on", 'Deux échéances ne peuvent pas tomber le même jour.');
                }
                $seen[$day] = true;
            }
            $sum = array_sum(array_map(fn (array $i) => (int) $i['percent'], $data['schedule']));
            if ($sum !== 100) {
                $v->errors()->add('schedule', "La somme des pourcentages de l'échéancier doit faire 100 % (actuellement {$sum} %).");
            }
        });
        $validator->validate();

        $instalments = null;
        if ($schedule) {
            $instalments = array_map(fn (array $i) => ['due_on' => (string) $i['due_on'], 'percent' => (int) $i['percent']], array_values($data['schedule']));
            usort($instalments, fn (array $a, array $b) => strcmp($a['due_on'], $b['due_on']));
        }

        return [
            'rhythm' => $data['rhythm'],
            'basis' => $data['basis'],
            'unjustified_absences' => $data['unjustified_absences'],
            'schedule' => $instalments,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalise(array $data): array
    {
        foreach (['rhythm', 'basis', 'unjustified_absences'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $data[$key] = $value === '' ? null : $value;
        }
        if (is_array($data['schedule'] ?? null)) {
            $data['schedule'] = array_values(array_map(function ($instalment) {
                if (! is_array($instalment)) {
                    return $instalment;
                }
                foreach (['due_on', 'percent'] as $key) {
                    $value = $instalment[$key] ?? null;
                    $instalment[$key] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
                }

                return $instalment;
            }, $data['schedule']));
        } else {
            $data['schedule'] ??= [];
        }

        return $data;
    }

    /**
     * The first day of the dossier's sessions (an instalment never falls
     * before it), null without a session.
     *
     * @param  list<array{starts_on: string}>  $sessions
     */
    private static function startsOn(array $sessions): ?string
    {
        $days = array_column($sessions, 'starts_on');

        return $days === [] ? null : min($days);
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        $percent = 'Chaque pourcentage est un nombre entier de 1 à 100.';
        $day = 'Indiquez la date de chaque échéance.';

        return [
            'rhythm.required' => 'Choisissez le rythme de facturation : à la fin, mensuel ou échéancier.',
            'rhythm.in' => 'Choisissez le rythme de facturation : à la fin, mensuel ou échéancier.',
            'basis.required' => 'Choisissez la base de facturation : au réalisé ou au prévu.',
            'basis.in' => 'Choisissez la base de facturation : au réalisé ou au prévu.',
            'unjustified_absences.in' => 'Choisissez « Non facturées », « Facturées au contractant » ou le réglage de l\'organisme.',
            'schedule.required' => 'Indiquez au moins une échéance.',
            'schedule.array' => 'Indiquez au moins une échéance.',
            'schedule.max' => 'Un échéancier compte au plus '.self::MAX_INSTALMENTS.' échéances.',
            'schedule.*.array' => $day,
            'schedule.*.due_on.required' => $day,
            'schedule.*.due_on.date_format' => $day,
            'schedule.*.percent.required' => $percent,
            'schedule.*.percent.integer' => $percent,
            'schedule.*.percent.between' => $percent,
        ];
    }

    /**
     * @return Plan
     */
    private static function shape(?BillingPlan $plan, string $organismeAbsences): array
    {
        $own = $plan?->unjustified_absences;

        return [
            'rhythm' => $plan->rhythm ?? self::END,
            'basis' => $plan->basis ?? self::REALISED,
            'unjustified_absences' => $own,
            'absences' => $own ?? $organismeAbsences,
            'schedule' => $plan->schedule ?? [],
            'locked' => $plan?->locked_at !== null,
        ];
    }
}
