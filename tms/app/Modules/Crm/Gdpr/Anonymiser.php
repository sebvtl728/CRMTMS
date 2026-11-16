<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Activities\Events\ActivityChanged;
use App\Modules\Crm\Brochures\BrochureSendingAnonymisation;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Crm\Quotes\QuoteAnonymisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Irreversible anonymisation of a person (GDPR). In one transaction:
 * - the person: every personal field cleared, civility too, last name
 *   « Anonyme »; id, status, type, references, DAS, dates and links kept
 *   (statistics); the generated search / duplicate keys follow;
 * - her links (trashed ones too): work e-mail, work phone and job title;
 * - her trainings of interest: rows deleted;
 * - her notes and attachments (trashed too): rows deleted (files after the
 *   commit); notes on her entreprises stay;
 * - her consents: kept as history, their free text cleared;
 * - her opportunities as particulier (trashed too): kept with amounts,
 *   stages and history (statistics), the loss comment cleared; her rows as
 *   contact of an opportunity deleted;
 * - her quotes (as particulier or interlocuteur; QuoteAnonymisation): kept
 *   with numbers, lines, totals and statuses; recipient copy, mentions,
 *   refusal reason and last e-mail cleared, archived PDFs deleted (files
 *   after the commit); her address leaves the e-mails of other quotes;
 * - the brochures sent to her (BrochureSendingAnonymisation): her address,
 *   the subject and the message cleared, the sending itself kept;
 * - her actions (hers, or on her opportunities as particulier; trashed
 *   too): kept with type, dates and result, the report and place cleared,
 *   the subject (« Appel – <her name> ») reset to the type;
 * - imports: raw values (import_rows.source) of every row that touched her,
 *   her notes or links (or a fiche merged into her), and of every row whose
 *   values name her (failed rows: same e-mail, phone, or first + last name);
 *   the uploaded files of those batches, and of any other batch whose file
 *   names her, are deleted with their simulation lines. Counters, messages,
 *   report and undo information stay: the batch can still be undone;
 * - what other modules hold on her (Crm\Public\FicheLinks::anonymise;
 *   Sessions: adaptation needs and notes of her enrolments, notes of the
 *   fundings she pays or that target her enrolments, notes of her dossiers
 *   as particulier);
 * - her « À anonymiser » entry.
 * Journal: gdpr.anonymised (reason and counts, no value). The journal holds
 * no personal value of hers (masked from the start).
 */
class Anonymiser
{
    public const NAME = 'Anonyme';

    public const REASONS = [
        'retention' => 'Durée de conservation dépassée',
        'request' => 'Demande de la personne',
        'manual' => 'Anonymisation manuelle',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws AnonymisationPostponed while an import is active in the organisme
     */
    public function anonymise(Person $person, string $reason): void
    {
        $this->anonymiseAll([$person], $reason);
    }

    /**
     * Same, for several people: the uploaded files are read once for all.
     *
     * @param  iterable<Person>  $people
     * @return int number of people anonymised
     *
     * @throws AnonymisationPostponed while an import is active in the organisme
     */
    public function anonymiseAll(iterable $people, string $reason): int
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new InvalidArgumentException("Motif d'anonymisation inconnu : {$reason}.");
        }

        $targets = [];
        foreach ($people as $person) {
            $fresh = Person::withTrashed()->find($person->getKey());
            if ($fresh !== null && $fresh->anonymised_at === null && $fresh->merged_into_id === null) {
                $targets[$fresh->id] = $fresh;
            }
        }
        if ($targets === []) {
            return 0;
        }

        self::ensureNoActiveImport();

        $traces = array_map(fn (Person $p) => PersonTraces::of($p), $targets);
        $namedIn = $this->filesNaming($traces);

        $done = 0;
        foreach ($targets as $id => $person) {
            $files = DB::transaction(fn () => $this->scrub($person, $traces[$id], $namedIn[$id] ?? [], $reason));
            if ($files !== null) {
                $this->deleteFiles($files);
                $done++;
            }
        }

        return $done;
    }

    /**
     * @param  list<string>  $namedInBatches  batches whose uploaded file names her
     * @return list<string>|null files to delete after the commit, null when there was nothing to do
     */
    private function scrub(Person $person, PersonTraces $traces, array $namedInBatches, string $reason): ?array
    {
        $locked = Person::withTrashed()->whereKey($person->id)->lockForUpdate()->first();
        if ($locked === null || $locked->anonymised_at !== null || $locked->merged_into_id !== null) {
            return null;
        }
        self::ensureNoActiveImport();

        $morph = $locked->getMorphClass();
        $merged = $this->mergedInto($locked);
        $subjects = [$locked->id, ...$merged];

        $notes = DB::table('notes')->where('notable_type', $morph)->whereIn('notable_id', $subjects)->pluck('id')->all();
        $attachments = DB::table('attachments')->where('notable_type', $morph)->whereIn('notable_id', $subjects)->get(['id', 'path']);
        $links = DB::table('affiliations')->whereIn('person_id', $subjects)->pluck('id')->all();
        $refs = array_values(array_unique([...$subjects, ...$notes, ...$links, ...$attachments->pluck('id')->all()]));

        // Import rows that touched her (by id), then rows whose raw values name her.
        $batches = DB::table('import_stamps')->whereIn('subject_id', $refs)->distinct()->pluck('batch_id')->all();
        if ($batches !== []) {
            DB::table('import_rows')->whereIn('batch_id', $batches)->whereNotNull('source')
                ->where(fn ($q) => $q->whereIn('subject_id', $refs)
                    ->orWhereRaw("exists (select 1 from jsonb_array_elements(coalesce(created_refs, '[]'::jsonb) || coalesce(touched_refs, '[]'::jsonb)) r where r->>'id' in (select jsonb_array_elements_text(?::jsonb)))", [json_encode($refs)]))
                ->update(['source' => null]);
        }
        [$condition, $bindings] = $traces->sql('import_rows.source');
        $named = DB::table('import_rows')->whereNotNull('source')->whereRaw($condition, $bindings);
        $batches = array_values(array_unique([...$batches, ...$named->clone()->distinct()->pluck('batch_id')->all(), ...$namedInBatches]));
        $named->update(['source' => null]);

        // Their uploaded files and simulation lines.
        $files = $attachments->pluck('path')->filter()->values()->all();
        foreach (ImportBatch::query()->whereIn('id', $batches)->get(['id', 'stored_path', 'simulation']) as $batch) {
            if ($batch->stored_path !== null) {
                $files[] = $batch->stored_path;
            }
            DB::table('import_batches')->where('id', $batch->id)->update([
                'stored_path' => null,
                'simulation' => $batch->simulation === null ? null : json_encode(self::withoutLines($batch->simulation), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        DB::table('notes')->whereIn('id', $notes)->delete();
        DB::table('attachments')->whereIn('id', $attachments->pluck('id')->all())->delete();
        DB::table('consents')->whereIn('person_id', $subjects)->whereNotNull('source')->update(['source' => null]);
        DB::table('affiliations')->whereIn('id', $links)->update(['work_email' => null, 'work_phone' => null, 'job_title' => null, 'updated_at' => now()]);
        DB::table('gdpr_pending')->where('person_id', $locked->id)->delete();
        DB::table('opportunities')->whereIn('person_id', $subjects)->whereNotNull('loss_comment')->update(['loss_comment' => null, 'updated_at' => now()]);
        DB::table('opportunity_contacts')->whereIn('person_id', $subjects)->delete();
        FicheTrainings::forgetPeople($subjects);
        $quotes = QuoteAnonymisation::scrub($subjects, $traces->emails);
        $files = [...$files, ...$quotes['files']];
        BrochureSendingAnonymisation::scrub($subjects);
        // Personal values held by other modules (Sessions: adaptation needs, notes).
        FicheLinks::anonymise($subjects);
        $activities = DB::table('activities')
            ->where(fn ($q) => $q->whereIn('person_id', $subjects)
                ->orWhereIn('opportunity_id', DB::table('opportunities')->select('id')->whereIn('person_id', $subjects)))
            ->pluck('responsible_user_id', 'id');
        DB::table('activities')->whereIn('id', $activities->keys()->all())
            ->update([
                'purpose' => null,
                'report' => null,
                'next_steps' => null,
                'location' => null,
                'subject' => DB::raw('(select label from reference_items where reference_items.id = activities.type_id)'),
                'updated_at' => now(),
            ]);

        DB::table('people')->where('id', $locked->id)->update([
            ...array_fill_keys(Person::PERSONAL_FIELDS, null),
            'last_name' => self::NAME,
            'civility' => null,
            'gdpr_kept_until' => null,
            'gdpr_kept_reason' => null,
            'anonymised_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record('gdpr.anonymised', $locked, [], [
            'reason' => $reason,
            'notes' => count($notes),
            'attachments' => $attachments->count(),
            'quotes' => $quotes['quotes'],
        ]);

        // Calendars it was pushed to drop the name and place (after commit).
        foreach ($activities as $activityId => $responsibleId) {
            ActivityChanged::dispatch((string) $activityId, is_string($responsibleId) ? $responsibleId : null);
        }

        return $files;
    }

    /**
     * Ids of the fiches merged into $person, directly or not (already
     * scrubbed by the merge; their import rows may still hold her values).
     *
     * @return list<string>
     */
    private function mergedInto(Person $person): array
    {
        $ids = array_map(fn (array $s) => $s['id'], $person->merged_sources ?? []);
        $direct = Person::withTrashed()->where('merged_into_id', $person->id)->pluck('id')->all();

        return array_values(array_unique([...$ids, ...$direct]));
    }

    /**
     * Batches whose uploaded file (kept 30 days) names one of the people.
     *
     * @param  array<string, PersonTraces>  $traces
     * @return array<string, list<string>> person id => batch ids
     */
    private function filesNaming(array $traces): array
    {
        $traces = array_filter($traces, fn (PersonTraces $t) => ! $t->isEmpty());
        if ($traces === []) {
            return [];
        }

        $found = [];
        foreach (ImportBatch::query()->whereNotNull('stored_path')->get() as $batch) {
            try {
                foreach ($batch->reader()->rows() as $cells) {
                    foreach ($traces as $id => $trace) {
                        if ($trace->matches($cells)) {
                            $found[$id][$batch->id] = $batch->id;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Unreadable file: it cannot be imported either; the purge removes it.
                Log::warning('Fichier d\'import illisible pendant l\'anonymisation', ['batch_id' => $batch->id, 'exception' => $e->getMessage()]);
            }
        }

        return array_map('array_values', $found);
    }

    /**
     * @param  array<string, mixed>  $simulation
     * @return array<string, mixed>
     */
    public static function withoutLines(array $simulation): array
    {
        unset($simulation['error_rows'], $simulation['other_rows']);

        return $simulation;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        $disk = Storage::disk(TenantStorage::DISK);
        foreach (array_unique($paths) as $path) {
            if (! $disk->delete($path) && $disk->exists($path)) {
                Log::error('Fichier non supprimé après anonymisation', ['path' => $path]);
            }
        }
    }

    private static function ensureNoActiveImport(): void
    {
        // A stuck batch (no progress for STUCK_MINUTES: dead worker) blocks nothing.
        if (ImportBatch::query()->whereIn('status', [ImportBatch::RUNNING, ImportBatch::SIMULATING, ImportBatch::UNDOING])
            ->where('updated_at', '>', now()->subMinutes(ImportBatch::STUCK_MINUTES))->exists()) {
            throw new AnonymisationPostponed;
        }
    }
}
