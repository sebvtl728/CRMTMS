<?php

namespace App\Modules\Admissions\Alternance;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The alternance contract of a candidature (one at most): apprenticeship or
 * professionalisation, the employer, the tutor (maître d'apprentissage) and
 * the OPCO, all CRM fiches read through Crm\Public.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property ContractType $contract_type
 * @property string $employer_organisation_id
 * @property string|null $tutor_person_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $opco_organisation_id
 */
class AlternanceContract extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'alternance_contract';

    /** @var list<string> */
    protected $fillable = ['candidature_id', 'contract_type', 'employer_organisation_id', 'tutor_person_id', 'starts_on', 'ends_on', 'opco_organisation_id'];

    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Candidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }
}
