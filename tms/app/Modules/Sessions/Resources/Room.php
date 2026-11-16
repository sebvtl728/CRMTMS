<?php

namespace App\Modules\Sessions\Resources;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A training room of the organisme (« Réglages » of the sessions), written
 * by Rooms only. A room in use is deactivated, never deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name ≤ 120
 * @property int|null $capacity > 0
 * @property string|null $address ≤ 500
 * @property bool $active
 * @property Carbon|null $deleted_at
 */
class Room extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'room';

    protected $table = 'rooms';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['name', 'capacity', 'address', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            $room->created_by ??= Auth::id();
            $room->updated_by ??= Auth::id();
        });
        static::updating(function (Room $room): void {
            if (Auth::id() !== null) {
                $room->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }
}
