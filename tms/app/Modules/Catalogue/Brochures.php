<?php

namespace App\Modules\Catalogue;

use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochureVersion;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public service of the brochure library, the only way other modules (Crm:
 * quotes, sending from a fiche) read brochures. A version is identified by
 * its id: that is what a sending records, so that a resend or a reminder
 * reaches the same file even after the brochure was replaced or trashed
 * (versions are never erased).
 */
final class Brochures
{
    /** @var array<string, \Closure(string): bool> */
    private static array $sentChecks = [];

    /**
     * « This version was sent »: each module that sends brochures (quote
     * e-mails, sendings from a fiche) registers a check, so that a sent
     * version of a trashed brochure stays downloadable from the history
     * (Catalogue never reads their tables).
     *
     * @param  string  $name  unique name (module.data), a second registration replaces the first
     * @param  callable(string): bool  $wasSent  true when the version whose id is given was sent
     */
    public static function registerSentCheck(string $name, callable $wasSent): void
    {
        self::$sentChecks[$name] = \Closure::fromCallable($wasSent);
    }

    /**
     * Only files in the tenant's own brochures directory are ever read.
     */
    public static function isOwnPath(string $path): bool
    {
        return str_starts_with($path, TenantStorage::path('brochures').'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    public static function wasSent(string $versionId): bool
    {
        foreach (self::$sentChecks as $check) {
            if ($check($versionId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The current version of every brochure (not in the trash) attached to
     * one of these trainings, directly or through the DAS of one of them,
     * each brochure once.
     *
     * @param  list<string>  $trainingIds
     * @return list<array{brochure_id: string, version_id: string, title: string, original_name: string, size: int}>
     */
    public function forTrainings(array $trainingIds): array
    {
        $ids = self::uuids($trainingIds);
        if ($ids === []) {
            return [];
        }

        $brochures = Brochure::query()->with('currentVersion')
            ->whereNotNull('current_version_id')
            ->where(fn ($q) => $q
                ->whereIn('brochures.id', DB::table('brochure_trainings')->select('brochure_id')->whereIn('training_id', $ids))
                ->orWhereIn('brochures.id', DB::table('brochure_das')->select('brochure_id')
                    ->whereIn('das_id', Training::query()->whereIn('trainings.id', $ids)->select('trainings.das_id'))))
            ->orderBy('title')->orderBy('id')->get();

        return $brochures->map(fn (Brochure $b) => self::row($b))->filter()->values()->all();
    }

    /**
     * The brochures of one training for its page: attached to it directly
     * (source « training », also when its DAS carries the same one) or through
     * its DAS (source « das »), each once, by title.
     *
     * @return list<array{id: string, title: string, original_name: string, size: int, source: string}>
     */
    public function forTrainingPage(Training $training): array
    {
        $direct = DB::table('brochure_trainings')->where('training_id', $training->id)->pluck('brochure_id')->all();
        $viaDas = DB::table('brochure_das')->where('das_id', $training->das_id)->pluck('brochure_id')->all();

        return Brochure::query()->with('currentVersion')->whereNotNull('current_version_id')
            ->whereIn('brochures.id', array_merge($direct, $viaDas))
            ->orderBy('title')->orderBy('id')->get()
            ->map(fn (Brochure $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'original_name' => (string) $b->currentVersion?->original_name,
                'size' => (int) $b->currentVersion?->size,
                'source' => in_array($b->id, $direct, true) ? 'training' : 'das',
            ])->values()->all();
    }

    /**
     * The versions of these ids, in the order given, unknown ones (and those
     * of another organisme) left out. Versions of a brochure in the trash
     * are returned too.
     *
     * @param  list<string>  $versionIds
     * @return list<array{version_id: string, path: string, original_name: string, size: int}>
     */
    public function versions(array $versionIds): array
    {
        $ids = self::uuids($versionIds);
        if ($ids === []) {
            return [];
        }

        $found = BrochureVersion::query()->whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        foreach ($ids as $id) {
            $version = $found->get($id);
            if ($version instanceof BrochureVersion) {
                $rows[] = [
                    'version_id' => $version->id,
                    'path' => $version->path,
                    'original_name' => $version->original_name,
                    'size' => $version->size,
                ];
            }
        }

        return $rows;
    }

    /**
     * Among these version ids, the rows of those still offered for a new
     * sending: the current version of a brochure that is not in the trash
     * (an older version, or one of a trashed brochure, is left out).
     *
     * @param  list<string>  $versionIds
     * @return list<array{version_id: string, path: string, original_name: string, size: int}>
     */
    public function offered(array $versionIds): array
    {
        $ids = self::uuids($versionIds);
        if ($ids === []) {
            return [];
        }
        $current = Brochure::query()->whereIn('current_version_id', $ids)->pluck('current_version_id')->all();

        return array_values(array_filter($this->versions($ids), fn (array $v) => in_array($v['version_id'], $current, true)));
    }

    /**
     * The brochure of each version (brochures in the trash included), for
     * the history of what was sent.
     *
     * @param  list<string>  $versionIds
     * @return array<string, array{brochure_id: string, title: string, trashed: bool}> version id => brochure
     */
    public function sent(array $versionIds): array
    {
        $ids = self::uuids($versionIds);
        if ($ids === []) {
            return [];
        }
        $titles = [];
        foreach (BrochureVersion::query()->whereIn('id', $ids)->with(['brochure' => fn ($q) => $q->withTrashed()])->get() as $version) {
            $titles[$version->id] = ['brochure_id' => $version->brochure_id, 'title' => (string) $version->brochure?->title, 'trashed' => $version->brochure?->trashed() ?? true];
        }

        return $titles;
    }

    /**
     * Brochures to choose from (not in the trash), by title, optionally those
     * whose title holds every word of $search (accent-insensitive).
     *
     * @return list<array{brochure_id: string, version_id: string, title: string}>
     */
    public function options(?string $search = null): array
    {
        return Brochure::query()->whereNotNull('current_version_id')
            ->when($search !== null && trim($search) !== '', fn ($q) => $q->titleMatches(mb_substr(trim((string) $search), 0, 100)))
            ->orderBy('title')->orderBy('id')->get(['id', 'title', 'current_version_id'])
            ->map(fn (Brochure $b) => ['brochure_id' => $b->id, 'version_id' => (string) $b->current_version_id, 'title' => $b->title])
            ->values()->all();
    }

    /**
     * @return array{brochure_id: string, version_id: string, title: string, original_name: string, size: int}|null
     */
    public static function row(Brochure $brochure): ?array
    {
        $version = $brochure->currentVersion;
        if ($version === null) {
            return null;
        }

        return [
            'brochure_id' => $brochure->id,
            'version_id' => $version->id,
            'title' => $brochure->title,
            'original_name' => $version->original_name,
            'size' => $version->size,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, fn ($id) => Str::isUuid($id))));
    }
}
