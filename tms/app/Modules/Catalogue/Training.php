<?php

namespace App\Modules\Catalogue;

use App\Modules\Catalogue\Brochures\BrochureStore;
use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A training of the catalogue. Other modules read it through Trainings
 * (TrainingData), never through this model or its tables.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $code stored upper-cased
 * @property string $title
 * @property string $das_id
 * @property string|null $duration_hours numeric(6,2) as a string
 * @property string|null $duration_days numeric(5,2) as a string
 * @property string|null $centre_hours numeric(6,2), hours in the centre (alternance)
 * @property string|null $company_hours numeric(6,2), hours in the company (alternance, placements)
 * @property string|null $audience
 * @property string|null $prerequisites
 * @property string|null $price_company numeric(12,2) as a string, HT per trainee
 * @property string|null $price_individual numeric(12,2) as a string, HT per trainee
 * @property string|null $description
 * @property list<string>|null $objectives ordered texts
 * @property string|null $methods
 * @property string|null $evaluation
 * @property string|null $sanction
 * @property string|null $accessibility
 * @property string|null $access_terms
 * @property string|null $rncp_code « RNCP » followed by digits, uppercase
 * @property string|null $results
 * @property BpfObjective|null $bpf_objective BPF cadre F-3
 * @property list<array{title: string, competences: list<array{title: string, skills: list<string>}>}>|null $competences grid (Competences)
 * @property string|null $nsf_code NSF group of specialities (Nsf::CODES), BPF cadre F-4
 * @property bool $is_active
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $source_system software an import took it from
 * @property string|null $source_id its identifier there
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Training extends Model
{
    /** Hours of a training day: converts a duration in hours to days and back (form, assistant). */
    public const HOURS_PER_DAY = 7;

    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /**
     * The journal records that the competence grid changed, never its (long) content.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['competences'];

    /** @var list<string> */
    protected $fillable = [
        'code', 'title', 'das_id', 'duration_hours', 'duration_days', 'centre_hours', 'company_hours', 'audience', 'prerequisites',
        'price_company', 'price_individual', 'is_active',
        'description', 'objectives', 'methods', 'evaluation', 'sanction', 'accessibility', 'access_terms', 'results', 'rncp_code', 'bpf_objective', 'nsf_code', 'competences',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (Training $training): void {
            $code = $training->code === null ? '' : mb_strtoupper(trim($training->code));
            $training->code = $code === '' ? null : $code;
        });
        static::creating(function (Training $training): void {
            $training->created_by ??= Auth::id();
            $training->updated_by ??= Auth::id();
        });
        // A deleted training no longer carries brochures (they stay in the library).
        static::deleted(fn (Training $training) => BrochureStore::detachTraining($training->id));
        static::updating(function (Training $training): void {
            if (Auth::id() !== null) {
                $training->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'duration_hours' => 'decimal:2',
            'duration_days' => 'decimal:2',
            'centre_hours' => 'decimal:2',
            'company_hours' => 'decimal:2',
            'price_company' => 'decimal:2',
            'price_individual' => 'decimal:2',
            'is_active' => 'boolean',
            'objectives' => 'array',
            'bpf_objective' => BpfObjective::class,
            'competences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function das(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'das_id')->withTrashed();
    }

    /**
     * @return BelongsToMany<ReferenceItem, $this>
     */
    public function fundingModes(): BelongsToMany
    {
        return $this->belongsToMany(ReferenceItem::class, 'training_funding_modes', 'training_id', 'funding_mode_id')
            ->withPivot('tenant_id')->withTrashed();
    }

    /**
     * @param  list<string>  $ids
     */
    public function syncFundingModes(array $ids): void
    {
        $this->fundingModes()->sync(array_fill_keys($ids, ['tenant_id' => $this->tenant_id]));
    }

    /**
     * @return HasMany<TrainingModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(TrainingModule::class)->orderBy('position');
    }
}
