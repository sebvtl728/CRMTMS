<?php

namespace App\Modules\Catalogue\Brochures;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One uploaded PDF of a brochure. Written once, never changed nor erased:
 * replacing the file of a brochure adds a version (a sent one must stay
 * readable). The stored name is generated; the original name is for display.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $brochure_id
 * @property string $path path on the private disk (TenantStorage, zone brochures)
 * @property string $original_name
 * @property int $size bytes
 * @property string $sha256
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 */
class BrochureVersion extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /**
     * The original name may carry a person's name: journaled without value (the path holds it no more, but is no use in the journal).
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['original_name', 'path'];

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['path', 'original_name', 'size', 'sha256', 'uploaded_by'];

    protected static function booted(): void
    {
        static::creating(function (BrochureVersion $version): void {
            $version->uploaded_by ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Brochure, $this>
     */
    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class)->withTrashed();
    }
}
