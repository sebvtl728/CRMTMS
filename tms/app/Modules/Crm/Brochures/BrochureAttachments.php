<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;

/**
 * What every e-mail carrying brochures (a quote, a sending from a fiche)
 * shares: the 20 Mo limit of one message (raw bytes of the PDFs), its exact
 * French message, and the reading of the files of recorded versions.
 */
final class BrochureAttachments
{
    /** Bytes: all the attachments of one e-mail. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const TOO_BIG = 'Les pièces jointes dépassent 20 Mo : retirez une plaquette.';

    public const GONE = 'Cette plaquette n\'existe plus.';

    public const MISSING = 'Une plaquette jointe est introuvable : l\'e-mail n\'est pas parti.';

    public static function tooBig(int $bytes): bool
    {
        return $bytes > self::MAX_BYTES;
    }

    /**
     * The files of these versions (Catalogue\Brochures::versions rows), in
     * order; null when one is no longer on the disk (nothing is sent).
     *
     * @param  list<array{version_id: string, path: string, original_name: string, size: int}>  $versions
     * @return list<array{bytes: string, name: string}>|null
     */
    public static function read(array $versions): ?array
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $files = [];
        foreach ($versions as $version) {
            if (! $disk->exists($version['path'])) {
                return null;
            }
            $files[] = ['bytes' => (string) $disk->get($version['path']), 'name' => $version['original_name']];
        }

        return $files;
    }
}
