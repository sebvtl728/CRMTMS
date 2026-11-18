<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A candidature (table candidatures): a CRM person (read through
 * Crm\Public only) applying to a training. Written by CandidatureWriter, its
 * stage changed by CandidatureTransitions only. The NIR is never stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number C-<AAAA>-<NNNN>
 * @property string $person_id
 * @property CandidatureStatus $status
 * @property string|null $training_id
 * @property string|null $rncp_code
 * @property string|null $target_session_id
 * @property string $das_id
 * @property string|null $responsible_user_id
 * @property string|null $created_by
 * @property string|null $origin_id référentiel contact_origin
 * @property string|null $prescriber_organisation_id
 * @property string|null $france_travail_id
 * @property string|null $last_diploma
 * @property array<string, string|null>|null $legal_representative
 * @property bool $rqth
 * @property string|null $adaptation_needs
 * @property CarbonImmutable|null $positioning_sent_on
 * @property CarbonImmutable|null $positioning_received_on
 * @property string|null $positioning_result
 * @property Carbon|null $interview_at
 * @property string|null $interviewer_user_id
 * @property string|null $interview_report
 * @property string|null $decision_reason_id référentiel candidature_refusal_reason
 * @property string|null $dossier_id
 * @property array<string, mixed> $custom_values
 * @property Carbon|null $anonymised_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Candidature extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'candidature';

    /** The sensitive data: only with admissions.sensitive.view. */
    public const SENSITIVE = ['rqth', 'adaptation_needs', 'interview_report'];

    /**
     * Personal values: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = [
        'legal_representative', 'rqth', 'adaptation_needs', 'interview_report', 'positioning_result',
        'france_travail_id', 'last_diploma', 'custom_values',
    ];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    /** @var list<string> */
    protected $fillable = [
        'person_id', 'number', 'training_id', 'rncp_code', 'target_session_id', 'das_id', 'responsible_user_id',
        'origin_id', 'prescriber_organisation_id', 'france_travail_id', 'last_diploma', 'legal_representative', 'rqth',
        'adaptation_needs', 'positioning_sent_on', 'positioning_received_on', 'positioning_result', 'interview_at',
        'interviewer_user_id', 'interview_report', 'decision_reason_id', 'custom_values',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'identified',
        'rqth' => false,
        'custom_values' => '{}',
    ];

    protected static function booted(): void
    {
        static::creating(function (Candidature $candidature): void {
            $candidature->created_by ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => CandidatureStatus::class,
            'legal_representative' => 'array',
            'rqth' => 'boolean',
            'positioning_sent_on' => 'immutable_date',
            'positioning_received_on' => 'immutable_date',
            'interview_at' => 'datetime',
            'custom_values' => 'array',
            'anonymised_at' => 'datetime',
        ];
    }

    /** @return HasOne<AlternanceContract, $this> */
    public function contract(): HasOne
    {
        return $this->hasOne(AlternanceContract::class);
    }

    /** @return HasMany<CandidatureFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(CandidatureFile::class);
    }
}
