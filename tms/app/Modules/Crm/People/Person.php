<?php

namespace App\Modules\Crm\People;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Organisations\OrganisationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A natural person: a « Particulier » (is_individual) and / or an
 * « Interlocuteur » (at least one active affiliation to an entreprise).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $civility
 * @property string|null $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $email_secondary
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string $country
 * @property Carbon|null $birth_date
 * @property bool $is_individual
 * @property string|null $project
 * @property string|null $professional_situation_id
 * @property string|null $funding_mode_id
 * @property string|null $origin_id
 * @property OrganisationStatus $status
 * @property string|null $responsible_user_id
 * @property Carbon|null $last_contact_at
 * @property string|null $source_system
 * @property string|null $source_id
 * @property Carbon|null $anonymised_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $deleted_at
 */
class Person extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'people';

    /**
     * Personal data: the journal keeps the field name, never the value.
     *
     * @var list<string>
     */
    public const PERSONAL_FIELDS = [
        'first_name', 'last_name', 'email', 'email_secondary', 'phone', 'mobile',
        'address_line1', 'address_line2', 'postal_code', 'city', 'birth_date', 'project',
    ];

    /** @var list<string> */
    protected array $auditPersonal = self::PERSONAL_FIELDS;

    /**
     * Generated column and bookkeeping: not worth a journal line.
     *
     * @var list<string>
     */
    protected array $auditIgnore = ['search_vector', 'created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = [
        'civility', 'first_name', 'last_name', 'email', 'email_secondary', 'phone', 'mobile',
        'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'birth_date', 'is_individual', 'project',
        'professional_situation_id', 'funding_mode_id', 'origin_id', 'status', 'responsible_user_id',
        'last_contact_at', 'source_system', 'source_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'prospect',
        'country' => 'FR',
        'is_individual' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Person $person): void {
            $person->created_by ??= Auth::id();
            $person->updated_by ??= Auth::id();
        });
        static::updating(function (Person $person): void {
            if (Auth::id() !== null) {
                $person->updated_by = Auth::id();
            }
        });

        // Whatever the entry point (screen, import, merge), phones are stored normalised.
        static::saving(function (Person $person): void {
            foreach (['phone', 'mobile'] as $column) {
                if ($person->isDirty($column) && is_string($person->getAttribute($column))) {
                    $person->setAttribute($column, PhoneNumber::normalise($person->getAttribute($column)));
                }
            }
        });

        // The affiliations follow the person into the trash and back.
        static::deleted(function (Person $person): void {
            if (! $person->isForceDeleting()) {
                Affiliation::cascadeDelete('person_id', $person);
            }
        });
        static::restoring(fn (Person $person) => Affiliation::cascadeRestore('person_id', $person));
    }

    protected function casts(): array
    {
        return [
            'status' => OrganisationStatus::class,
            'birth_date' => 'date',
            'is_individual' => 'boolean',
            'last_contact_at' => 'datetime',
            'anonymised_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.$this->last_name);
    }

    /**
     * @return HasMany<Affiliation, $this>
     */
    public function affiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    /**
     * @return HasMany<Affiliation, $this>
     */
    public function activeAffiliations(): HasMany
    {
        return $this->affiliations()->whereNull('ended_on');
    }

    /**
     * @return BelongsToMany<ReferenceItem, $this>
     */
    public function das(): BelongsToMany
    {
        return $this->belongsToMany(ReferenceItem::class, 'person_das', 'person_id', 'das_id')->withPivot('tenant_id')->orderBy('reference_items.position');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function professionalSituation(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'professional_situation_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function fundingMode(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'funding_mode_id');
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
     * Accent- and case-insensitive match: every word of $term must appear in
     * the name, e-mails or phones (trigram index on search_vector), or in the
     * work e-mail / phone of an affiliation. A term that looks like a phone
     * number is compared on its digits, whatever the spacing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch($query, string $term)
    {
        $term = trim($term);

        if (preg_match('/^\+?[\d\s.\-()]{6,}$/', $term) === 1) {
            $digits = preg_replace('/\D/', '', $term) ?? '';
            // « +33 6 12 » and « 06 12 » designate the same start of number.
            $local = str_starts_with($digits, '33') && str_starts_with($term, '+') ? '0'.substr($digits, 2) : $digits;
            $like = '%'.addcslashes($local, '\\%_').'%';

            return $query->where(fn ($q) => $q
                ->whereRaw("regexp_replace(people.search_vector, '\\D', '', 'g') like ?", [$like])
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('affiliations')
                    ->whereColumn('affiliations.person_id', 'people.id')->whereNull('affiliations.deleted_at')
                    ->whereRaw("regexp_replace(regexp_replace(coalesce(affiliations.work_phone, ''), '^\\+33', '0'), '\\D', '', 'g') like ?", [$like])));
        }

        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn ($q) => $q
                ->whereRaw('people.search_vector like public.immutable_unaccent(lower(?))', [$pattern])
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('affiliations')
                    ->whereColumn('affiliations.person_id', 'people.id')->whereNull('affiliations.deleted_at')
                    ->whereRaw("public.immutable_unaccent(lower(coalesce(affiliations.work_email, '') || ' ' || coalesce(affiliations.work_phone, ''))) like public.immutable_unaccent(lower(?))", [$pattern])));
        }

        return $query;
    }
}
