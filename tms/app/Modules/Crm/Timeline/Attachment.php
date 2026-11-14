<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * File attached to an entreprise or a person. The bytes live on the private
 * disk under tenants/<tenant_id>/attachments/<uuid>; deleting the row keeps
 * the file until the GDPR purge; the file of a fiche trashed for 12 months
 * is deleted, the row kept (AttachmentPurge). The file name may carry a person's name:
 * the journal masks it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $notable_type
 * @property string $notable_id
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property string|null $uploaded_by
 * @property Carbon $created_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $file_deleted_at file deleted by the retention purge (AttachmentPurge), row kept
 */
class Attachment extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MAX_KILOBYTES = 20 * 1024;

    /**
     * Accepted extensions and, for each, the real MIME types (finfo) the
     * content may have. The first one is stored and served. Office formats
     * are zip containers: some libmagic versions only say application/zip.
     */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ];

    /** @var list<string> */
    protected array $auditPersonal = ['original_name'];

    /** @var list<string> */
    protected array $auditIgnore = ['path', 'file_deleted_at'];

    /** @var list<string> */
    protected $fillable = ['original_name', 'mime', 'size', 'path'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (Attachment $attachment): void {
            $attachment->uploaded_by ??= Auth::id();
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'file_deleted_at' => 'datetime'];
    }
}
