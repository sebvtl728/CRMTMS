<?php

namespace App\Modules\Crm\Organisations;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A client or prospect company (« Entreprise » in the interface).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $siret 14 digits
 * @property string|null $naf_code
 * @property string|null $legal_form_id
 * @property string|null $sector_id
 * @property string|null $establishment_type_id
 * @property string|null $company_size_id
 * @property string|null $origin_id
 * @property OrganisationStatus $status
 * @property string|null $responsible_user_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string $country
 * @property string|null $notes_summary
 * @property Carbon|null $last_contact_at
 * @property string|null $source_system
 * @property string|null $source_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $merged_into_id the fiche this one was merged into (then trashed for good)
 * @property list<array{id: string, source_id: string|null, source_system: string|null}> $merged_sources fiches merged into this one
 * @property Carbon|null $deleted_at
 */
class Organisation extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * Generated column and bookkeeping (last_contact_at follows the notes): not worth a journal line.
     *
     * @var list<string>
     */
    protected array $auditIgnore = ['search_vector', 'created_by', 'updated_by', 'last_contact_at', 'merged_sources'];

    /** @var list<string> */
    protected $fillable = [
        'name', 'siret', 'naf_code', 'legal_form_id', 'sector_id', 'establishment_type_id', 'company_size_id', 'origin_id',
        'status', 'responsible_user_id', 'email', 'phone', 'website',
        'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'notes_summary',
        'last_contact_at', 'source_system', 'source_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'prospect',
        'country' => 'FR',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organisation $organisation): void {
            $organisation->created_by ??= Auth::id();
            $organisation->updated_by ??= Auth::id();
        });
        static::updating(function (Organisation $organisation): void {
            if (Auth::id() !== null) {
                $organisation->updated_by = Auth::id();
            }
        });

        // People are never deleted with their entreprise: only the links go
        // to the trash (and come back with a restore).
        static::deleted(function (Organisation $organisation): void {
            if (! $organisation->isForceDeleting()) {
                Affiliation::cascadeDelete('organisation_id', $organisation);
            }
        });
        // A merged fiche stays in the trash: its content now lives in the kept one.
        static::restoring(fn (Organisation $organisation) => $organisation->merged_into_id === null ? null : false);
        static::restoring(fn (Organisation $organisation) => Affiliation::cascadeRestore('organisation_id', $organisation));
    }

    protected function casts(): array
    {
        return [
            'status' => OrganisationStatus::class,
            'last_contact_at' => 'datetime',
            'merged_sources' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<ReferenceItem, $this>
     */
    public function das(): BelongsToMany
    {
        return $this->belongsToMany(ReferenceItem::class, 'organisation_das', 'organisation_id', 'das_id')->withPivot('tenant_id')->orderBy('reference_items.position');
    }

    /**
     * @return HasMany<Affiliation, $this>
     */
    public function affiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function legalForm(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'legal_form_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'sector_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function establishmentType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'establishment_type_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function companySize(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'company_size_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'origin_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Accent- and case-insensitive match on name, city and SIRET: every word
     * of $term must appear (trigram index on search_vector).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch($query, string $term)
    {
        foreach (preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $escaped = addcslashes($word, '\\%_');
            $query->whereRaw("organisations.search_vector like '%' || public.immutable_unaccent(lower(?)) || '%'", [$escaped]);
        }

        return $query;
    }
}
