<?php

namespace App\Modules\Catalogue\Brochures;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\StagedFile;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Writes of the brochure library. A PDF goes on the private disk at
 * tenants/<tenant_id>/brochures/<brochure_id>/<version_id>.pdf (generated
 * name: a replacement never overwrites a previous version) under a temporary
 * name first, and takes its final name only once the database is committed
 * (StagedFile): a failed save leaves nothing on the disk. Journaled as
 * brochure.version_added (type, size, SHA-256: never the name nor the content).
 */
class BrochureStore
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<string>  $trainingIds
     * @param  list<string>  $dasIds
     */
    public function create(string $title, UploadedFile $file, array $trainingIds, array $dasIds, string $kind = Brochure::BROCHURE): Brochure
    {
        $brochureId = (string) Str::uuid7();

        return $this->withStagedFile($brochureId, $file, function (string $staged, string $versionId, string $path) use ($title, $file, $brochureId, $trainingIds, $dasIds, $kind): Brochure {
            $brochure = new Brochure(['title' => $title, 'kind' => $kind]);
            $brochure->id = $brochureId;
            $brochure->save();
            $this->addVersion($brochure, $file, $versionId, $path, $staged);
            $this->syncLinks($brochure, $trainingIds, $dasIds);

            return $brochure;
        });
    }

    /**
     * Changes the title and, when given, the links (null = unchanged); a file
     * adds a version and becomes the current one.
     *
     * @param  list<string>|null  $trainingIds
     * @param  list<string>|null  $dasIds
     */
    public function update(Brochure $brochure, string $title, ?UploadedFile $file, ?array $trainingIds, ?array $dasIds, ?string $kind = null): Brochure
    {
        $save = function (?string $staged = null, ?string $versionId = null, ?string $path = null) use ($brochure, $title, $file, $trainingIds, $dasIds, $kind): Brochure {
            $locked = Brochure::query()->lockForUpdate()->findOrFail($brochure->getKey());
            $locked->update(['title' => $title, 'kind' => $kind ?? $locked->kind]);
            if ($file !== null && $staged !== null && $versionId !== null && $path !== null) {
                $this->addVersion($locked, $file, $versionId, $path, $staged);
            }
            $this->syncLinks($locked, $trainingIds, $dasIds);

            return $locked;
        };

        if ($file === null) {
            return DB::transaction(fn () => $save());
        }

        return $this->withStagedFile($brochure->id, $file, $save);
    }

    /**
     * Soft delete: the versions and their files stay (a sent one is still
     * readable from the history it was sent in).
     */
    public function trash(Brochure $brochure): void
    {
        DB::transaction(fn () => $brochure->delete());
    }

    /**
     * A deleted training no longer carries brochures (they stay in the library).
     */
    public static function detachTraining(string $trainingId): void
    {
        DB::table('brochure_trainings')->where('training_id', $trainingId)->delete();
    }

    /**
     * Stages the upload, runs $write in a transaction (given the staged path,
     * the new version id and its final path) and discards the staged file on
     * any failure.
     *
     * @template T
     *
     * @param  callable(string, string, string): T  $write
     * @return T
     */
    private function withStagedFile(string $brochureId, UploadedFile $file, callable $write): mixed
    {
        $directory = TenantStorage::path('brochures', $brochureId);
        $staged = StagedFile::stage($directory, $file, 'pdf');
        if ($staged === null) {
            report(new RuntimeException('Brochure could not be written to the private disk.'));

            throw ValidationException::withMessages([BrochureFile::FIELD => "La plaquette n'a pas pu être enregistrée. Réessayez ou contactez l'administrateur."]);
        }

        $versionId = (string) Str::uuid7();
        $path = $directory.'/'.$versionId.'.pdf';

        try {
            return DB::transaction(function () use ($write, $staged, $versionId, $path) {
                $result = $write($staged, $versionId, $path);
                StagedFile::promote($staged, $path, null);

                return $result;
            });
        } catch (\Throwable $e) {
            StagedFile::discard($staged);

            throw $e;
        }
    }

    private function addVersion(Brochure $brochure, UploadedFile $file, string $versionId, string $path, string $staged): void
    {
        $version = new BrochureVersion([
            'path' => $path,
            'original_name' => mb_substr(mb_scrub($file->getClientOriginalName()), 0, 255),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', (string) $file->getRealPath()),
        ]);
        $version->id = $versionId;
        $version->brochure_id = $brochure->id;
        $version->save();

        $brochure->forceFill(['current_version_id' => $version->id])->save();

        $this->audit->record('brochure.version_added', $brochure, [], [
            'version' => $version->id,
            'size' => $version->size,
            'sha256' => $version->sha256,
        ]);
    }

    /**
     * @param  list<string>|null  $trainingIds
     * @param  list<string>|null  $dasIds
     */
    private function syncLinks(Brochure $brochure, ?array $trainingIds, ?array $dasIds): void
    {
        $tenant = app(TenantContext::class)->id();
        if ($trainingIds !== null) {
            $brochure->trainings()->sync(array_fill_keys($trainingIds, ['tenant_id' => $tenant]));
        }
        if ($dasIds !== null) {
            $brochure->das()->sync(array_fill_keys($dasIds, ['tenant_id' => $tenant]));
        }
    }
}
