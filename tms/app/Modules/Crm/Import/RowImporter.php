<?php

namespace App\Modules\Crm\Import;

use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Affiliations\AffiliationRequest;
use App\Modules\Crm\Duplicates\DuplicateCandidate;
use App\Modules\Crm\Duplicates\DuplicateFinder;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationRequest;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PersonRequest;
use App\Modules\Crm\Support\DasLinks;
use App\Modules\Crm\Timeline\Note;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports one file row (or predicts the outcome, in a dry run) through the
 * same domain code as the screens: the validation rules of the requests,
 * the models (audit with personal data masked, phone normalisation, search
 * column, last contact), the duplicate finder and the DAS links.
 *
 * Order of resolution of a fiche: the source identifier (source_system +
 * source_id, following merges to the kept fiche, restoring a trashed one)
 * -> update; else a duplicate (DuplicateFinder) -> batch strategy; else
 * creation. Reference values come from preloaded maps (ValueResolver);
 * source identifiers are looked up once per chunk (prefetch()).
 */
final class RowImporter
{
    /** Fields whose stored column differs from the field code. */
    private const COLUMNS = [
        'legal_form' => 'legal_form_id', 'sector' => 'sector_id', 'establishment_type' => 'establishment_type_id',
        'company_size' => 'company_size_id', 'origin' => 'origin_id', 'responsible' => 'responsible_user_id',
        'professional_situation' => 'professional_situation_id', 'funding_mode' => 'funding_mode_id', 'decision_role' => 'decision_role_id',
    ];

    private const ORGANISATION_FIELDS = [
        'name', 'siret', 'naf_code', 'legal_form_id', 'sector_id', 'establishment_type_id', 'company_size_id', 'origin_id', 'status',
        'responsible_user_id', 'email', 'phone', 'website', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'notes_summary',
    ];

    private const PERSON_FIELDS = [
        'civility', 'first_name', 'last_name', 'email', 'email_secondary', 'phone', 'mobile', 'address_line1', 'address_line2', 'postal_code',
        'city', 'country', 'birth_date', 'is_individual', 'project', 'professional_situation_id', 'funding_mode_id', 'origin_id', 'status', 'responsible_user_id',
    ];

    private const LINK_FIELDS = ['job_title', 'decision_role_id', 'work_email', 'work_phone', 'is_referent', 'started_on'];

    /** @var array<string, array<string, string>> type => source_id => fiche id (chunk cache) */
    private array $sources = ['organisation' => [], 'person' => []];

    /** @var array<string, int> dry run: identifying key => line that would create the fiche */
    private array $seen = [];

    /** @var array<string, array<string, string>> dry run: type => source_id => line */
    private array $seenSources = ['organisation' => [], 'person' => []];

    /** @var array<string, array<string, Organisation|Person>> type => id => fiche (chunk cache) */
    private array $fiches = ['organisation' => [], 'person' => []];

    private readonly ImportStamps $stamps;

    private readonly DuplicateStrategy $strategy;

    private readonly ?string $sourceSystem;

    public function __construct(
        private readonly ImportBatch $batch,
        private readonly FieldMap $map,
        private readonly ValueResolver $values,
        private readonly bool $dryRun,
        private readonly DuplicateFinder $finder,
        private readonly DasLinks $das,
    ) {
        $this->strategy = $batch->strategy();
        $this->stamps = new ImportStamps;
        $this->sourceSystem = $batch->sourceSystem();
    }

    /**
     * Problems that concern the whole file (blocking: no row can be imported).
     *
     * @return list<string>
     */
    public function blockingErrors(): array
    {
        $errors = [];
        $required = match ($this->batch->target) {
            ImportTarget::Organisations => ['name'],
            ImportTarget::People, ImportTarget::Affiliations => ['last_name'],
            ImportTarget::Notes => ['body'],
        };
        foreach ($required as $field) {
            if (! $this->map->has($field)) {
                $errors[] = 'Associez une colonne au champ obligatoire « '.$this->batch->target->fields()[$field].' ».';
            }
        }
        if ($this->batch->target === ImportTarget::Notes && ! array_filter(['subject_siret', 'subject_email', 'subject_source_id'], fn ($f) => $this->map->has($f))) {
            $errors[] = 'Associez une colonne permettant de retrouver la fiche : SIRET, e-mail ou identifiant source.';
        }
        $usesSource = array_filter(['source_id', 'organisation.source_id', 'subject_source_id'], fn ($f) => $this->map->has($f));
        if ($usesSource !== [] && $this->sourceSystem === null) {
            $errors[] = 'Indiquez le logiciel d\'origine des identifiants (ex. : Formevol) pour pouvoir réimporter sans doublon.';
        }

        return $errors;
    }

    /**
     * Looks up, in one query per type, the fiches already holding the source
     * identifiers of a chunk of rows.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    public function prefetch(array $rows): void
    {
        if ($this->sourceSystem === null) {
            return;
        }

        $this->sources = ['organisation' => [], 'person' => []];
        $this->fiches = ['organisation' => [], 'person' => []];
        $wanted = ['organisation' => [], 'person' => []];
        foreach ($rows as $row) {
            $values = $this->map->values($row);
            foreach (['source_id' => $this->batch->target === ImportTarget::Organisations ? 'organisation' : 'person',
                'organisation.source_id' => 'organisation', 'subject_source_id' => 'both'] as $field => $type) {
                $id = trim($values[$field] ?? '');
                if ($id === '') {
                    continue;
                }
                foreach ($type === 'both' ? ['organisation', 'person'] : [$type] as $t) {
                    $wanted[$t][] = $id;
                }
            }
        }

        foreach (['organisation' => Organisation::class, 'person' => Person::class] as $type => $class) {
            $ids = array_values(array_unique($wanted[$type]));
            if ($ids === []) {
                continue;
            }
            $class::withTrashed()->where('source_system', $this->sourceSystem)->whereIn('source_id', $ids)
                ->get(['id', 'source_id'])
                ->each(function (Model $m) use ($type): void {
                    $this->sources[$type][(string) $m->getAttribute('source_id')] = (string) $m->getKey();
                });

            // A fiche merged into another and then purged: its identifiers live on in merged_sources.
            $missing = array_values(array_diff($ids, array_keys($this->sources[$type])));
            if ($missing !== []) {
                $table = (new $class)->getTable();
                $placeholders = implode(',', array_fill(0, count($missing), '?'));
                foreach (DB::select("select {$table}.id, elem->>'source_id' as source_id from {$table}, jsonb_array_elements({$table}.merged_sources) elem
                    where {$table}.merged_sources <> '[]'::jsonb and elem->>'source_system' = ? and elem->>'source_id' in ({$placeholders})", [$this->sourceSystem, ...$missing]) as $hit) {
                    $this->sources[$type][(string) $hit->source_id] ??= (string) $hit->id;
                }
            }
        }

        // The fiches themselves and what the imports left on them: one query each.
        foreach (['organisation' => Organisation::class, 'person' => Person::class] as $type => $class) {
            $ids = array_values(array_unique($this->sources[$type]));
            if ($ids !== []) {
                foreach ($class::withTrashed()->whereIn('id', $ids)->get() as $fiche) {
                    $this->fiches[$type][(string) $fiche->getKey()] = $fiche;
                }
            }
        }
        $this->stamps->prefetch(array_map(fn (array $fiches) => array_keys($fiches), $this->fiches));
    }

    /**
     * @param  array<string, string>  $row  column => raw value
     * @param  bool  $isolated  run in its own savepoint (a failing row is just
     *                          an error); otherwise any failure throws
     *                          RetryChunk and the caller replays the chunk isolated
     */
    public function import(int $line, array $row, bool $isolated = true): RowOutcome
    {
        [$values, $errors, $warnings] = $this->read($row);

        if ($errors !== []) {
            return RowOutcome::error(...$errors);
        }

        $run = fn (): RowOutcome => match ($this->batch->target) {
            ImportTarget::Organisations => $this->organisationRow($line, $values),
            ImportTarget::People => $this->personRow($line, $values),
            ImportTarget::Affiliations => $this->affiliationRow($line, $values),
            ImportTarget::Notes => $this->noteRow($values),
        };

        // In a replayable run (no savepoint per row), any failure goes up to RunImport.
        try {
            // Savepoint: a failing row never aborts the chunk.
            $outcome = $this->dryRun || ! $isolated ? $run() : DB::transaction($run);
        } catch (ImportRowException|QueryException $e) {
            if ($this->replayable($isolated)) {
                throw new RetryChunk(previous: $e);
            }
            $outcome = match (true) {
                $e instanceof SkipRow => (new RowOutcome(ImportRow::SKIPPED))->add('info', $e->getMessage()),
                $e instanceof ImportRowException => RowOutcome::error($e->getMessage()),
                $e instanceof UniqueConstraintViolationException => RowOutcome::error(self::conflict($e)),
                default => throw $e,
            };
        }

        foreach ($warnings as $warning) {
            $outcome->add('warning', $warning);
        }

        return $outcome;
    }

    /**
     * A real run without savepoint per row: failures go up to RunImport.
     */
    private function replayable(bool $isolated): bool
    {
        return ! $this->dryRun && ! $isolated;
    }

    // ------------------------------------------------------------------
    // Reading and validation
    // ------------------------------------------------------------------

    /**
     * Typed values of the row (field => value), errors and warnings.
     *
     * @param  array<string, string>  $row
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>}
     */
    private function read(array $row): array
    {
        $labels = $this->batch->target->fields();
        $mappable = $this->batch->target->mappableFields();
        $values = [];
        $errors = [];
        $warnings = [];

        foreach ($this->map->values($row) as $field => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $label = $labels[$field];
            $base = str_starts_with($field, 'organisation.') ? substr($field, 13) : $field;

            if (in_array($base, ['siret', 'subject_siret'], true)) {
                $siret = Values::siret($raw);
                if ($siret['error'] !== null) {
                    $errors[] = "{$label} : {$siret['error']}";
                } else {
                    $values[$field] = $siret['value'];
                    if ($siret['warning'] !== null) {
                        $warnings[] = $siret['warning'];
                    }
                }
            } elseif (in_array($base, ['birth_date', 'started_on'], true)) {
                $date = Values::date($raw);
                $date === null ? $errors[] = "{$label} : date invalide (format attendu JJ/MM/AAAA)." : $values[$field] = $date->toDateString();
            } elseif ($base === 'date') {
                $date = Values::date($raw, true);
                $date === null ? $errors[] = "{$label} : date invalide (format attendu JJ/MM/AAAA)." : $values[$field] = $date;
            } elseif (in_array($base, ['is_individual', 'is_referent'], true)) {
                $bool = Values::bool($raw);
                $bool === null ? $errors[] = "{$label} : répondez par Oui ou Non." : $values[$field] = $bool;
            } elseif ($base === 'civility') {
                $civility = Values::civility($raw);
                $civility === null ? $errors[] = "{$label} : choisissez M. ou Mme." : $values[$field] = $civility;
            } elseif ($base === 'country') {
                $values[$field] = Values::country($raw);
            } elseif ($base === 'das') {
                $ids = [];
                foreach (preg_split('/\s*[;,|\n]\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
                    $resolved = $this->values->resolve($field, $one);
                    if ($resolved['error'] !== null) {
                        $errors[] = $resolved['error'];
                    } elseif ($resolved['value'] !== null) {
                        $ids[] = $resolved['value'];
                    }
                }
                $values[$field] = array_values(array_unique($ids));
            } elseif (in_array($field, $mappable, true)) {
                $resolved = $this->values->resolve($field, $raw);
                if ($resolved['error'] !== null) {
                    $errors[] = $resolved['error'];
                } elseif ($resolved['value'] !== null) {
                    $values[$field] = $resolved['value'];
                }
            } elseif ($field === 'source_id' || str_ends_with($field, 'source_id')) {
                $values[$field] = mb_substr($raw, 0, 255);
            } else {
                $values[$field] = $raw;
            }
        }

        if ($errors === []) {
            $errors = $this->validate($values);
        }

        return [$values, $errors, $warnings];
    }

    /**
     * The rules of the screens (OrganisationRequest, PersonRequest,
     * AffiliationRequest), applied to the parts present on the row.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function validate(array &$values): array
    {
        $errors = [];
        $target = $this->batch->target;

        if ($target === ImportTarget::Organisations || ($target === ImportTarget::Affiliations && $this->hasOrganisation($values))) {
            $prefix = $target === ImportTarget::Affiliations ? 'organisation.' : '';
            $attributes = $this->organisationAttributes($values);
            $attributes = [...$attributes, ...OrganisationRequest::normalise($attributes)];
            $errors = [...$errors, ...$this->check($attributes, OrganisationRequest::fieldRules(), OrganisationRequest::validationMessages(), $prefix)];
            $this->store($values, $attributes, $prefix);
        }

        if ($target === ImportTarget::People || $target === ImportTarget::Affiliations) {
            $attributes = $this->personAttributes($values);
            $attributes = [...$attributes, ...PersonRequest::normalise($attributes)];
            $errors = [...$errors, ...$this->check($attributes, PersonRequest::fieldRules(), PersonRequest::validationMessages(), '')];
            $this->store($values, $attributes, '');
        }

        if ($target === ImportTarget::Affiliations) {
            $attributes = $this->linkAttributes($values);
            if (isset($attributes['work_email'])) {
                $attributes['work_email'] = mb_strtolower((string) $attributes['work_email']);
            }
            $errors = [...$errors, ...$this->check($attributes, AffiliationRequest::fieldRules(), AffiliationRequest::validationMessages(), '')];
            $this->store($values, $attributes, '');
        }

        if ($target === ImportTarget::Notes) {
            $body = $values['body'] ?? null;
            if (! is_string($body) || $body === '') {
                $errors[] = 'Note : ce champ est obligatoire.';
            } elseif (mb_strlen($body) > Note::MAX_LENGTH) {
                $errors[] = 'Note : le texte dépasse '.Note::MAX_LENGTH.' caractères.';
            }
            $date = $values['date'] ?? null;
            if ($date instanceof CarbonImmutable && $date->isAfter(now()->addMinute())) {
                $errors[] = 'Date : la date est dans le futur.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, string>  $messages
     * @return list<string>
     */
    private function check(array $attributes, array $rules, array $messages, string $prefix): array
    {
        // Only the columns present (the required name is always checked).
        $required = array_filter($rules, fn (array $r) => in_array('required', $r, true));
        $rules = array_intersect_key($rules, $attributes + $required);
        $validator = Validator::make($attributes, $rules, $messages);

        if (! $validator->fails()) {
            return [];
        }

        $labels = $this->batch->target->fields();
        $errors = [];
        foreach ($validator->errors()->messages() as $attribute => $list) {
            $field = array_search($attribute, self::COLUMNS, true);
            $field = $prefix.($field === false ? $attribute : $field);
            $label = $labels[$field] ?? $attribute;
            foreach ($list as $message) {
                $errors[] = "{$label} : {$message}";
            }
        }

        return $errors;
    }

    /**
     * Writes cleaned attributes back into the values (prefixed).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes
     */
    private function store(array &$values, array $attributes, string $prefix): void
    {
        foreach ($attributes as $column => $value) {
            $field = array_search($column, self::COLUMNS, true);
            $values[$prefix.($field === false ? $column : $field)] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function organisationAttributes(array $values): array
    {
        $prefix = $this->batch->target === ImportTarget::Affiliations ? 'organisation.' : '';

        return $this->attributes($values, self::ORGANISATION_FIELDS, $prefix);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function personAttributes(array $values): array
    {
        return $this->attributes($values, self::PERSON_FIELDS, '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function linkAttributes(array $values): array
    {
        return $this->attributes($values, self::LINK_FIELDS, '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function attributes(array $values, array $columns, string $prefix): array
    {
        $attributes = [];
        foreach ($columns as $column) {
            $field = array_search($column, self::COLUMNS, true);
            $key = $prefix.($field === false ? $column : $field);
            if (array_key_exists($key, $values) && $values[$key] !== null) {
                $attributes[$column] = $values[$key];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hasOrganisation(array $values): bool
    {
        foreach (['organisation.name', 'organisation.siret', 'organisation.source_id'] as $field) {
            if (isset($values[$field])) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Targets
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $values
     */
    private function organisationRow(int $line, array $values): RowOutcome
    {
        $outcome = new RowOutcome(ImportRow::SKIPPED);
        $attributes = $this->organisationAttributes($values);
        $sourceId = $values['source_id'] ?? null;
        $das = $values['das'] ?? [];

        $result = $this->resolveOrganisation($line, $attributes, is_string($sourceId) ? $sourceId : null, $das, $outcome, standalone: true);
        $outcome->subject($result['fiche'], $result['created']);

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function personRow(int $line, array $values): RowOutcome
    {
        $outcome = new RowOutcome(ImportRow::SKIPPED);
        $attributes = $this->personAttributes($values);
        $sourceId = $values['source_id'] ?? null;

        // A file of « Personnes » holds individuals unless told otherwise.
        $result = $this->resolvePerson($line, $attributes, is_string($sourceId) ? $sourceId : null, $values['das'] ?? [], $outcome, standalone: true, onCreate: ['is_individual' => true]);
        $outcome->subject($result['fiche'], $result['created']);

        return $outcome;
    }

    /**
     * A person and her entreprise on the same row: the entreprise is found
     * or created first, then the person, then the link between them.
     *
     * @param  array<string, mixed>  $values
     */
    private function affiliationRow(int $line, array $values): RowOutcome
    {
        $outcome = new RowOutcome(ImportRow::SKIPPED);
        $organisation = null;
        $withOrganisation = $this->hasOrganisation($values);

        if ($withOrganisation) {
            $orgSource = $values['organisation.source_id'] ?? null;
            $org = $this->resolveOrganisation($line, $this->organisationAttributes($values), is_string($orgSource) ? $orgSource : null, [], $outcome, standalone: false);
            $organisation = $org['fiche'];
            if ($org['skipped']) {
                $outcome->status = ImportRow::SKIPPED;

                return $outcome;
            }
        } else {
            $outcome->add('warning', 'Aucune entreprise sur la ligne : personne importée sans rattachement.');
        }

        $attributes = $this->personAttributes($values);
        // As on the screen: a new interlocuteur takes the entreprise's status.
        $onCreate = $withOrganisation ? ['status' => $organisation?->status->value ?? $this->organisationAttributes($values)['status'] ?? OrganisationStatus::Prospect->value] : [];
        $sourceId = $values['source_id'] ?? null;
        $person = $this->resolvePerson($line, $attributes, is_string($sourceId) ? $sourceId : null, $values['das'] ?? [], $outcome, standalone: false, onCreate: $onCreate);
        if ($person['skipped']) {
            $outcome->status = ImportRow::SKIPPED;

            return $outcome;
        }

        $outcome->subject($person['fiche'], $person['created']);
        if (! $withOrganisation) {
            return $outcome;
        }

        $link = $this->linkAttributes($values);
        $affiliation = null;
        if ($person['fiche'] !== null && $organisation !== null) {
            $affiliation = Affiliation::query()->where('person_id', $person['fiche']->id)->where('organisation_id', $organisation->id)->whereNull('ended_on')->first();
        }

        if ($affiliation !== null) {
            if ($this->fill($affiliation, $link, overwrite: false, outcome: $outcome)) {
                $outcome->status = $outcome->status === ImportRow::CREATED ? ImportRow::CREATED : ImportRow::UPDATED;
            }
            $outcome->subject($affiliation, false);
            if ($outcome->status === ImportRow::SKIPPED) {
                $outcome->add('info', 'Interlocuteur déjà présent : rien à modifier.');
            }
        } elseif (! $this->dryRun && $person['fiche'] !== null && $organisation !== null) {
            $affiliation = (new Affiliation)->fill([...$link, 'person_id' => $person['fiche']->id, 'organisation_id' => $organisation->id]);
            $affiliation->save();
            $outcome->created($affiliation);
            $outcome->subject($affiliation, true);
            $outcome->status = ImportRow::CREATED;
        } else {
            $outcome->status = ImportRow::CREATED;
        }

        return $outcome;
    }

    /**
     * A note on the fiche found by source identifier, SIRET or e-mail. Its
     * date (if any) becomes the note's date; the last contact only moves
     * forward. A note already present (same fiche, text and date) is skipped.
     *
     * @param  array<string, mixed>  $values
     */
    private function noteRow(array $values): RowOutcome
    {
        $subject = $this->noteSubject($values);
        if ($subject->getAttribute('anonymised_at') !== null) {
            throw new SkipRow('Fiche anonymisée (RGPD) : non réimportée.');
        }
        $body = (string) $values['body'];
        $date = $values['date'] ?? null;

        $existing = Note::query()->where('notable_type', $subject->getMorphClass())->where('notable_id', $subject->getKey())
            ->where('body', $body)
            ->when($date instanceof CarbonImmutable, fn ($q) => $q->where('created_at', $date))
            ->exists();
        if ($existing) {
            return (new RowOutcome(ImportRow::SKIPPED))->add('info', 'Note déjà importée : ligne ignorée.')->subject($subject);
        }

        $outcome = new RowOutcome(ImportRow::CREATED);
        if ($this->dryRun) {
            return $outcome;
        }

        $note = new Note(['body' => $body]);
        $note->notable()->associate($subject);
        if ($date instanceof CarbonImmutable) {
            $note->created_at = Carbon::instance($date);
        }
        $note->save();
        $outcome->created($note);

        return $outcome->subject($note, true);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function noteSubject(array $values): Model
    {
        $sourceId = $values['subject_source_id'] ?? null;
        if (is_string($sourceId)) {
            $found = array_filter([
                $this->bySource(Organisation::class, 'organisation', $sourceId),
                $this->bySource(Person::class, 'person', $sourceId),
            ]);
            if (count($found) > 1) {
                throw new ImportRowException("Identifiant source « {$sourceId} » : une entreprise et une personne le portent, précisez le SIRET ou l'e-mail.");
            }
            if ($found !== []) {
                return $this->active(reset($found));
            }
        }

        $siret = $values['subject_siret'] ?? null;
        if (is_string($siret)) {
            $organisation = Organisation::query()->whereRaw('siret = ?::char(14)', [$siret])->first();
            if ($organisation !== null) {
                return $organisation;
            }
        }

        $email = $values['subject_email'] ?? null;
        if (is_string($email)) {
            $email = mb_strtolower($email);
            $people = Person::query()->where(fn ($q) => $q->where('dup_email', $email)->orWhere('dup_email_secondary', $email))->limit(2)->get();
            if ($people->count() > 1) {
                throw new ImportRowException('E-mail de la fiche : plusieurs personnes ont cette adresse, précisez l\'identifiant source.');
            }
            if ($people->count() === 1) {
                return $people->first();
            }
            $organisations = Organisation::query()->where('dup_email', $email)->limit(2)->get();
            if ($organisations->count() > 1) {
                throw new ImportRowException('E-mail de la fiche : plusieurs entreprises ont cette adresse, précisez le SIRET.');
            }
            if ($organisations->count() === 1) {
                return $organisations->first();
            }
        }

        throw new ImportRowException('Aucune fiche ne correspond (SIRET, e-mail ou identifiant source) : ligne non importée.');
    }

    // ------------------------------------------------------------------
    // Fiches
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $das
     * @return array{fiche: Organisation|null, created: bool, skipped: bool}
     */
    private function resolveOrganisation(int $line, array $attributes, ?string $sourceId, array $das, RowOutcome $outcome, bool $standalone): array
    {
        $what = $standalone ? 'la fiche' : 'l\'entreprise';

        // 1. Same source identifier: the file is the reference, the fiche is updated.
        if ($sourceId !== null && $this->sourceSystem !== null) {
            $existing = $this->bySource(Organisation::class, 'organisation', $sourceId);
            if ($existing instanceof Organisation) {
                $this->update($existing, $attributes, $das, $outcome, overwrite: true);

                return ['fiche' => $existing, 'created' => false, 'skipped' => false];
            }
            if (isset($this->seenSources['organisation'][$sourceId])) {
                $outcome->status = $standalone ? ImportRow::UPDATED : $outcome->status;
                $outcome->add('info', 'Même identifiant source que la ligne '.$this->seenSources['organisation'][$sourceId].' : '.$what.' sera mise à jour.');

                return ['fiche' => null, 'created' => false, 'skipped' => false];
            }
        }

        // 2. Duplicate of an existing fiche, or of an earlier row (simulation).
        $earlier = $this->seenLine('organisation', $attributes);
        $candidate = $earlier === null ? $this->finder->forOrganisation($attributes)->first() : null;
        if ($earlier !== null || $candidate !== null) {
            $certain = $earlier !== null || self::certain($candidate);
            $reason = $earlier !== null ? "doublon de la ligne {$earlier} du fichier" : 'doublon '.($certain ? 'probable' : 'possible').' d\'une fiche existante ('.mb_strtolower(DuplicateCandidate::REASON_LABELS[$candidate->reason] ?? $candidate->reason).')';
            $kept = $candidate === null ? null : Organisation::query()->find($candidate->id);

            if (! $standalone && $certain && $this->strategy !== DuplicateStrategy::Create) {
                // Interlocuteurs: the row is attached to the entreprise already known.
                if ($kept !== null) {
                    $this->guard($kept);
                }
                if ($this->strategy === DuplicateStrategy::Merge && $kept !== null) {
                    $this->update($kept, $attributes, $das, $outcome, overwrite: false);
                }

                return ['fiche' => $kept, 'created' => false, 'skipped' => false];
            }

            if ($this->strategy === DuplicateStrategy::Skip || ($this->strategy === DuplicateStrategy::Merge && ! $certain)) {
                $outcome->status = ImportRow::SKIPPED;
                $outcome->add('info', ucfirst($what).' est un '.$reason.' : ligne ignorée'.($certain ? '.' : ', à vérifier.'));
                $outcome->subject($kept);

                return ['fiche' => $kept, 'created' => false, 'skipped' => true];
            }

            if ($this->strategy === DuplicateStrategy::Merge) {
                if ($kept !== null) {
                    $this->update($kept, $attributes, $das, $outcome, overwrite: false);
                    $outcome->add('info', 'Fiche existante complétée ('.$reason.').');
                    $this->claimSource($kept, $sourceId, $outcome);
                } else {
                    $outcome->status = $standalone ? ImportRow::UPDATED : $outcome->status;
                    $outcome->add('info', 'La fiche de la ligne '.$earlier.' sera complétée.');
                }

                return ['fiche' => $kept, 'created' => false, 'skipped' => false];
            }

            // Create anyway: never on a SIRET already used.
            if (($candidate?->reason === 'siret') || ($earlier !== null && isset($attributes['siret']) && isset($this->seen['organisation|siret:'.$attributes['siret']]))) {
                throw new ImportRowException('SIRET : '.OrganisationRequest::DUPLICATE_SIRET);
            }
            $outcome->add('warning', 'Créée malgré un '.$reason.'.');
        }

        // 3. New fiche.
        $this->remember('organisation', $attributes, $sourceId, $line);
        $outcome->status = ImportRow::CREATED;
        if ($this->dryRun) {
            return ['fiche' => null, 'created' => true, 'skipped' => false];
        }

        $organisation = new Organisation;
        $organisation->fill([...$attributes, 'source_system' => $sourceId === null ? null : $this->sourceSystem, 'source_id' => $sourceId]);
        $organisation->save();
        $this->das->add($organisation, $das);
        $outcome->created($organisation);
        if ($sourceId !== null) {
            $this->sources['organisation'][$sourceId] = $organisation->id;
        }

        return ['fiche' => $organisation, 'created' => true, 'skipped' => false];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $das
     * @param  array<string, mixed>  $onCreate  defaults of a new fiche (a file value wins)
     * @return array{fiche: Person|null, created: bool, skipped: bool}
     */
    private function resolvePerson(int $line, array $attributes, ?string $sourceId, array $das, RowOutcome $outcome, bool $standalone, array $onCreate = []): array
    {
        if ($sourceId !== null && $this->sourceSystem !== null) {
            $existing = $this->bySource(Person::class, 'person', $sourceId);
            if ($existing instanceof Person) {
                $this->update($existing, $attributes, $das, $outcome, overwrite: true);

                return ['fiche' => $existing, 'created' => false, 'skipped' => false];
            }
            if (isset($this->seenSources['person'][$sourceId])) {
                $outcome->status = ImportRow::UPDATED;
                $outcome->add('info', 'Même identifiant source que la ligne '.$this->seenSources['person'][$sourceId].' : la fiche sera mise à jour.');

                return ['fiche' => null, 'created' => false, 'skipped' => false];
            }
        }

        $earlier = $this->seenLine('person', $attributes);
        $candidate = $earlier === null ? $this->finder->forPerson($attributes)->first() : null;
        if ($earlier !== null || $candidate !== null) {
            $certain = $earlier !== null || self::certain($candidate);
            $reason = $earlier !== null ? "doublon de la ligne {$earlier} du fichier" : 'doublon '.($certain ? 'probable' : 'possible').' d\'une fiche existante ('.mb_strtolower(DuplicateCandidate::REASON_LABELS[$candidate->reason] ?? $candidate->reason).')';
            $kept = $candidate === null ? null : Person::query()->find($candidate->id);

            if (! $standalone && $certain && $this->strategy !== DuplicateStrategy::Create) {
                if ($kept !== null) {
                    $this->guard($kept);
                }
                if ($this->strategy === DuplicateStrategy::Merge && $kept !== null) {
                    $this->update($kept, $attributes, $das, $outcome, overwrite: false);
                }

                return ['fiche' => $kept, 'created' => false, 'skipped' => false];
            }

            if ($this->strategy === DuplicateStrategy::Skip || ($this->strategy === DuplicateStrategy::Merge && ! $certain)) {
                $outcome->status = ImportRow::SKIPPED;
                $outcome->add('info', 'La personne est un '.$reason.' : ligne ignorée'.($certain ? '.' : ', à vérifier.'));
                $outcome->subject($kept);

                return ['fiche' => $kept, 'created' => false, 'skipped' => true];
            }

            if ($this->strategy === DuplicateStrategy::Merge) {
                if ($kept !== null) {
                    $this->update($kept, $attributes, $das, $outcome, overwrite: false);
                    $outcome->add('info', 'Fiche existante complétée ('.$reason.').');
                    $this->claimSource($kept, $sourceId, $outcome);
                } else {
                    $outcome->status = ImportRow::UPDATED;
                    $outcome->add('info', 'La fiche de la ligne '.$earlier.' sera complétée.');
                }

                return ['fiche' => $kept, 'created' => false, 'skipped' => false];
            }

            $outcome->add('warning', 'Créée malgré un '.$reason.'.');
        }

        $this->remember('person', $attributes, $sourceId, $line);
        $outcome->status = ImportRow::CREATED;
        if ($this->dryRun) {
            return ['fiche' => null, 'created' => true, 'skipped' => false];
        }

        $person = new Person;
        $person->fill([...$onCreate, ...$attributes, 'source_system' => $sourceId === null ? null : $this->sourceSystem, 'source_id' => $sourceId]);
        $person->save();
        $this->das->add($person, $das);
        $outcome->created($person);
        if ($sourceId !== null) {
            $this->sources['person'][$sourceId] = $person->id;
        }

        return ['fiche' => $person, 'created' => true, 'skipped' => false];
    }

    /**
     * Updates a fiche found by source identifier (overwrite: the file's
     * non-empty values win) or by duplicate (complete: only empty fields).
     * An empty cell never erases a value.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $das
     */
    private function update(Organisation|Person $fiche, array $attributes, array $das, RowOutcome $outcome, bool $overwrite): void
    {
        $this->guard($fiche);

        if ($outcome->status !== ImportRow::CREATED) {
            $outcome->status = ImportRow::UPDATED;
        }

        if ($this->dryRun) {
            // Overwriting a fiche someone changed since its import: counted for the warning.
            $outcome->modifiedSinceImport = $outcome->modifiedSinceImport || ($overwrite && $this->stamps->modifiedSinceImport($fiche));

            return;
        }

        $restored = false;
        if ($fiche->trashed()) {
            // guard(): only a fiche trashed by an import undo comes back.
            $fiche->restore();
            $restored = true;
            $outcome->add('info', 'Fiche restaurée depuis la corbeille.');
        }

        $changed = $this->fill($fiche, $attributes, $overwrite);
        $before = $fiche->das()->count();
        $this->das->add($fiche, $das);
        if ($changed || $restored) {
            $outcome->touched($fiche);
        }
        if (! $changed && $fiche->das()->count() === $before && $outcome->status === ImportRow::UPDATED) {
            $outcome->add('info', 'Aucune modification.');
        }
    }

    /**
     * A fiche an import must never touch: anonymised (GDPR), or deleted by a
     * user (only a fiche deleted by the undo of an import may come back).
     *
     * @throws SkipRow
     */
    private function guard(Organisation|Person $fiche): void
    {
        if ($fiche->getAttribute('anonymised_at') !== null) {
            throw new SkipRow('Fiche anonymisée (RGPD) : non réimportée.');
        }
        if ($fiche->trashed() && ! $this->stamps->trashedByUndo($fiche)) {
            throw new SkipRow('Fiche supprimée par un utilisateur : non réimportée.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fill(Model $model, array $attributes, bool $overwrite, ?RowOutcome $outcome = null): bool
    {
        if ($this->dryRun) {
            return $attributes !== [];
        }

        foreach ($attributes as $key => $value) {
            $current = $model->getAttribute($key);
            $empty = $current === null || $current === '' || ($key === 'is_referent' && $current === false);
            if ($overwrite || $empty) {
                $model->setAttribute($key, $value);
            }
        }

        if (! $model->isDirty()) {
            return false;
        }
        $model->save();
        $outcome?->touched($model);

        return true;
    }

    /**
     * A fiche completed by an import with identifiers takes them, if it has none.
     */
    private function claimSource(Organisation|Person $fiche, ?string $sourceId, ?RowOutcome $outcome = null): void
    {
        if ($this->dryRun || $sourceId === null || $this->sourceSystem === null || $fiche->source_id !== null) {
            return;
        }
        $fiche->forceFill(['source_system' => $this->sourceSystem, 'source_id' => $sourceId])->save();
        $outcome?->touched($fiche);
    }

    /**
     * The fiche holding a source identifier, followed to the fiche it was
     * merged into (chain, at most 20 hops). Trashed fiches are returned
     * (guard() decides whether they may come back).
     *
     * @template T of Organisation|Person
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function bySource(string $class, string $type, string $sourceId): ?Model
    {
        $id = $this->sources[$type][$sourceId] ?? null;
        if ($id === null) {
            return null;
        }

        $fiche = $this->fiches[$type][$id] ?? $class::withTrashed()->find($id);
        for ($hops = 0; $fiche !== null && $fiche->merged_into_id !== null && $hops < 20; $hops++) {
            $fiche = $class::withTrashed()->find($fiche->merged_into_id);
        }

        return $fiche !== null && $fiche->merged_into_id === null ? $fiche : null;
    }

    private function active(Model $fiche): Model
    {
        if (method_exists($fiche, 'trashed') && $fiche->trashed()) {
            throw new ImportRowException('La fiche de cet identifiant source est dans la corbeille : restaurez-la d\'abord.');
        }

        return $fiche;
    }

    private static function certain(?DuplicateCandidate $candidate): bool
    {
        return $candidate !== null && ($candidate->reason !== 'name' || $candidate->score >= 0.999);
    }

    // ------------------------------------------------------------------
    // Simulation memory: fiches that earlier rows would create
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private static function keys(string $type, array $attributes): array
    {
        $keys = [];
        if ($type === 'organisation' && isset($attributes['siret'])) {
            $keys[] = 'siret:'.$attributes['siret'];
        }
        foreach (['email', 'email_secondary'] as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field])) {
                $keys[] = 'email:'.mb_strtolower($attributes[$field]);
            }
        }
        $name = $type === 'organisation' ? ($attributes['name'] ?? null) : trim(($attributes['last_name'] ?? '').' '.($attributes['first_name'] ?? ''));
        $place = $attributes['postal_code'] ?? ($attributes['city'] ?? null);
        if (is_string($name) && $name !== '' && is_string($place)) {
            $keys[] = 'name:'.Text::key($name).'|'.Text::key($place);
        }

        return array_map(fn (string $k) => $type.'|'.$k, $keys);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seenLine(string $type, array $attributes): ?int
    {
        if (! $this->dryRun) {
            return null; // a real run finds the fiches created by earlier rows in the database
        }
        foreach (self::keys($type, $attributes) as $key) {
            if (isset($this->seen[$key])) {
                return $this->seen[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function remember(string $type, array $attributes, ?string $sourceId, int $line): void
    {
        if (! $this->dryRun) {
            return;
        }
        foreach (self::keys($type, $attributes) as $key) {
            $this->seen[$key] ??= $line;
        }
        if ($sourceId !== null) {
            $this->seenSources[$type][$sourceId] ??= (string) $line;
        }
    }

    private static function conflict(UniqueConstraintViolationException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'organisations_siret_unique') => 'SIRET : '.OrganisationRequest::DUPLICATE_SIRET,
            str_contains($message, 'affiliations_active_unique') => AffiliationRequest::ALREADY_ATTACHED,
            str_contains($message, 'source_unique') => 'Identifiant source déjà utilisé par une autre fiche.',
            default => 'Ligne non importée : elle entre en conflit avec une fiche existante.',
        };
    }
}
