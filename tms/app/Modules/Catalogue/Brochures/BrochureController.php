<?php

namespace App\Modules\Catalogue\Brochures;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Brochures as BrochureLibrary;
use App\Modules\Catalogue\Permissions;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingListQuery;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Plaquettes » screens of the catalogue: library list (catalogue.view),
 * deposit and change (catalogue.manage), trash (catalogue.delete), and the
 * download of a version from the private disk.
 */
class BrochureController extends Controller
{
    public function __construct(private readonly BrochureStore $store) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $type = is_string($request->query('type')) && array_key_exists($request->query('type'), Brochure::KINDS) ? (string) $request->query('type') : null;

        $brochures = Brochure::query()->with(['currentVersion', 'trainings:id,title', 'das'])->withCount('versions')
            ->titleMatches($q)->when($type !== null, fn ($query) => $query->where('kind', $type))->orderBy('title')->orderBy('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Brochure $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'kind' => $b->kind,
                'kind_label' => $b->kindLabel(),
                'original_name' => $b->currentVersion?->original_name,
                'size' => $b->currentVersion?->size,
                'versions_count' => $b->versions_count,
                'updated_at' => $b->updated_at?->toIso8601String(),
                'trainings' => $b->trainings->pluck('title')->sort()->values()->all(),
                'das' => $b->das->pluck('label')->sort()->values()->all(),
            ]);

        return Inertia::render('catalogue/brochures/index', [
            'brochures' => $brochures,
            'filters' => ['q' => $q, 'type' => $type],
            'kinds' => self::kinds(),
            'can' => ['manage' => $user->can(Permissions::MANAGE), 'delete' => $user->can(Permissions::DELETE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Brochure::class);

        return Inertia::render('catalogue/brochures/form', [
            'brochure' => null,
            'default_kind' => $request->query('type') === Brochure::POSITIONING_TEST ? Brochure::POSITIONING_TEST : Brochure::BROCHURE,
            'options' => self::options([], []),
            'kinds' => self::kinds(),
        ]);
    }

    public function store(BrochureRequest $request): RedirectResponse
    {
        Gate::authorize('create', Brochure::class);

        $file = $request->file(BrochureFile::FIELD);
        assert($file instanceof UploadedFile);
        $kind = (string) ($request->validated('kind') ?? Brochure::BROCHURE);
        $this->store->create((string) $request->validated('title'), $file, $request->ids('training_ids') ?? [], $request->ids('das_ids') ?? [], $kind);

        return to_route('catalogue.brochures.index')->with('status', $kind === Brochure::POSITIONING_TEST ? 'Test de positionnement ajouté.' : 'Plaquette ajoutée.');
    }

    public function edit(Brochure $brochure): Response
    {
        Gate::authorize('update', $brochure);

        $trainingIds = $brochure->trainings()->pluck('trainings.id')->all();
        $dasIds = $brochure->das()->pluck('reference_items.id')->all();

        return Inertia::render('catalogue/brochures/form', [
            'brochure' => [
                'id' => $brochure->id,
                'title' => $brochure->title,
                'kind' => $brochure->kind,
                'training_ids' => $trainingIds,
                'das_ids' => $dasIds,
                'versions' => $brochure->versions()->get()->map(fn (BrochureVersion $v) => [
                    'id' => $v->id,
                    'original_name' => $v->original_name,
                    'size' => $v->size,
                    'created_at' => $v->created_at?->toIso8601String(),
                    'current' => $v->id === $brochure->current_version_id,
                ])->values()->all(),
            ],
            'options' => self::options($trainingIds, $dasIds),
            'kinds' => self::kinds(),
        ]);
    }

    public function update(BrochureRequest $request, Brochure $brochure): RedirectResponse
    {
        Gate::authorize('update', $brochure);

        $file = $request->file(BrochureFile::FIELD);
        $this->store->update($brochure, (string) $request->validated('title'), $file instanceof UploadedFile ? $file : null, $request->ids('training_ids'), $request->ids('das_ids'), $request->validated('kind'));

        return to_route('catalogue.brochures.index')->with('status', 'Document enregistré.');
    }

    public function destroy(Brochure $brochure): RedirectResponse
    {
        Gate::authorize('delete', $brochure);

        $this->store->trash($brochure);

        return to_route('catalogue.brochures.index')->with('status', 'Plaquette mise à la corbeille.');
    }

    /**
     * The current version.
     */
    public function download(Brochure $brochure): StreamedResponse
    {
        Gate::authorize('view', $brochure);

        $version = $brochure->currentVersion;
        abort_if($version === null, 404);

        return self::stream($version);
    }

    /**
     * One version of this brochure (a previous one included).
     */
    public function downloadVersion(Brochure $brochure, string $version): StreamedResponse
    {
        Gate::authorize('view', $brochure);

        abort_unless(Str::isUuid($version), 404);
        $found = $brochure->versions()->whereKey($version)->first();
        abort_if($found === null, 404);
        // A brochure in the trash only serves the versions that were sent.
        abort_if($brochure->trashed() && ! BrochureLibrary::wasSent($found->id), 404);

        return self::stream($found);
    }

    /**
     * Streams a stored PDF as a download under its original name (UTF-8
     * filename*, with an ASCII fallback). Missing on disk: 404.
     */
    public static function stream(BrochureVersion $version): StreamedResponse
    {
        $disk = Storage::disk(TenantStorage::DISK);
        abort_unless(self::isOwnPath($version) && $disk->exists($version->path), 404);

        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($version->original_name)) ?: 'plaquette.pdf';

        return response()->stream(function () use ($disk, $version): void {
            $stream = $disk->readStream($version->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $disk->size($version->path),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $version->original_name, $fallback),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Only files in the tenant's own brochures directory are ever read.
     */
    private static function isOwnPath(BrochureVersion $version): bool
    {
        return BrochureLibrary::isOwnPath($version->path);
    }

    /**
     * @param  list<string>  $trainingIds  attached trainings (kept as choices even when inactive)
     * @param  list<string>  $dasIds
     * @return array<string, mixed>
     */
    private static function options(array $trainingIds, array $dasIds): array
    {
        return [
            'das' => TrainingListQuery::reference(ReferenceList::Das, $dasIds),
            'trainings' => Training::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $trainingIds))
                ->orderBy('title')->orderBy('id')->get(['id', 'code', 'title', 'is_active'])
                ->map(fn (Training $t) => [
                    'value' => $t->id,
                    'label' => ($t->code !== null ? $t->code.' · ' : '').$t->title.($t->is_active ? '' : ' (inactive)'),
                ])->values()->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function kinds(): array
    {
        return array_map(fn (string $k) => ['value' => $k, 'label' => Brochure::KINDS[$k]], array_keys(Brochure::KINDS));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
