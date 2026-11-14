<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention of attached files: once their fiche (entreprise or person) has
 * been in the trash for TRASHED_MONTHS, the files are deleted from the disk.
 * The row stays (who added what, when), marked file_deleted_at and shown as
 * « Fichier supprimé »; restoring the fiche does not bring the file back.
 * Idempotent.
 */
final class AttachmentPurge
{
    public const TRASHED_MONTHS = 12;

    public const DELETED_LABEL = 'Fichier supprimé';

    public const DELETED_MESSAGE = 'Fichier supprimé : la fiche était dans la corbeille depuis plus de '.self::TRASHED_MONTHS.' mois.';

    /**
     * Current organisme only (run it through ForEachTenant).
     *
     * @return int number of files deleted
     */
    public static function purgeTrashedFiches(): int
    {
        $limit = now()->subMonths(self::TRASHED_MONTHS);
        $count = 0;

        foreach ([Organisation::class, Person::class] as $model) {
            $fiche = new $model;
            $trashed = $model::onlyTrashed()->where($fiche->qualifyColumn('deleted_at'), '<=', $limit)->select($fiche->getQualifiedKeyName());

            Attachment::withTrashed()->whereNull('file_deleted_at')
                ->where('notable_type', $fiche->getMorphClass())
                ->whereIn('notable_id', $trashed)
                ->chunkById(500, function ($attachments) use (&$count): void {
                    $disk = Storage::disk(TenantStorage::DISK);
                    // Only the files really gone are flagged; a failed deletion is retried next run.
                    $deleted = $attachments->filter(fn (Attachment $a): bool => $disk->delete($a->path) || ! $disk->exists($a->path));
                    if ($deleted->isEmpty()) {
                        return;
                    }
                    // Not a user change: no journal line (the file name is personal data anyway).
                    $count += DB::table('attachments')->whereIn('id', $deleted->modelKeys())->whereNull('file_deleted_at')
                        ->update(['file_deleted_at' => now()]);
                });
        }

        return $count;
    }
}
