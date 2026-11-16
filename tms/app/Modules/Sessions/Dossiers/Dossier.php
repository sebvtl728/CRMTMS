<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Fundings\Funding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A dossier de formation (table dossiers): written by DossierWriter (number,
 * lines), its state changed by DossierTransitions only. The contractant is an
 * entreprise or a particulier of the CRM (ids read through Crm\Public\Fiches
 * only), the issuer a company of the organisme (Core).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number D-<AAAA>-<NNNN>, unique in the organisme
 * @property string $company_id
 * @property string|null $account_organisation_id
 * @property string|null $account_person_id
 * @property string|null $contact_person_id
 * @property string|null $responsible_user_id
 * @property string|null $quote_id
 * @property string|null $opportunity_id
 * @property string $das_id principal DAS (« Ses DAS » visibility)
 * @property DossierStatus $status stored state (see effectiveStatus())
 * @property CarbonImmutable|null $convention_sent_on
 * @property CarbonImmutable|null $convention_signed_on
 * @property CarbonImmutable|null $convocations_sent_on
 * @property string|null $cancelled_reason_id
 * @property CarbonImmutable|null $cancelled_on
 * @property string|null $abandon_mode override of the organisme's rule (AbandonRule)
 * @property int|null $abandon_dedit_percent
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Dossier extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'dossier';

    /**
     * Free text that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['notes'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'account_organisation_id', 'account_person_id', 'contact_person_id', 'responsible_user_id',
        'das_id', 'abandon_mode', 'abandon_dedit_percent', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'created',
    ];

    protected static function booted(): void
    {
        static::creating(function (Dossier $dossier): void {
            $dossier->created_by ??= Auth::id();
            $dossier->updated_by ??= Auth::id();
        });
        static::updating(function (Dossier $dossier): void {
            if (Auth::id() !== null) {
                $dossier->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DossierStatus::class,
            'convention_sent_on' => 'immutable_date',
            'convention_signed_on' => 'immutable_date',
            'convocations_sent_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
            'abandon_dedit_percent' => 'integer',
        ];
    }

    /**
     * Price of the dossier: Σ of its lines HT, in cents (the lines loaded,
     * or read once).
     */
    public function priceCents(): int
    {
        return Amounts::sum($this->lines->map(fn (DossierLine $l) => (string) $l->total_ht));
    }

    /**
     * The state on $today (a Paris day): « En cours » / « Réalisé » from the
     * sessions of its enrolments while a step before them is stored.
     *
     * @param  array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}|null  $span  already read for a page (DossierSchedule::spans())
     */
    public function effectiveStatus(CarbonImmutable $today, ?array $span = null): DossierStatus
    {
        $span ??= DossierSchedule::spans([$this->id])[$this->id] ?? null;

        return $this->status->effective($span['starts_on'] ?? null, $span['ends_on'] ?? null, $today);
    }

    public function isCancelled(): bool
    {
        return $this->status === DossierStatus::Cancelled;
    }

    /** « organisation » or « person ». */
    public function accountType(): string
    {
        return $this->account_organisation_id !== null ? 'organisation' : 'person';
    }

    public function accountId(): string
    {
        return (string) ($this->account_organisation_id ?? $this->account_person_id);
    }

    /**
     * Today in Paris, as a date.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/Paris')->startOfDay();
    }

    /**
     * @return HasMany<DossierLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DossierLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<Funding, $this>
     */
    public function fundings(): HasMany
    {
        return $this->hasMany(Funding::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
