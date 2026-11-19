<?php

namespace App\Modules\Finance\Settings;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Finance\Plans\BillingPlans;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The invoicing defaults of the organisme (Finance › Réglages): one row per
 * tenant, created at the first change; without it, the defaults below.
 *
 *  - unjustified_absences: « not_billed » (default) or « bill_contractor »
 *    (spec 0.9 § 4 bis 7), which each dossier may replace by its own.
 *
 * @property string $id
 * @property string $tenant_id
 *  - reminder_days: the reminder levels, days after the due date (1 to 3,
 *    increasing; default 7, 30, 60) and auto_reminders: the levels sent
 *    every morning by tms:finance:remind (default off) (spec 0.9.1 § 4.3).
 *
 * @property string $unjustified_absences
 * @property list<int> $reminder_days
 * @property bool $auto_reminders
 */
class FinanceSettings extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'finance_settings';

    protected $table = 'finance_settings';

    /** @var list<string> */
    protected $fillable = ['unjustified_absences', 'reminder_days', 'auto_reminders'];

    /** Default reminder levels: days after the due date. */
    public const REMINDER_DAYS = [7, 30, 60];

    /** @var array<string, mixed> */
    protected $attributes = ['unjustified_absences' => BillingPlans::NOT_BILLED];

    protected function casts(): array
    {
        return ['reminder_days' => 'array', 'auto_reminders' => 'boolean'];
    }

    /**
     * The organisme's reminder levels: days after the due date, increasing.
     *
     * @return list<int>
     */
    public static function reminderDays(): array
    {
        $days = static::query()->value('reminder_days');
        $days = is_string($days) ? json_decode($days, true) : $days;

        return is_array($days) && $days !== [] ? array_values(array_map('intval', $days)) : self::REMINDER_DAYS;
    }

    /** Whether the reminders leave on their own every morning. */
    public static function autoReminders(): bool
    {
        return (bool) (static::query()->value('auto_reminders') ?? false);
    }

    /**
     * The organisme's rule for unjustified absences.
     */
    public static function absencesDefault(): string
    {
        return static::query()->value('unjustified_absences') ?? BillingPlans::NOT_BILLED;
    }

    /**
     * Saves the organisme's rule for unjustified absences (journaled when it
     * changes); two administrators saving at once: one row, the last wins.
     */
    public static function saveAbsences(string $rule): void
    {
        DB::transaction(function () use ($rule): void {
            // One row per tenant, created without racing (unique tenant_id), then locked.
            static::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => app(TenantContext::class)->id(),
                'unjustified_absences' => BillingPlans::NOT_BILLED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $settings = static::query()->lockForUpdate()->sole();
            $settings->fill(['unjustified_absences' => $rule])->save();
        });
    }
}
