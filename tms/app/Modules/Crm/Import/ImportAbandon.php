<?php

namespace App\Modules\Crm\Import;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Abandons an import that has not run (uploaded, simulating, simulated):
 * the batch, its rows and its file are deleted for good, no fiche is
 * touched (nothing was imported). A simulation in progress stops by itself
 * (SimulateImport exits when its batch no longer exists). The file goes
 * after the commit, so a rollback never loses it.
 */
final class ImportAbandon
{
    public const REFUSED = 'Cet import ne peut plus être abandonné : il a déjà été lancé. Utilisez « Annuler l\'import » pour retirer ce qu\'il a créé.';

    public static function run(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if (! $locked->canAbandon()) {
                throw new UserFacingHttpException(409, self::REFUSED);
            }
            $path = $locked->stored_path;

            DB::table('import_rows')->where('batch_id', $locked->id)->delete();
            // Journaled by the model (target; the file name is masked), never the content.
            app(AuditRecorder::class)->record('crm.import_abandoned', $locked, [], ['target' => $locked->target->value]);
            $locked->delete();

            if (is_string($path) && $path !== '') {
                DB::afterCommit(fn () => Storage::disk(TenantStorage::DISK)->delete($path));
            }
        });
    }
}
