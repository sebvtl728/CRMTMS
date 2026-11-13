<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A named combination of list filters (and chosen columns). Private to its
 * author, or shared with every user of the organisme (still editable by the
 * author only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $resource
 * @property string $name
 * @property array<string, mixed> $filters
 * @property list<string>|null $columns
 * @property bool $is_shared
 * @property Carbon $created_at
 */
class SavedFilter extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MAX_PER_USER = 50;

    /** Search terms may be names: the journal keeps the fact, not the values. */
    /** @var list<string> */
    protected array $auditPersonal = ['filters'];

    /** @var list<string> */
    protected $fillable = ['resource', 'name', 'filters', 'columns', 'is_shared'];

    protected static function booted(): void
    {
        static::creating(function (SavedFilter $filter): void {
            $filter->user_id ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'is_shared' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Own filters and the ones shared by the other users of the organisme.
     *
     * @param  Builder<SavedFilter>  $query
     * @return Builder<SavedFilter>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('user_id', $user->getKey())->orWhere('is_shared', true));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $viewer): array
    {
        return [
            'id' => $this->id,
            'resource' => $this->resource,
            'name' => $this->name,
            'filters' => $this->filters === [] ? new \stdClass : $this->filters,
            'columns' => $this->columns,
            'is_shared' => $this->is_shared,
            'mine' => $this->user_id === $viewer->getKey(),
            'author' => $this->author?->name,
        ];
    }
}
