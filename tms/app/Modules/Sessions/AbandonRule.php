<?php

namespace App\Modules\Sessions;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;

/**
 * « Règle en cas d'abandon » of an organisme (spec lot 2a § 5.6), stored in
 * tenants.settings['sessions']['abandon_rule']:
 *
 *  - prorata (default): the trainee who leaves is billed the hours attended;
 *  - prorata_dedit: the same, plus a dédit of dedit_percent % (1 to 100).
 *
 * A force majeure abandon reason never costs a dédit (reference item flag
 * meta.force_majeure): the callers apply that, this class only states the rule.
 *
 * forTenant() is the organisme's rule. forDossier(), which will return the
 * dossier's own override (dossiers.abandon_mode / abandon_dedit_percent) and
 * otherwise this rule, is added with the Dossier model (lot 2a, task 4).
 * 2a records and shows the rule; the hours attended (2d) and the invoices
 * (lot 3) will apply it.
 */
final class AbandonRule
{
    public const PRORATA = 'prorata';

    public const PRORATA_DEDIT = 'prorata_dedit';

    public const MODES = [self::PRORATA, self::PRORATA_DEDIT];

    public const MIN_PERCENT = 1;

    public const MAX_PERCENT = 100;

    /**
     * @return array{mode: 'prorata'|'prorata_dedit', dedit_percent: ?int}
     */
    public static function forTenant(?Tenant $tenant = null): array
    {
        $tenant ??= app(TenantContext::class)->current();

        return self::normalise(data_get($tenant->settings ?? [], 'sessions.abandon_rule'));
    }

    /**
     * Any stored value as a valid rule (prorata when absent or incoherent).
     *
     * @return array{mode: 'prorata'|'prorata_dedit', dedit_percent: ?int}
     */
    public static function normalise(mixed $stored): array
    {
        $percent = is_array($stored) ? ($stored['dedit_percent'] ?? null) : null;

        if (is_array($stored) && ($stored['mode'] ?? null) === self::PRORATA_DEDIT
            && is_int($percent) && $percent >= self::MIN_PERCENT && $percent <= self::MAX_PERCENT) {
            return ['mode' => self::PRORATA_DEDIT, 'dedit_percent' => $percent];
        }

        return ['mode' => self::PRORATA, 'dedit_percent' => null];
    }
}
