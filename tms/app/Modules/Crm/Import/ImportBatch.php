<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Audit\AuditRecorder;
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
 * One uploaded file and its import: uploaded -> simulating -> simulated ->
 * running -> done | failed, then undoing -> undone. A batch running,
 * simulating or undoing without progress for STUCK_MINUTES is « stuck » and
 * may be marked failed (what it created can then be undone).
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

    public const SIMULATING = 'simulating';

    public const SIMULATED = 'simulated';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNDOING = 'undoing';

    public const UNDONE = 'undone';

    /** No progress for this long: the worker is considered dead. */
    public const STUCK_MINUTES = 15;

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

    /** The mapping can change until the run starts (SIMULATING: the background job itself). */
    public function canSimulate(): bool
    {
        return in_array($this->status, [self::UPLOADED, self::SIMULATING, self::SIMULATED], true);
    }

    /**
     * Records the mapping and queues a (re)simulation: the status becomes
     * « simulating » at once, so the wizard shows the progress right away.
     * The caller dispatches SimulateImport.
     *
     * Conditional update: a run started in the meantime (another tab, a
     * double click) is never turned back into a simulation.
     *
     * @return bool false when the batch was already run (nothing written)
     */
    public function startSimulation(FieldMap $map, ValueMapper $values): bool
    {
        $editable = [self::UPLOADED, self::SIMULATED];
        if (! in_array($this->status, $editable, true)) {
            return false;
        }

        $options = $this->options;
        unset($options['simulation_error']);
        $this->forceFill([
            'field_map' => $map->toArray(), 'value_map' => $values->toArray(), 'options' => $options,
            'simulation' => null, 'status' => self::SIMULATING, 'cursor_line' => 0, 'total' => 0,
        ]);
        $changed = self::query()->whereKey($this->id)->whereIn('status', $editable)->update($this->getDirty());
        if ($changed === 0) {
            $this->refresh();

            return false;
        }
        $this->syncOriginal();

        return true;
    }

    /**
     * Run requested: « running » at once with fresh counters (RunImport
     * continues from here). The raw lines of the simulation are dropped.
     */
    public function start(): void
    {
        $simulation = $this->simulation ?? [];
        unset($simulation['error_rows'], $simulation['other_rows']);
        $this->forceFill(['status' => self::RUNNING, 'started_at' => now(), 'cursor_line' => 0, 'total' => 0, 'created' => 0,
            'updated' => 0, 'skipped' => 0, 'errors' => 0, 'simulation' => $simulation])->save();
    }

    /**
     * Undo requested: « undoing » at once (UndoImport continues from here).
     */
    public function startUndo(): void
    {
        $options = $this->options;
        $options['undo'] = ['removed' => 0, 'kept' => 0];
        $this->forceFill(['status' => self::UNDOING, 'cursor_line' => 0, 'options' => $options])->save();
    }

    /** Running, simulating or undoing with no progress for STUCK_MINUTES. */
    public function isStuck(): bool
    {
        return in_array($this->status, [self::RUNNING, self::SIMULATING, self::UNDOING], true)
            && $this->updated_at !== null
            && $this->updated_at->lte(now()->subMinutes(self::STUCK_MINUTES));
    }

    /**
     * A stuck run, simulation or undo is declared failed: what it created
     * can then be undone. Conditional update: a worker that wrote progress
     * in the meantime wins. Journaled (crm.import_failed_marked).
     *
     * @return bool false when the batch is progressing or not running
     */
    public function markFailed(User $by): bool
    {
        $options = $this->options;
        $options['failure'] = 'Marqué comme échoué par '.$by->name.' : l\'import ne progressait plus.';
        $changed = self::query()->whereKey($this->id)
            ->whereIn('status', [self::RUNNING, self::SIMULATING, self::UNDOING])
            ->where('updated_at', '<=', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => self::FAILED, 'finished_at' => now(), 'updated_at' => now(), 'options' => json_encode($options, JSON_UNESCAPED_UNICODE)]);
        if ($changed === 0) {
            return false;
        }

        $this->refresh();
        app(AuditRecorder::class)->record('crm.import_failed_marked', $this, [], [
            'file' => $this->original_name, 'target' => $this->target->value, 'total' => $this->total, 'created' => $this->created,
        ], $by);

        return true;
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
