<?php

namespace App\Modules\Core\Tenancy;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Replaces a stored file without ever losing the previous one: the upload is
 * written under a temporary name next to it, the caller saves the database,
 * and only once that is committed is the temporary file moved to its final
 * name (and a previous file of another name deleted). A failed save deletes
 * the temporary file only. Leftover temporary files (a crash between commit
 * and move) are removed at the next replacement in the directory.
 */
final class StagedFile
{
    private const PREFIX = '.tmp-';

    /**
     * Writes $file as a temporary file of $directory; returns its path, or null when the disk refused it.
     */
    public static function stage(string $directory, UploadedFile $file, string $extension): ?string
    {
        $disk = Storage::disk(TenantStorage::DISK);
        foreach ($disk->files($directory) as $leftover) {
            if (str_starts_with(basename($leftover), self::PREFIX)) {
                $disk->delete($leftover);
            }
        }

        $name = self::PREFIX.Str::uuid()->toString().'.'.$extension;

        return $disk->putFileAs($directory, $file, $name) === false ? null : $directory.'/'.$name;
    }

    /**
     * After the current transaction commits (at once outside one): the
     * temporary file becomes $final, and $previous (another name) goes.
     */
    public static function promote(string $temporary, string $final, ?string $previous): void
    {
        DB::afterCommit(function () use ($temporary, $final, $previous): void {
            $disk = Storage::disk(TenantStorage::DISK);
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            if (! $disk->move($temporary, $final)) {
                report(new RuntimeException('A staged file could not be moved to its final name.'));
            }
            if ($previous !== null && $previous !== $final) {
                $disk->delete($previous);
            }
        });
    }

    public static function discard(string $temporary): void
    {
        Storage::disk(TenantStorage::DISK)->delete($temporary);
    }
}
