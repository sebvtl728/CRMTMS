<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Import\Readers\CsvReader;
use App\Modules\Crm\Import\Readers\Readers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives a file to import: checked readable first (format, encoding,
 * header row), then copied to tenants/<id>/imports/<batch>/original.<ext>
 * on the private disk and recorded as an « uploaded » batch.
 */
final class ImportUploads
{
    /**
     * @param  array<string, mixed>  $options  strategy, source_system, delimiter, encoding
     *
     * @throws ImportFileException (French message) when the file cannot be read
     */
    public function store(User $user, string $localPath, string $originalName, ImportTarget $target, array $options = [], ?ImportProfile $profile = null): ImportBatch
    {
        $originalName = mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
        $options = array_filter([
            'strategy' => (DuplicateStrategy::tryFrom(is_string($options['strategy'] ?? null) ? $options['strategy'] : '') ?? DuplicateStrategy::Skip)->value,
            'source_system' => is_string($options['source_system'] ?? null) && trim($options['source_system']) !== '' ? mb_substr(trim($options['source_system']), 0, 50) : null,
            'delimiter' => in_array($options['delimiter'] ?? null, CsvReader::DELIMITERS, true) || ($options['delimiter'] ?? null) === '|' ? $options['delimiter'] : null,
            'encoding' => in_array($options['encoding'] ?? null, [EncodingDetector::UTF8, EncodingDetector::WINDOWS_1252], true) ? $options['encoding'] : null,
        ], fn ($v) => $v !== null);

        // Refused before anything is stored.
        Readers::open($localPath, $originalName, $options);

        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $id = (string) Str::uuid7();
        $path = TenantStorage::path('imports', "{$id}/original.{$extension}");
        $disk = Storage::disk(TenantStorage::DISK);

        $stream = fopen($localPath, 'rb');
        if ($stream === false || ! $disk->writeStream($path, $stream)) {
            throw new ImportFileException('Le fichier n\'a pas pu être enregistré. Réessayez.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            return DB::transaction(function () use ($id, $user, $target, $profile, $originalName, $path, $options): ImportBatch {
                $batch = new ImportBatch(['target' => $target, 'profile_id' => $profile?->id, 'original_name' => $originalName, 'options' => $options]);
                $batch->id = $id;
                $batch->user_id = $user->id;
                $batch->stored_path = $path;
                if ($profile !== null) {
                    $batch->field_map = $profile->field_map;
                    $batch->value_map = $profile->value_map;
                }
                $batch->save();

                return $batch;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }
}
