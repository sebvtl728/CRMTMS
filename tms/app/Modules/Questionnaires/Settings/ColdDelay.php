<?php

namespace App\Modules\Questionnaires\Settings;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Delay of the cold questionnaire after the end of a session, in
 * tenants.settings['questionnaires']['cold_months']: 3 by default, 0 sends
 * none, at most 24. Read again at each call (the tenant of the context is
 * kept in memory for a whole request or job).
 */
final class ColdDelay
{
    public const DEFAULT = 3;

    public const MAX = 24;

    public static function months(): int
    {
        $settings = Tenant::query()->whereKey(app(TenantContext::class)->id())->first()->settings ?? [];
        $value = data_get($settings, 'questionnaires.cold_months');

        return is_int($value) && $value >= 0 && $value <= self::MAX ? $value : self::DEFAULT;
    }

    /** @return array{0: int, 1: int} the old and new delay */
    public static function set(int $months): array
    {
        return DB::transaction(function () use ($months): array {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail(app(TenantContext::class)->id());
            $old = self::months();
            $settings = $tenant->settings ?? [];
            $settings['questionnaires']['cold_months'] = $months;
            $tenant->forceFill(['settings' => $settings])->save();

            return [$old, $months];
        });
    }
}
