<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file of a candidature (table candidature_files): positioning test,
 * signed contract or the value of a « file » custom field (kind
 * custom:<field id>). Stored under TenantStorage zone « admissions ».
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property string $kind positioning|contract|custom:<uuid>
 * @property string $name
 * @property int $size
 * @property string $path
 * @property string|null $uploaded_by
 */
class CandidatureFile extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'candidature_file';

    /** @var list<string> */
    protected array $auditPersonal = ['name'];

    /** @var list<string> */
    protected array $auditIgnore = ['path', 'uploaded_by'];

    /** @var list<string> */
    protected $fillable = ['candidature_id', 'kind', 'name', 'size', 'path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Candidature, $this> */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }
}
