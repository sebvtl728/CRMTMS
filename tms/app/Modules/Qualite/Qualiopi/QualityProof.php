<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A proof of a Qualiopi indicator: a file (private disk, TenantStorage zone
 * « quality »), a link or a note.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $referential_version
 * @property int $number
 * @property string $kind file | link | note
 * @property string $title
 * @property string|null $url
 * @property string|null $note
 * @property string|null $path
 * @property string|null $original_name
 * @property int|null $size
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class QualityProof extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'quality_proof';

    /** @var list<string> */
    protected array $auditIgnore = ['path', 'created_by'];

    /** @var list<string> */
    protected $fillable = ['referential_version', 'number', 'kind', 'title', 'url', 'note', 'path', 'original_name', 'size'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (QualityProof $proof): void {
            $proof->created_by ??= Auth::id();
        });
    }
}
