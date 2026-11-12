<?php

namespace App\Modules\Core\Security;

use App\Modules\Core\Tenancy\Tenant;

/**
 * Security settings of an organisme, stored in tenants.settings['security'].
 */
final class SecuritySettings
{
    public const MIN_IDLE_MINUTES = 15;

    public const MAX_IDLE_MINUTES = 480;

    public const DEFAULT_IDLE_MINUTES = 120;

    /**
     * @return array{force_2fa: bool, idle_timeout_minutes: int}
     */
    public static function for(?Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];
        $idle = data_get($settings, 'security.idle_timeout_minutes');

        return [
            'force_2fa' => data_get($settings, 'security.force_2fa') === true,
            'idle_timeout_minutes' => is_int($idle) && $idle >= self::MIN_IDLE_MINUTES && $idle <= self::MAX_IDLE_MINUTES
                ? $idle
                : self::DEFAULT_IDLE_MINUTES,
        ];
    }
}
