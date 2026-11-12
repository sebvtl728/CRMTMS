<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention of the uploaded files: after RETENTION_DAYS the original file
 * and the raw values of its rows (personal data) are deleted. The batch,
 * its counters, messages, report and undo information stay.
 */
final class ImportFiles
{
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
            ->where(fn ($q) => $q->whereNotNull('stored_path')->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('import_rows')
                ->whereColumn('import_rows.batch_id', 'import_batches.id')->whereNotNull('import_rows.source')))
            ->whereNotIn('status', [ImportBatch::RUNNING, ImportBatch::UNDOING])
            ->each(function (ImportBatch $batch) use (&$count): void {
                if ($batch->stored_path !== null) {
                    Storage::disk(TenantStorage::DISK)->delete($batch->stored_path);
                }
                DB::table('import_rows')->where('batch_id', $batch->id)->whereNotNull('source')->update(['source' => null]);
                $batch->forceFill(['stored_path' => null])->save();
                $count++;
            });

        return $count;
    }
}
