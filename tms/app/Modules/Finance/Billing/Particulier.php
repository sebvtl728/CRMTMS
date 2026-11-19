<?php

namespace App\Modules\Finance\Billing;

use Carbon\CarbonImmutable;

/**
 * Protection of the particulier who pays his own training (spec 0.9.1
 * § 4.4; Code du travail L6353-5 and L6353-6, to be confirmed by the
 * accountant): nothing may be asked during the withdrawal delay following
 * the signature of the contract, and no more than 30 % of the price before
 * the training's payments are spread over its course.
 */
final class Particulier
{
    public const WITHDRAWAL_DAYS = 14;

    public const MAX_ADVANCE_PERCENT = 30;

    public const NO_SIGNATURE = 'Date de signature du contrat inconnue : vérifiez le délai de rétractation du particulier.';

    /**
     * Whether the contractant of the dossier (Billing::dossier) is a particulier.
     *
     * @param  array<string, mixed>  $dossier
     */
    public static function is(array $dossier): bool
    {
        return ($dossier['contractor']['type'] ?? null) === 'person';
    }

    /**
     * The first day a particulier may be invoiced: the signature of the
     * contract + 14 days; null when the contractant is not a particulier or
     * the signature date is unknown.
     *
     * @param  array<string, mixed>  $dossier
     */
    public static function firstPayableDay(array $dossier): ?string
    {
        $signed = $dossier['convention_signed_on'] ?? null;
        if (! self::is($dossier) || ! is_string($signed) || $signed === '') {
            return null;
        }

        return CarbonImmutable::parse($signed, 'Europe/Paris')->addDays(self::WITHDRAWAL_DAYS)->toDateString();
    }

    /** « 24/11/2026 ». */
    public static function french(string $day): string
    {
        return CarbonImmutable::parse($day)->format('d/m/Y');
    }
}
