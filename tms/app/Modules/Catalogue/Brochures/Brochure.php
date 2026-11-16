<?php

namespace App\Modules\Catalogue\Brochures;

use App\Modules\Catalogue\Training;
use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
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
 * A training brochure of the library: a title, the versions of its PDF
 * (current_version_id is the one offered) and the trainings and/or DAS it
 * concerns. Soft-deleted when put in the trash; its versions are never
 * erased (one may have been sent).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string|null $current_version_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read BrochureVersion|null $currentVersion
 */
class Brochure extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['title'];

    protected static function booted(): void
    {
        static::creating(function (Brochure $brochure): void {
            $brochure->created_by ??= Auth::id();
            $brochure->updated_by ??= Auth::id();
        });
        static::updating(function (Brochure $brochure): void {
            if (Auth::id() !== null) {
                $brochure->updated_by = Auth::id();
            }
        });
    }

    /**
     * @return HasMany<BrochureVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(BrochureVersion::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<BrochureVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BrochureVersion::class, 'current_version_id');
    }

    /**
     * @return BelongsToMany<Training, $this>
     */
    public function trainings(): BelongsToMany
    {
        return $this->belongsToMany(Training::class, 'brochure_trainings', 'brochure_id', 'training_id')->withPivot('tenant_id');
    }

    /**
     * @return BelongsToMany<ReferenceItem, $this>
     */
    public function das(): BelongsToMany
    {
        return $this->belongsToMany(ReferenceItem::class, 'brochure_das', 'brochure_id', 'das_id')->withPivot('tenant_id')->withTrashed();
    }

    /**
     * Every word in the title (accent-insensitive).
     *
     * @param  Builder<Brochure>  $query
     * @return Builder<Brochure>
     */
    public function scopeTitleMatches(Builder $query, string $term): Builder
    {
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->whereRaw(
                'public.immutable_unaccent(lower(brochures.title)) like public.immutable_unaccent(lower(?))',
                ['%'.addcslashes($word, '\\%_').'%'],
            );
        }

        return $query;
    }
}
