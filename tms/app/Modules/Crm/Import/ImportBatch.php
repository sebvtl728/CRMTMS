<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Import\Readers\Readers;
use App\Modules\Crm\Import\Readers\TabularReader;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded file and its import: uploaded -> simulated -> running ->
 * done | failed, then undoing -> undone.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property ImportTarget $target
 * @property string|null $profile_id
 * @property string $original_name
 * @property string|null $stored_path
 * @property string $status
 * @property array<string, mixed> $options strategy, source_system, delimiter, encoding
 * @property array<string, string>|null $field_map
 * @property array<string, array<string, string>>|null $value_map
 * @property array<string, mixed>|null $simulation
 * @property int $total
 * @property int $created
 * @property int $updated
 * @property int $skipped
 * @property int $errors
 * @property int $cursor_line
 * @property string|null $report_path
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $undone_at
 * @property Carbon $created_at
 */
class ImportBatch extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const UPLOADED = 'uploaded';

    public const SIMULATED = 'simulated';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNDOING = 'undoing';

    public const UNDONE = 'undone';

    /** Original files (and raw row values) are kept this many days. */
    public const RETENTION_DAYS = 30;

    /**
     * The upload is journaled (who, which file, which target); its progress
     * is not (crm.import_run / crm.import_undone sum it up).
     *
     * @var list<string>
     */
    protected array $auditIgnore = [
        'status', 'stored_path', 'field_map', 'value_map', 'simulation', 'total', 'created', 'updated', 'skipped', 'errors',
        'cursor_line', 'report_path', 'started_at', 'finished_at', 'undone_at', 'options',
    ];

    /** @var list<string> */
    protected $fillable = ['target', 'profile_id', 'original_name', 'options'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::UPLOADED, 'options' => '{}'];

    protected function casts(): array
    {
        return [
            'target' => ImportTarget::class,
            'options' => 'array',
            'field_map' => 'array',
            'value_map' => 'array',
            'simulation' => 'array',
            'total' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'errors' => 'integer',
            'cursor_line' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id');
    }

    public function strategy(): DuplicateStrategy
    {
        return DuplicateStrategy::tryFrom((string) ($this->options['strategy'] ?? '')) ?? DuplicateStrategy::Skip;
    }

    public function sourceSystem(): ?string
    {
        $system = $this->options['source_system'] ?? null;

        return is_string($system) && trim($system) !== '' ? mb_substr(trim($system), 0, 50) : null;
    }

    public function fieldMap(): FieldMap
    {
        return FieldMap::fromArray($this->target, $this->field_map ?? []);
    }

    public function valueMapper(): ValueMapper
    {
        return ValueMapper::fromArray($this->value_map);
    }

    /**
     * @throws ImportFileException when the file is gone (purged) or unreadable
     */
    public function reader(): TabularReader
    {
        if ($this->stored_path === null || ! Storage::disk(TenantStorage::DISK)->exists($this->stored_path)) {
            throw new ImportFileException('Le fichier importé n\'est plus disponible (supprimé après '.self::RETENTION_DAYS.' jours).');
        }

        return Readers::open(Storage::disk(TenantStorage::DISK)->path($this->stored_path), $this->original_name, [
            'delimiter' => is_string($this->options['delimiter'] ?? null) ? $this->options['delimiter'] : null,
            'encoding' => is_string($this->options['encoding'] ?? null) ? $this->options['encoding'] : null,
        ]);
    }

    public function canRun(): bool
    {
        return $this->status === self::SIMULATED;
    }

    public function canUndo(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED], true);
    }
}
