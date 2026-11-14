<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\CalendarAnnouncements;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * An affaire of the pipeline, held by an entreprise or a particulier.
 * Stage moves go through OpportunityStage (rules and history).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $organisation_id
 * @property string|null $person_id
 * @property string $title
 * @property string $das_id
 * @property string $stage_id
 * @property int $probability
 * @property bool $probability_overridden set when the probability was typed away from the stage default since the last move
 * @property string|null $amount numeric(12,2) as a string
 * @property string|null $weighted_amount generated: amount * probability / 100
 * @property Carbon|null $expected_close_on
 * @property string|null $priority_id
 * @property string|null $funding_mode_id
 * @property string|null $loss_reason_id
 * @property string|null $loss_comment
 * @property Carbon|null $won_on
 * @property Carbon|null $lost_on
 * @property string|null $responsible_user_id
 * @property string|null $source_system
 * @property string|null $source_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Opportunity extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * Free text that may describe the particulier: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['loss_comment'];

    /** @var list<string> */
    protected array $auditIgnore = ['weighted_amount', 'created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = [
        'organisation_id', 'person_id', 'title', 'das_id', 'stage_id', 'probability', 'probability_overridden',
        'amount', 'expected_close_on', 'priority_id', 'funding_mode_id', 'loss_reason_id', 'loss_comment',
        'won_on', 'lost_on', 'responsible_user_id', 'source_system', 'source_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'probability_overridden' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Opportunity $opportunity): void {
            $opportunity->created_by ??= Auth::id();
            $opportunity->updated_by ??= Auth::id();
        });
        static::updating(function (Opportunity $opportunity): void {
            if (Auth::id() !== null) {
                $opportunity->updated_by = Auth::id();
            }
        });

        // Calendars (Outlook) follow the opportunity of an action.
        $announce = fn (Opportunity $opportunity) => CalendarAnnouncements::announceFor('opportunity_id', $opportunity->id);
        static::deleted($announce);
        static::restored($announce);
        static::updated(fn (Opportunity $opportunity) => $opportunity->wasChanged(['das_id', 'organisation_id', 'person_id']) ? $announce($opportunity) : null);
    }

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'probability_overridden' => 'boolean',
            'amount' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
            'expected_close_on' => 'date',
            'won_on' => 'date',
            'lost_on' => 'date',
        ];
    }

    /** Particulier (person account) rather than entreprise. */
    public function isIndividual(): bool
    {
        return $this->person_id !== null;
    }

    /**
     * Days from creation to the win (Paris calendar days), null while not won.
     */
    public function cycleDays(): ?int
    {
        if ($this->won_on === null || $this->created_at === null) {
            return null;
        }

        $tz = config('app.timezone');
        $created = CarbonImmutable::instance($this->created_at)->setTimezone($tz)->startOfDay();
        $won = CarbonImmutable::parse($this->won_on->toDateString(), $tz)->startOfDay();

        // Won before it was entered in the CRM (dated afterwards): no negative cycle.
        return max(0, (int) $created->diffInDays($won));
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'opportunity_contacts', 'opportunity_id', 'person_id')->withPivot('tenant_id');
    }

    /**
     * @return HasMany<StageChange, $this>
     */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(StageChange::class);
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'stage_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function das(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'das_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'priority_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function fundingMode(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'funding_mode_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'loss_reason_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
