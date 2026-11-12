<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outcome of one file row. Written in bulk by RunImport (no model events);
 * `source` holds the raw values of the row: personal data, never journaled
 * (every column is ignored by the audit), purged with the file and by the
 * anonymisation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $batch_id
 * @property int $line
 * @property array<string, string>|null $source
 * @property string $status created|updated|skipped|error
 * @property list<array{level: string, text: string}> $messages
 * @property string|null $subject_type morph alias
 * @property string|null $subject_id
 * @property bool $created_subject
 * @property list<array{type: string, id: string}> $created_refs every fiche created by the row
 */
class ImportRow extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    public const LABELS = [
        self::CREATED => 'Créée',
        self::UPDATED => 'Mise à jour',
        self::SKIPPED => 'Ignorée',
        self::ERROR => 'Erreur',
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected array $auditIgnore = [
        'id', 'tenant_id', 'batch_id', 'line', 'source', 'status', 'messages', 'subject_type', 'subject_id', 'created_subject', 'created_refs',
    ];

    /** @var list<string> */
    protected $fillable = ['line', 'source', 'status', 'messages', 'subject_type', 'subject_id', 'created_subject', 'created_refs'];

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'source' => 'array',
            'messages' => 'array',
            'created_subject' => 'boolean',
            'created_refs' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}
