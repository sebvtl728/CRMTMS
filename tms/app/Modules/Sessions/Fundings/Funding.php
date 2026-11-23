<?php

namespace App\Modules\Sessions\Fundings;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Sessions\Dossiers\Dossier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A funding of a dossier: a funder (an entreprise or a person of the CRM,
 * read through Crm\Public\Fiches only), a type (reference list « Types de
 * financeur »), an amount HT and a state; it may target a line of the
 * dossier or one of its enrolments. Written by FundingWriter only, under
 * the lock of the dossier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dossier_id
 * @property string|null $funder_organisation_id
 * @property string|null $funder_person_id
 * @property string $funding_type_id
 * @property string|null $dossier_line_id
 * @property string|null $enrolment_id
 * @property string $amount numeric(12,2) HT
 * @property FundingStatus $status
 * @property string|null $agreement_number n° de prise en charge
 * @property CarbonImmutable|null $agreed_on
 * @property string|null $notes
 * @property bool $pays_directly the funder pays the organisme and receives its own invoice (0.9)
 * @property int|null $advance_percent percent of the amount billed as an advance (acompte)
 * @property CarbonImmutable|null $advance_on the day the advance is billed from
 * @property Carbon|null $created_at
 */
class Funding extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'funding';

    /**
     * Free text that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['notes'];

    /** @var list<string> */
    protected $fillable = [
        'dossier_id', 'funder_organisation_id', 'funder_person_id', 'funding_type_id', 'dossier_line_id', 'enrolment_id',
        'amount', 'status', 'agreement_number', 'agreed_on', 'notes', 'pays_directly', 'advance_percent', 'advance_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'to_request',
        'pays_directly' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => FundingStatus::class,
            'amount' => 'decimal:2',
            'agreed_on' => 'immutable_date',
            'pays_directly' => 'boolean',
            'advance_percent' => 'integer',
            'advance_on' => 'immutable_date',
        ];
    }

    /** « organisation » or « person ». */
    public function funderType(): string
    {
        return $this->funder_organisation_id !== null ? 'organisation' : 'person';
    }

    public function funderId(): string
    {
        return (string) ($this->funder_organisation_id ?? $this->funder_person_id);
    }

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class)->withTrashed();
    }
}
