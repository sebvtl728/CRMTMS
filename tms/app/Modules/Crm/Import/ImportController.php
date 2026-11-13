<?php

namespace App\Modules\Crm\Import;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Import\Readers\CsvReader;
use App\Modules\Crm\Organisations\OrganisationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * « Importer » wizard (crm.import): 1 file, 2 columns, 3 values, 4 simulation,
 * 5 import (progress), 6 report; and the history with « Annuler l'import ».
 * Simulation, run and undo are queued jobs the pages poll (status()).
 * A batch is visible to its author and to users.manage (404 otherwise).
 */
class ImportController extends Controller
{
    private const ALREADY_RUN = 'Cet import a déjà été lancé.';

    public const MAX_MEGABYTES = 20;

    /** Distinct file values per list offered to the user at once. */
    private const MAX_VALUES = 500;

    private const STATUS_LABELS = [
        ImportBatch::UPLOADED => 'Fichier déposé',
        ImportBatch::SIMULATING => 'Simulation en cours',
        ImportBatch::SIMULATED => 'Prêt à importer',
        ImportBatch::RUNNING => 'Import en cours',
        ImportBatch::DONE => 'Terminé',
        ImportBatch::FAILED => 'Échoué',
        ImportBatch::UNDOING => 'Annulation en cours',
        ImportBatch::UNDONE => 'Annulé',
    ];

    public function __construct(private readonly ImportUploads $uploads) {}

    // ---------------------------------------------------------------- history

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $batches = $this->visible($user)->with('user:id,name')->latest()->paginate(20)->withQueryString()
            ->through(fn (ImportBatch $b) => [
                'id' => $b->id,
                'created_at' => $b->created_at->toIso8601String(),
                'file' => $b->original_name,
                'target' => $b->target->label(),
                'author' => $b->user?->name,
                'status' => $this->statusOf($b),
                'stuck' => $b->isStuck(),
                'total' => $b->total,
                'created' => $b->created,
                'updated' => $b->updated,
                'skipped' => $b->skipped,
                'errors' => $b->errors,
                'canUndo' => $this->canUndo($b),
            ]);

        return Inertia::render('crm/import/index', [
            'batches' => $batches,
            'seesAll' => $user->can('users.manage'),
            'flash' => $request->session()->get('status'),
        ]);
    }

    // ------------------------------------------------------------ 1. the file

    public function create(): Response
    {
        return Inertia::render('crm/import/upload', [
            'targets' => array_map(fn (ImportTarget $t) => ['value' => $t->value, 'label' => $t->label()], ImportTarget::cases()),
            'profiles' => ImportProfile::query()->orderBy('name')->get(['id', 'name', 'target'])
                ->map(fn (ImportProfile $p) => ['id' => $p->id, 'name' => $p->name, 'target' => $p->target->value])->all(),
            'maxMegabytes' => self::MAX_MEGABYTES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'file' => ['required', 'file', 'max:'.(self::MAX_MEGABYTES * 1024)],
            'target' => ['required', Rule::enum(ImportTarget::class)],
            'profile_id' => ['nullable', 'uuid'],
            'delimiter' => ['nullable', Rule::in([';', ',', 'tab'])],
            'encoding' => ['nullable', Rule::in([EncodingDetector::UTF8, EncodingDetector::WINDOWS_1252])],
        ], [
            'file.required' => 'Choisissez le fichier à importer.',
            'file.file' => 'Le fichier n\'a pas pu être reçu. Réessayez.',
            'file.max' => 'Le fichier dépasse '.self::MAX_MEGABYTES.' Mo : découpez-le en plusieurs fichiers.',
            'file.uploaded' => 'Le fichier n\'a pas pu être reçu (il dépasse peut-être '.self::MAX_MEGABYTES.' Mo). Réessayez avec un fichier plus léger.',
            'target.required' => 'Indiquez ce que contient le fichier.',
            'target.enum' => 'Indiquez ce que contient le fichier.',
        ]);

        $file = $request->file('file');
        assert($file instanceof UploadedFile);
        $name = $file->getClientOriginalName();
        if (! in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Format non pris en charge : déposez un fichier CSV ou Excel (.xlsx). Un fichier .xls doit d\'abord être enregistré en .xlsx.']);
        }

        $target = ImportTarget::from($input['target']);
        $profile = null;
        if (! empty($input['profile_id'])) {
            $profile = ImportProfile::query()->find($input['profile_id']);
            if ($profile === null || $profile->target !== $target) {
                throw ValidationException::withMessages(['profile_id' => 'Ce profil ne correspond pas au type de données choisi.']);
            }
        }

        $options = array_filter([
            ...($profile === null ? [] : $profile->options),
            'delimiter' => ($input['delimiter'] ?? null) === 'tab' ? "\t" : ($input['delimiter'] ?? null),
            'encoding' => $input['encoding'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $batch = $this->uploads->store($this->user($request), $file->getRealPath(), $name, $target, $options, $profile);
        } catch (ImportFileException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return to_route('crm.import.mapping', $batch->id);
    }

    // ----------------------------------------------------------- 2. columns

    public function mapping(Request $request, string $batch): Response|RedirectResponse
    {
        $batch = $this->find($request, $batch);
        if (! $this->editable($batch)) {
            return $this->toShow($batch);
        }

        try {
            $reader = $batch->reader();
            $headers = $reader->headers();
            $preview = [];
            foreach ($reader->rows() as $row) {
                $preview[] = array_map(fn (string $h) => $row[$h] ?? '', $headers);
                if (count($preview) === 5) {
                    break;
                }
            }
        } catch (ImportFileException $e) {
            return to_route('crm.import.index')->withErrors(['batch' => $e->getMessage()]);
        }

        $target = $batch->target;
        $map = $batch->field_map ?? FieldMap::guess($target, $headers)->toArray();

        return Inertia::render('crm/import/mapping', [
            'batch' => $this->summary($batch),
            'headers' => $headers,
            'preview' => $preview,
            'warnings' => $reader->warnings(),
            'info' => $this->readInfo($reader),
            'fields' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label, 'required' => in_array($value, $target->requiredFields(), true)],
                array_keys($target->fields()), array_values($target->fields())),
            'map' => (object) $map,
            'identifierFields' => $target->identifierFields(),
            'sourceSystem' => $batch->sourceSystem(),
            'profileName' => $batch->profile_id !== null ? ImportProfile::query()->whereKey($batch->profile_id)->value('name') : null,
            'error' => $batch->options['simulation_error'] ?? null,
        ]);
    }

    public function saveMapping(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);
        if (! $this->editable($batch)) {
            return $this->toShow($batch);
        }

        $input = $request->validate([
            'map' => ['required', 'array'],
            'map.*' => ['nullable', 'string', 'max:100'],
            'source_system' => ['nullable', 'string', 'max:50'],
            'profile_name' => ['nullable', 'string', 'max:100'],
        ], ['map.required' => 'Associez au moins une colonne du fichier.']);

        $headers = $batch->reader()->headers();
        $given = [];
        foreach ($input['map'] as $column => $field) {
            if (! in_array((string) $column, $headers, true)) {
                throw ValidationException::withMessages(['map' => 'La colonne « '.$column.' » n\'existe pas dans le fichier.']);
            }
            $given[(string) $column] = $field;
        }
        try {
            $map = FieldMap::fromArray($batch->target, $given);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['map' => $e->getMessage()]);
        }

        $source = trim((string) ($input['source_system'] ?? ''));
        foreach ($batch->target->blockingProblems($map, $source === '' ? null : mb_substr($source, 0, 50)) as $problem) {
            throw ValidationException::withMessages([str_contains($problem, 'logiciel d\'origine') ? 'source_system' : 'map' => $problem]);
        }

        $options = $batch->options;
        unset($options['source_system']);
        if ($source !== '') {
            $options['source_system'] = mb_substr($source, 0, 50);
        }
        $options['values_reviewed'] = false;
        $batch->options = $options;

        $profileName = trim((string) ($input['profile_name'] ?? ''));
        $this->checkProfileName($batch, $profileName, $this->user($request));
        $this->startSimulation($batch, $map, $batch->valueMapper());
        if ($profileName !== '') {
            $this->saveProfile($batch, $profileName);
        }

        return to_route('crm.import.show', $batch->id);
    }

    // ------------------------------------------------------------ 3. values

    public function values(Request $request, string $batch): Response|RedirectResponse
    {
        $batch = $this->find($request, $batch);
        if (! $this->editable($batch) || $batch->field_map === null) {
            return $this->toShow($batch);
        }

        $chosen = $batch->value_map ?? [];
        $unknown = $batch->simulation['unknown_values'] ?? [];
        $blocks = [];
        foreach ($batch->target->mappableFields() as $field) {
            $values = array_values(array_unique([...($unknown[$field] ?? []), ...array_keys($chosen[$field] ?? [])]));
            if ($values === []) {
                continue;
            }
            $kind = $this->kind($batch->target, $field);
            $blocks[] = [
                'field' => $field,
                'label' => $batch->target->fields()[$field] ?? $field,
                'kind' => $kind,
                'canCreate' => $kind === 'reference',
                'options' => $this->options($batch->target, $field),
                'values' => array_slice(array_map('strval', $values), 0, self::MAX_VALUES),
                'more' => max(0, count($values) - self::MAX_VALUES),
                'choices' => (object) ($chosen[$field] ?? []),
            ];
        }

        return Inertia::render('crm/import/values', [
            'batch' => $this->summary($batch),
            'blocks' => $blocks,
            'profileName' => $batch->profile_id !== null ? ImportProfile::query()->whereKey($batch->profile_id)->value('name') : null,
        ]);
    }

    public function saveValues(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);
        if (! $this->editable($batch) || $batch->field_map === null) {
            return $this->toShow($batch);
        }

        $request->validate(['values' => ['present', 'array'], 'profile_name' => ['nullable', 'string', 'max:100']]);
        $submitted = $request->input('values');
        $mappable = $batch->target->mappableFields();
        $merged = $batch->value_map ?? [];

        foreach ($submitted as $field => $choices) {
            if (! in_array($field, $mappable, true) || ! is_array($choices)) {
                throw ValidationException::withMessages(['values' => 'Champ inconnu dans les correspondances de valeurs.']);
            }
            $allowed = array_column($this->options($batch->target, (string) $field), 'value');
            $clean = [];
            foreach ($choices as $value => $action) {
                if ($action === null || $action === '') {
                    continue;
                }
                $isCreate = $action === ValueMapper::CREATE && $this->kind($batch->target, (string) $field) === 'reference';
                if (! is_string($action) || ! ($isCreate || $action === ValueMapper::EMPTY || in_array($action, $allowed, true))) {
                    throw ValidationException::withMessages(['values' => 'Choix invalide pour la valeur « '.$value.' ».']);
                }
                $clean[(string) $value] = $action;
            }
            $merged[(string) $field] = $clean;
        }

        $options = $batch->options;
        $options['values_reviewed'] = true;
        $batch->options = $options;
        $profileName = trim((string) $request->input('profile_name', ''));
        $this->checkProfileName($batch, $profileName, $this->user($request));
        $this->startSimulation($batch, $batch->fieldMap(), ValueMapper::fromArray($merged));
        if ($profileName !== '') {
            $this->saveProfile($batch, $profileName);
        }

        return to_route('crm.import.show', $batch->id);
    }

    // ------------------------------------------------------ the batch's page

    /**
     * The page of the batch's current step.
     */
    public function show(Request $request, string $batch): Response|RedirectResponse
    {
        $batch = $this->find($request, $batch);

        return match ($batch->status) {
            ImportBatch::SIMULATING, ImportBatch::RUNNING, ImportBatch::UNDOING => $this->progress($batch),
            ImportBatch::DONE, ImportBatch::FAILED, ImportBatch::UNDONE => $this->reportPage($batch),
            ImportBatch::SIMULATED => ($batch->simulation['unknown_values'] ?? []) !== [] && empty($batch->options['values_reviewed'])
                ? to_route('crm.import.values', $batch->id)
                : $this->simulationPage($batch),
            default => $batch->simulation !== null ? $this->simulationPage($batch) : to_route('crm.import.mapping', $batch->id),
        };
    }

    public function status(Request $request, string $batch): JsonResponse
    {
        $batch = $this->find($request, $batch);

        return response()->json($this->progressData($batch))->header('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------------- 4. simulation

    public function saveStrategy(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);
        if (! $this->editable($batch) || $batch->field_map === null) {
            return $this->toShow($batch);
        }

        $input = $request->validate(['strategy' => ['required', Rule::enum(DuplicateStrategy::class)]],
            ['strategy.required' => 'Choisissez que faire des doublons.', 'strategy.enum' => 'Choisissez que faire des doublons.']);

        $options = $batch->options;
        $options['strategy'] = $input['strategy'];
        $batch->options = $options;
        $this->startSimulation($batch, $batch->fieldMap(), $batch->valueMapper());

        return to_route('crm.import.show', $batch->id);
    }

    // ------------------------------------------------------------- 5. the run

    public function run(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);

        // Claimed atomically: a double click starts one run.
        $claimed = ImportBatch::query()->whereKey($batch->id)->where('status', ImportBatch::SIMULATED)
            ->update(['status' => ImportBatch::RUNNING, 'updated_at' => now()]);
        if ($claimed === 1) {
            $batch->refresh()->start();
            RunImport::dispatch($batch->id);
        }

        return to_route('crm.import.show', $batch->id);
    }

    public function fail(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);

        if (! $batch->markFailed($this->user($request))) {
            throw ValidationException::withMessages(['batch' => 'Cet import progresse encore. Il peut être marqué comme échoué après '.ImportBatch::STUCK_MINUTES.' minutes sans avancement.']);
        }
        if ($batch->status === ImportBatch::UPLOADED) {
            return to_route('crm.import.show', $batch->id);
        }

        return to_route('crm.import.show', $batch->id)->with('status', 'L\'import est marqué comme échoué. Vous pouvez annuler ce qu\'il a créé.');
    }

    // --------------------------------------------------------------- the undo

    public function undoPreview(Request $request, string $batch): JsonResponse
    {
        return response()->json(UndoPreview::for($this->find($request, $batch)))->header('Cache-Control', 'no-store, private');
    }

    public function undo(Request $request, string $batch): RedirectResponse
    {
        $batch = $this->find($request, $batch);
        $user = $this->user($request);

        if (! $batch->canUndo()) {
            throw ValidationException::withMessages(['batch' => 'Cet import ne peut pas être annulé dans son état actuel.']);
        }
        $claimed = ImportBatch::query()->whereKey($batch->id)->whereIn('status', [ImportBatch::DONE, ImportBatch::FAILED])
            ->update(['status' => ImportBatch::UNDOING, 'updated_at' => now()]);
        if ($claimed === 1) {
            $batch->refresh()->startUndo();
            UndoImport::dispatch($batch->id, $user->id);
        }

        return to_route('crm.import.show', $batch->id);
    }

    // ------------------------------------------------------------- 6. report

    public function report(Request $request, string $batch): HttpResponse
    {
        $batch = $this->find($request, $batch);
        $disk = Storage::disk(TenantStorage::DISK);

        if (! in_array($batch->status, [ImportBatch::DONE, ImportBatch::FAILED, ImportBatch::UNDOING, ImportBatch::UNDONE], true)
            || $batch->report_path === null || ! $disk->exists($batch->report_path)) {
            abort(404);
        }

        $name = Str::slug(pathinfo($batch->original_name, PATHINFO_FILENAME)) ?: 'import';

        return $disk->download($batch->report_path, 'rapport-import-'.$name.'.xlsx');
    }

    // ------------------------------------------------------------------- pages

    private function simulationPage(ImportBatch $batch): Response
    {
        $simulation = $batch->simulation ?? [];
        $unknown = array_sum(array_map('count', $simulation['unknown_values'] ?? []));
        unset($simulation['unknown_values']);

        return Inertia::render('crm/import/simulation', [
            'batch' => $this->summary($batch),
            'simulation' => $simulation + ['error_rows' => [], 'other_rows' => [], 'blocking' => [], 'warnings' => []],
            'unknownCount' => $unknown,
            'hasValues' => $batch->target->mappableFields() !== [],
            'strategy' => [
                'value' => $batch->strategy()->value,
                'options' => array_map(fn (DuplicateStrategy $s) => ['value' => $s->value, 'label' => $s->label(), 'description' => self::strategyHelp($s)], DuplicateStrategy::cases()),
            ],
            'canRun' => $batch->canRun() && ($simulation['blocking'] ?? []) === [],
            'sourceSystem' => $batch->sourceSystem(),
        ]);
    }

    private function progress(ImportBatch $batch): Response
    {
        return Inertia::render('crm/import/progress', [
            'batch' => $this->summary($batch),
            ...$this->progressData($batch),
        ]);
    }

    private function reportPage(ImportBatch $batch): Response
    {
        $rows = $batch->rows()->whereIn('status', [ImportRow::ERROR, ImportRow::SKIPPED])->orderBy('line')->limit(100)
            ->get(['line', 'status', 'messages'])
            ->map(fn (ImportRow $r) => ['line' => $r->line, 'status' => $r->status, 'messages' => array_map(fn (array $m) => $m['text'], $r->messages)])->all();

        return Inertia::render('crm/import/report', [
            'batch' => $this->summary($batch),
            'rows' => $rows,
            'rowsTotal' => $batch->errors + $batch->skipped,
            'canUndo' => $this->canUndo($batch),
            'hasReport' => $batch->report_path !== null && Storage::disk(TenantStorage::DISK)->exists($batch->report_path),
            'flash' => session('status'),
        ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function progressData(ImportBatch $batch): array
    {
        $phase = match ($batch->status) {
            ImportBatch::SIMULATING => 'simulation',
            ImportBatch::RUNNING => 'run',
            ImportBatch::UNDOING => 'undo',
            default => null,
        };
        $processed = match ($phase) {
            'simulation' => $batch->cursor_line,
            'run' => $batch->total,
            'undo' => (int) ($batch->options['undo']['removed'] ?? 0) + (int) ($batch->options['undo']['kept'] ?? 0),
            default => $batch->total,
        };
        $expected = $phase === 'run' ? (int) ($batch->simulation['total'] ?? 0) : null;
        $stuck = $batch->isStuck();

        return [
            'status' => $batch->status,
            'label' => $this->statusOf($batch)['label'],
            'phase' => $phase,
            'processed' => $processed,
            'total' => $expected,
            'percent' => $expected !== null && $expected > 0 ? min(100, (int) floor($processed * 100 / $expected)) : null,
            'stuck' => $stuck,
            'canFail' => $stuck,
            'finished' => $phase === null,
            'failure' => $batch->options['failure'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'original_name' => $batch->original_name,
            'target' => ['value' => $batch->target->value, 'label' => $batch->target->label()],
            'author' => $batch->user?->name,
            'status' => $this->statusOf($batch),
            'total' => $batch->total,
            'created' => $batch->created,
            'updated' => $batch->updated,
            'skipped' => $batch->skipped,
            'errors' => $batch->errors,
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'undone_at' => $batch->undone_at?->toIso8601String(),
            'created_at' => $batch->created_at->toIso8601String(),
            'failure' => $batch->options['failure'] ?? null,
            'undo' => isset($batch->options['undo']) ? ['removed' => (int) $batch->options['undo']['removed'], 'kept' => (int) $batch->options['undo']['kept']] : null,
            'strategy' => $batch->strategy()->label(),
            'source_system' => $batch->sourceSystem(),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function statusOf(ImportBatch $batch): array
    {
        return ['value' => $batch->status, 'label' => $batch->isStuck() ? 'Bloqué' : self::STATUS_LABELS[$batch->status]];
    }

    private function canUndo(ImportBatch $batch): bool
    {
        return $batch->canUndo() && $batch->created > 0;
    }

    private function readInfo(Readers\TabularReader $reader): string
    {
        if (! $reader instanceof CsvReader) {
            return 'Classeur Excel : première feuille.';
        }

        return sprintf('Fichier lu en %s, séparateur « %s ».', $reader->encoding(), $reader->delimiter() === "\t" ? 'tabulation' : $reader->delimiter());
    }

    private static function strategyHelp(DuplicateStrategy $strategy): string
    {
        return match ($strategy) {
            DuplicateStrategy::Skip => 'Si la fiche existe déjà (même SIRET, e-mail, téléphone ou nom au même endroit), la ligne est ignorée : la fiche existante n\'est pas touchée.',
            DuplicateStrategy::Merge => 'Si la fiche existe déjà de façon certaine, seules ses informations vides sont complétées avec celles du fichier. Les ressemblances moins sûres sont ignorées.',
            DuplicateStrategy::Create => 'Une nouvelle fiche est créée même si une fiche semblable existe (impossible quand le SIRET est déjà utilisé).',
        };
    }

    private function kind(ImportTarget $target, string $field): string
    {
        return str_ends_with($field, 'status') ? 'status' : ($field === 'responsible' ? 'responsible' : 'reference');
    }

    /**
     * What a file value of this field can be mapped to: [{value, label}].
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(ImportTarget $target, string $field): array
    {
        return match ($this->kind($target, $field)) {
            'status' => OrganisationStatus::options(),
            'responsible' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            default => isset($target->referenceFields()[$field])
                ? ReferenceItem::query()->where('list', $target->referenceFields()[$field]->value)->where('is_active', true)->orderBy('position')->get(['id', 'label'])
                    ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->all()
                : [],
        };
    }

    /**
     * Records the choices and queues the simulation (the caller set the options).
     */
    private function startSimulation(ImportBatch $batch, FieldMap $map, ValueMapper $values): void
    {
        if (! $batch->startSimulation($map, $values)) {
            throw new HttpResponseException(to_route('crm.import.show', $batch->id)->withErrors(['batch' => self::ALREADY_RUN]));
        }
        SimulateImport::dispatch($batch->id);
    }

    /**
     * A profile name is unique in the organisme: saving under an existing
     * name overwrites that profile, which only makes sense for the same type
     * of data, and only its author or an administrator (users.manage) may do it.
     *
     * @throws ValidationException
     */
    private function checkProfileName(ImportBatch $batch, string $name, User $user): void
    {
        if ($name === '') {
            return;
        }
        $existing = ImportProfile::query()->where('name', $name)->first();
        if ($existing === null) {
            return;
        }
        if ($existing->target !== $batch->target) {
            throw ValidationException::withMessages(['profile_name' => 'Un profil « '.$name.' » existe déjà pour un autre type de données : choisissez un autre nom.']);
        }
        if ($existing->created_by !== $user->id && ! $user->can(CorePermissions::USERS_MANAGE)) {
            throw ValidationException::withMessages(['profile_name' => 'Le profil « '.$name.' » a été créé par un autre utilisateur : choisissez un autre nom.']);
        }
    }

    /** Called after checkProfileName(). */
    private function saveProfile(ImportBatch $batch, string $name): void
    {
        $batch->refresh();
        $options = array_filter(['strategy' => $batch->strategy()->value, 'source_system' => $batch->sourceSystem()], fn ($v) => $v !== null);
        $profile = ImportProfile::query()->where('target', $batch->target)->firstOrNew(['name' => $name]);
        $profile->fill(['target' => $batch->target, 'field_map' => $batch->field_map ?? [], 'value_map' => $batch->value_map ?? [], 'options' => $options])->save();
    }

    private function editable(ImportBatch $batch): bool
    {
        return in_array($batch->status, [ImportBatch::UPLOADED, ImportBatch::SIMULATED], true);
    }

    private function toShow(ImportBatch $batch): RedirectResponse
    {
        return to_route('crm.import.show', $batch->id);
    }

    /**
     * @return Builder<ImportBatch>
     */
    private function visible(User $user): Builder
    {
        $query = ImportBatch::query();

        return $user->can(CorePermissions::USERS_MANAGE) ? $query : $query->where('user_id', $user->id);
    }

    private function find(Request $request, string $id): ImportBatch
    {
        return $this->visible($this->user($request))->with('user:id,name')->findOrFail($id);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
