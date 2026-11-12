<?php

namespace App\Modules\Core\References;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One value of a tenant-configurable list.
 *
 * `code` is a slug derived from the label at creation and NEVER changes
 * afterwards (other modules may store it); the label can be renamed freely.
 * Deactivating keeps the item and its label for history; `is_system` items
 * (e.g. the won/lost pipeline stages) can be neither deactivated nor deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ReferenceList $list
 * @property string $code
 * @property string $label
 * @property int $position
 * @property bool $is_system
 * @property bool $is_active
 * @property array{probability?: int, outcome?: string|null, color?: string|null}|null $meta
 * @property Carbon|null $deleted_at
 */
class ReferenceItem extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['list', 'code', 'label', 'position', 'is_system', 'is_active', 'meta'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_system' => false,
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'list' => ReferenceList::class,
            'position' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * Active items of a list in display order.
     *
     * @return Collection<int, static>
     */
    public static function options(ReferenceList $list): Collection
    {
        return static::query()
            ->where('list', $list)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('label')
            ->get();
    }

    /**
     * A free slug for $label in $list. Soft-deleted items keep their code
     * reserved, so a code never designates two different values over time.
     */
    public static function uniqueCode(ReferenceList $list, string $label): string
    {
        $base = Str::slug($label) ?: 'valeur';
        $code = $base;

        for ($n = 2; static::withTrashed()->where('list', $list)->where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }

    /**
     * Referenced by a business record (see ReferenceUsage).
     */
    public function isInUse(): bool
    {
        return ReferenceUsage::isInUse($this);
    }
}
