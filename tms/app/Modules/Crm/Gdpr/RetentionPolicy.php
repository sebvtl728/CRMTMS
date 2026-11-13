<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How long the data of a person without contractual relation is kept after
 * the last contact (tenant setting gdpr.retention_years), and who is past it.
 */
final class RetentionPolicy
{
    public const DEFAULT_YEARS = 3;

    public const MIN_YEARS = 1;

    public const MAX_YEARS = 10;

    public static function for(?Tenant $tenant): int
    {
        $years = data_get($tenant->settings ?? [], 'gdpr.retention_years');

        return is_int($years) && $years >= self::MIN_YEARS && $years <= self::MAX_YEARS ? $years : self::DEFAULT_YEARS;
    }

    /**
     * People of the current organisme past the retention period: last
     * contact (or creation) older than N years, no contractual relation (not
     * a client themselves, no active link to a client entreprise), not
     * anonymised, not merged into another fiche, not kept on purpose (until
     * the end of the « Conserver » period). Trashed fiches count: their data
     * is still held.
     *
     * @return Builder<Person>
     */
    public static function expiredPeople(): Builder
    {
        $years = self::for(app(TenantContext::class)->current());

        return Person::withTrashed()
            ->whereNull('people.anonymised_at')
            ->whereNull('people.merged_into_id')
            ->where('people.status', '!=', OrganisationStatus::Client->value)
            ->whereRaw('coalesce(people.last_contact_at, people.created_at) < ?', [now()->subYears($years)])
            ->where(fn ($q) => $q->whereNull('people.gdpr_kept_until')->orWhere('people.gdpr_kept_until', '<=', now()))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('affiliations')
                ->join('organisations', 'organisations.id', '=', 'affiliations.organisation_id')
                ->whereColumn('affiliations.person_id', 'people.id')
                ->whereNull('affiliations.ended_on')->whereNull('affiliations.deleted_at')
                ->where('organisations.status', OrganisationStatus::Client->value)->whereNull('organisations.deleted_at'));
    }
}
