<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The automation settings of the organisme, in tenants.settings['automation']
 * (2026-11-22), read again at each call (the tenant of the context is kept in
 * memory for a whole request or job).
 */
final class AutomationSettings
{
    public const DEFAULTS = [
        'convocation_days' => 7,
        'day_before' => true,
        'signature_reminder_days' => 3,
        'signature_reminder_max' => 2,
    ];

    public const MAX_DAYS = 30;

    public const MAX_REMINDERS = 5;

    public static function convocationDays(): int
    {
        return self::int('convocation_days', self::MAX_DAYS);
    }

    public static function dayBefore(): bool
    {
        $value = data_get(self::settings(), 'automation.day_before');

        return is_bool($value) ? $value : self::DEFAULTS['day_before'];
    }

    public static function signatureReminderDays(): int
    {
        return self::int('signature_reminder_days', self::MAX_DAYS);
    }

    public static function signatureReminderMax(): int
    {
        return self::int('signature_reminder_max', self::MAX_REMINDERS);
    }

    /** @return array{convocation_days: int, day_before: bool, signature_reminder_days: int, signature_reminder_max: int} */
    public static function all(): array
    {
        return [
            'convocation_days' => self::convocationDays(),
            'day_before' => self::dayBefore(),
            'signature_reminder_days' => self::signatureReminderDays(),
            'signature_reminder_max' => self::signatureReminderMax(),
        ];
    }

    /**
     * Merges the given keys into the settings of the organisme.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public static function save(array $values): void
    {
        $data = Validator::make($values, [
            'convocation_days' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_DAYS],
            'day_before' => ['sometimes', 'boolean'],
            'signature_reminder_days' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_DAYS],
            'signature_reminder_max' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_REMINDERS],
        ], [
            '*.integer' => 'Indiquez un nombre entier.',
            '*.min' => 'Indiquez un nombre positif.',
            'convocation_days.max' => self::MAX_DAYS.' jours au plus.',
            'signature_reminder_days.max' => self::MAX_DAYS.' jours au plus.',
            'signature_reminder_max.min' => 'Au moins une relance.',
            'signature_reminder_max.max' => self::MAX_REMINDERS.' relances au plus.',
            '*.boolean' => 'Valeur invalide.',
        ])->validate();
        $tenant = Tenant::query()->findOrFail(app(TenantContext::class)->id());
        $settings = $tenant->settings ?? [];
        foreach ($data as $key => $value) {
            $settings['automation'][$key] = is_bool(self::DEFAULTS[$key]) ? (bool) $value : (int) $value;
        }
        $tenant->forceFill(['settings' => $settings])->save();
    }

    private static function int(string $key, int $max): int
    {
        $value = data_get(self::settings(), 'automation.'.$key);
        $min = $key === 'signature_reminder_max' ? 1 : 0;

        return is_int($value) && $value >= $min && $value <= $max ? $value : self::DEFAULTS[$key];
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        return Tenant::query()->whereKey(app(TenantContext::class)->id())->first()->settings ?? [];
    }
}
