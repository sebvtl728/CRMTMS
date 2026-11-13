<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Export\ExportResource;
use App\Modules\Crm\Export\OrganisationExport;
use App\Modules\Crm\Export\PersonExport;
use App\Modules\Crm\Organisations\OrganisationListQuery;
use App\Modules\Crm\People\PersonListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The two listed resources of the CRM: what their saved filters may hold and
 * how their list is queried and exported.
 */
enum CrmResource: string
{
    case Organisations = 'organisations';
    case People = 'people';

    public function export(): ExportResource
    {
        return match ($this) {
            self::Organisations => new OrganisationExport,
            self::People => new PersonExport,
        };
    }

    /**
     * Filter keys a saved filter may hold.
     *
     * @return list<string>
     */
    public function savableFilters(): array
    {
        return match ($this) {
            self::Organisations => OrganisationListQuery::SAVABLE,
            self::People => PersonListQuery::SAVABLE,
        };
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function filterRules(): array
    {
        return match ($this) {
            self::Organisations => OrganisationListQuery::rules(),
            self::People => PersonListQuery::rules(),
        };
    }

    /**
     * Filters read from a query string / saved filter (same code as the list page).
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input, User $user): array
    {
        return match ($this) {
            self::Organisations => OrganisationListQuery::filters($input, $user),
            self::People => PersonListQuery::filters($input, $user),
        };
    }

    /**
     * The list query for filters read by filters().
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function query(array $filters, User $user): Builder
    {
        /** @var Builder<Model> */
        return match ($this) {
            self::Organisations => OrganisationListQuery::build($filters, $user),
            self::People => PersonListQuery::build($filters, $user),
        };
    }

    /** Label used in the audit journal (« crm.organisations »). */
    public function auditName(): string
    {
        return 'crm.'.$this->value;
    }
}
