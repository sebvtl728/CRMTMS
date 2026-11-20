<?php

namespace App\Modules\Sessions\Settings;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;

/**
 * Delay of the trainer's follow-up call after a training (lot 1e), in
 * tenants.settings['sessions']['trainer_follow_up_months']: 3 by default,
 * 0 disables it, at most 36.
 */
final class TrainerFollowUp
{
    public const DEFAULT = 3;

    public const MAX = 36;

    public static function months(?Tenant $tenant = null): int
    {
        // Read again: the tenant of the context is kept in memory for the whole request or job.
        $settings = Tenant::query()->whereKey($tenant->id ?? app(TenantContext::class)->id())->first()->settings ?? [];
        $value = data_get($settings, 'sessions.trainer_follow_up_months');

        return is_int($value) && $value >= 0 && $value <= self::MAX ? $value : self::DEFAULT;
    }
}
