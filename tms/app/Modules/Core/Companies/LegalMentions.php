<?php

namespace App\Modules\Core\Companies;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use NumberFormatter;

/**
 * Legal footer line of quotes and invoices, e.g.
 * « CAP FORMATION – SCOP SARL à capital variable – RCS Lyon 732 829 320 – SIRET 732 829 320 00074 ».
 *
 * Segments whose data is missing are omitted (placeholder companies have no
 * SIRET yet). Numbers use U+00A0 as group separator (ICU emits U+202F, which
 * many fonts and PDF engines render badly): they never wrap.
 */
final class LegalMentions
{
    private const NBSP = "\u{00A0}";

    public static function for(Company $c): string
    {
        $segments = [$c->name, self::form($c)];

        $siret = preg_match('/^\d{14}\z/', (string) $c->siret) === 1 ? (string) $c->siret : null;
        if ($siret !== null) {
            $siren = self::groups(substr($siret, 0, 9));
            if ($c->rcs_city !== null && trim($c->rcs_city) !== '') {
                $segments[] = 'RCS '.trim($c->rcs_city).' '.$siren;
            }
            $segments[] = 'SIRET '.$siren.self::NBSP.substr($siret, 9);
        }

        return implode(' – ', $segments);
    }

    /** First day of the CIBS wording (recodification of the CGI, 1 January 2027). */
    public const CIBS_FROM = '2027-01-01';

    /**
     * The VAT exemption notice of a document dated $on (Paris day; today by
     * default) for continuing vocational training: art. 261, 4-4° a of the
     * CGI until 31 December 2026, art. L. 213-93 of the CIBS from 1 January
     * 2027 (wording given by the accountant; to check against the 2027
     * finance law).
     */
    public static function vatExemptionNotice(?CarbonInterface $on = null): string
    {
        $day = ($on ?? CarbonImmutable::now('Europe/Paris'))->toDateString();

        return $day < self::CIBS_FROM
            ? 'Exonération de TVA, art. 261, 4-4° a du CGI'
            : 'Exonération de TVA, art. L. 213-93 du CIBS';
    }

    /** Whether a printed VAT mention is an exemption notice (any wording, former ones too). */
    public static function isVatExemption(?string $mention): bool
    {
        return $mention !== null && (str_contains($mention, 'Exonération de TVA') || str_contains($mention, 'TVA non applicable'));
    }

    private static function form(Company $c): string
    {
        $form = $c->legal_form->label();
        if ($c->legal_form->isCooperative() && $c->scop_underlying_form !== null) {
            $form .= ' '.mb_strtoupper($c->scop_underlying_form);
        }

        $amount = $c->capital_amount !== null ? self::euros((float) $c->capital_amount) : null;

        return match (true) {
            $c->capital_variable && $amount !== null => "{$form} à capital variable de {$amount}",
            $c->capital_variable => "{$form} à capital variable",
            $amount !== null => "{$form} au capital de {$amount}",
            default => $form,
        };
    }

    /**
     * 3-digit groups, e.g. 732829320 -> "732 829 320" (U+00A0).
     */
    private static function groups(string $digits): string
    {
        return implode(self::NBSP, str_split($digits, 3));
    }

    public static function euros(float $amount): string
    {
        $formatted = (new NumberFormatter('fr_FR', NumberFormatter::CURRENCY))->formatCurrency($amount, 'EUR');

        // ICU: U+202F between thousands, U+00A0 before the symbol -> U+00A0 everywhere.
        return str_replace("\u{202F}", self::NBSP, (string) $formatted);
    }
}
