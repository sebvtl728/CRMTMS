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
 * and move) older than ten minutes are removed at the next replacement in
 * the directory (a recent one may be a concurrent upload).
 */
final class StagedFile
{
    private const PREFIX = '.tmp-';

    /** A temporary file older than this is a leftover, never an upload in flight. */
    private const STALE_SECONDS = 600;

    /**
     * Writes $file as a temporary file of $directory; returns its path, or null when the disk refused it.
     */
    public static function stage(string $directory, UploadedFile $file, string $extension): ?string
    {
        $disk = Storage::disk(TenantStorage::DISK);
        foreach ($disk->files($directory) as $leftover) {
            if (! str_starts_with(basename($leftover), self::PREFIX)) {
                continue;
            }
            try {
                $stale = $disk->lastModified($leftover) < time() - self::STALE_SECONDS;
            } catch (\Throwable) {
                continue; // gone meanwhile (moved by its own upload): nothing to clean
            }
            if ($stale) {
                $disk->delete($leftover);
            }
        }

        $name = self::PREFIX.Str::uuid()->toString().'.'.$extension;

        return $disk->putFileAs($directory, $file, $name) === false ? null : $directory.'/'.$name;
    }

    /**
     * After the current transaction commits (at once outside one): the
     * temporary file is renamed over $final (one atomic rename: the old file
     * is never deleted first), $previous (another name) goes, then $after
     * runs (caches that depend on the file).
     *
     * @param  (callable(): mixed)|null  $after
     */
    public static function promote(string $temporary, string $final, ?string $previous, ?callable $after = null): void
    {
        DB::afterCommit(function () use ($temporary, $final, $previous, $after): void {
            $disk = Storage::disk(TenantStorage::DISK);
            if (! $disk->move($temporary, $final)) {
                report(new RuntimeException('A staged file could not be moved to its final name.'));

                return;
            }
            if ($previous !== null && $previous !== $final) {
                $disk->delete($previous);
            }
            if ($after !== null) {
                $after();
            }
        });
    }

    public static function discard(string $temporary): void
    {
        Storage::disk(TenantStorage::DISK)->delete($temporary);
    }
}
