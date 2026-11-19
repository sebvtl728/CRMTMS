<?php

namespace App\Modules\Admissions\Positioning;

use App\Modules\Catalogue\Brochures;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;

/**
 * The PDF files of the tests of a sending, read from the library through
 * its public service (only paths in the organisme's brochures directory),
 * 20 MB at most per e-mail.
 */
final class PositioningAttachments
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** @param  list<string>  $versionIds */
    public static function size(array $versionIds): int
    {
        return array_sum(array_column(app(Brochures::class)->versions($versionIds), 'size'));
    }

    /**
     * @param  list<string>  $versionIds
     * @return list<array{name: string, content: string}>|null null when a file is missing
     */
    public static function read(array $versionIds): ?array
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $files = [];
        foreach (app(Brochures::class)->versions($versionIds) as $version) {
            if (! Brochures::isOwnPath($version['path']) || ! $disk->exists($version['path'])) {
                return null;
            }
            $files[] = ['name' => $version['original_name'], 'content' => (string) $disk->get($version['path'])];
        }

        return $files;
    }
}
