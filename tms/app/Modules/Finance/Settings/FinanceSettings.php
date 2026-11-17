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
 * @property string $unjustified_absences
 */
class FinanceSettings extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'finance_settings';

    protected $table = 'finance_settings';

    /** @var list<string> */
    protected $fillable = ['unjustified_absences'];

    /** @var array<string, mixed> */
    protected $attributes = ['unjustified_absences' => BillingPlans::NOT_BILLED];

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
