<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention of the uploaded files: after RETENTION_DAYS the original file,
 * the raw values of its rows and what the simulation quotes from it (lines,
 * unknown values: personal data) are deleted, and its name (which may name
 * a person) becomes « fichier-import.<ext> ». The batch, its counters,
 * messages, report and undo information stay.
 */
final class ImportFiles
{
    /** Name of a purged file (its own name may be a person's). */
    public const NAME = 'fichier-import';

    /** A name already neutral (PostgreSQL regular expression, see neutralName()). */
    public const NEUTRAL_PATTERN = '^fichier-import(\.[a-z0-9]{1,10})?$';

    /** « fichier-import.<ext> », the extension of $name kept (lower case). */
    public static function neutralName(string $name): string
    {
        $extension = substr((string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(pathinfo($name, PATHINFO_EXTENSION))), 0, 10);

        return self::NAME.($extension === '' ? '' : '.'.$extension);
    }

    /**
     * Current organisme only (run it through ForEachTenant).
     *
     * @return int number of batches purged
     */
    public static function purgeExpired(): int
    {
        $count = 0;
        $limit = now()->subDays(ImportBatch::RETENTION_DAYS);

        ImportBatch::query()->where('created_at', '<', $limit)
            ->where(fn ($q) => $q->whereNotNull('stored_path')
                ->orWhereRaw("jsonb_exists(simulation, 'error_rows')")->orWhereRaw("jsonb_exists(simulation, 'other_rows')")
                ->orWhereRaw("jsonb_exists(simulation, 'unknown_values')")
                ->orWhere('original_name', '!~', self::NEUTRAL_PATTERN)
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('import_rows')
                    ->whereColumn('import_rows.batch_id', 'import_batches.id')->whereNotNull('import_rows.source')))
            // A batch still progressing keeps its file; a stuck one (worker
            // gone for STUCK_MINUTES) would otherwise keep it forever.
            ->where(fn ($q) => $q->whereNotIn('status', [ImportBatch::RUNNING, ImportBatch::UNDOING, ImportBatch::SIMULATING])
                ->orWhere('updated_at', '<=', now()->subMinutes(ImportBatch::STUCK_MINUTES)))
            ->each(function (ImportBatch $batch) use (&$count): void {
                if ($batch->stored_path !== null) {
                    Storage::disk(TenantStorage::DISK)->delete($batch->stored_path);
                }
                DB::table('import_rows')->where('batch_id', $batch->id)->whereNotNull('source')->update(['source' => null]);
                // The simulation lines and unknown values quote file values too.
                $simulation = $batch->simulation;
                if (is_array($simulation)) {
                    unset($simulation['error_rows'], $simulation['other_rows'], $simulation['unknown_values']);
                }
                $batch->forceFill(['stored_path' => null, 'simulation' => $simulation, 'original_name' => self::neutralName($batch->original_name)])->save();
                $count++;
            });

        return $count;
    }
}
