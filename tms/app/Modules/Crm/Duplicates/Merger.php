<?php

namespace App\Modules\Crm\Duplicates;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Irreversible merge of two fiches of the same type: $drop is absorbed by
 * $keep. In one transaction, with both rows locked (a concurrent second
 * merge of the same fiche waits, then is refused):
 * chosen fields copied onto $keep (field => 'keep'|'drop'; a missing choice
 * keeps $keep's value unless it is empty), affiliations moved (one active
 * link per pair: the oldest survives, completed by the other), notes and
 * attachments moved, DAS united, source identifiers kept in merged_sources,
 * $drop trashed for good with merged_into_id, journal crm.merged (ids only).
 */
class Merger
{
    /** @var array<string, string> mergeable fields of an entreprise => French label */
    public const ORGANISATION_FIELDS = [
        'name' => 'Raison sociale', 'siret' => 'SIRET', 'naf_code' => 'Code NAF', 'status' => 'Statut',
        'responsible_user_id' => 'Responsable', 'legal_form_id' => 'Forme juridique', 'sector_id' => "Secteur d'activité",
        'establishment_type_id' => "Type d'établissement", 'company_size_id' => 'Taille', 'origin_id' => 'Origine',
        'email' => 'E-mail', 'phone' => 'Téléphone', 'website' => 'Site web',
        'address_line1' => 'Adresse', 'address_line2' => "Complément d'adresse", 'postal_code' => 'Code postal', 'city' => 'Ville', 'country' => 'Pays',
        'notes_summary' => 'Notes',
    ];

    /** @var array<string, string> mergeable fields of a person => French label */
    public const PERSON_FIELDS = [
        'civility' => 'Civilité', 'last_name' => 'Nom', 'first_name' => 'Prénom', 'status' => 'Statut', 'responsible_user_id' => 'Responsable',
        'is_individual' => 'Particulier', 'email' => 'E-mail', 'email_secondary' => 'E-mail secondaire', 'phone' => 'Téléphone', 'mobile' => 'Mobile',
        'address_line1' => 'Adresse', 'address_line2' => "Complément d'adresse", 'postal_code' => 'Code postal', 'city' => 'Ville', 'country' => 'Pays',
        'birth_date' => 'Date de naissance', 'professional_situation_id' => 'Situation professionnelle', 'funding_mode_id' => 'Mode de financement',
        'origin_id' => 'Origine', 'project' => 'Projet',
    ];

    /** Placeholder of the (NOT NULL) last name of an absorbed, scrubbed person. */
    public const RETRY = 'La fusion n\'a pas pu aboutir, réessayez.';

    public const MERGED_PERSON_NAME = 'Fusionnée';

    /** Affiliation fields completed from the duplicate link when empty on the surviving one. */
    private const LINK_FIELDS = ['job_title', 'decision_role_id', 'work_email', 'work_phone', 'started_on'];

    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, string>  $fieldChoices
     */
    public function organisations(Organisation $keep, Organisation $drop, array $fieldChoices): Organisation
    {
        $merged = $this->merge($keep, $drop, $fieldChoices, self::ORGANISATION_FIELDS);
        assert($merged instanceof Organisation);

        return $merged;
    }

    /**
     * @param  array<string, string>  $fieldChoices
     */
    public function people(Person $keep, Person $drop, array $fieldChoices): Person
    {
        $merged = $this->merge($keep, $drop, $fieldChoices, self::PERSON_FIELDS);
        assert($merged instanceof Person);

        return $merged;
    }

    /**
     * The value retained for each field when nothing is chosen: the kept
     * fiche's, unless it is empty and the other one is not.
     */
    public static function defaultChoice(mixed $keepValue, mixed $dropValue): string
    {
        // A boolean is never « empty »: false is a value.
        if (is_bool($keepValue)) {
            return 'keep';
        }

        return self::isEmpty($keepValue) && ! self::isEmpty($dropValue) ? 'drop' : 'keep';
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * @param  array<string, string>  $choices
     * @param  array<string, string>  $fields
     */
    private function merge(Organisation|Person $keep, Organisation|Person $drop, array $choices, array $fields): Organisation|Person
    {
        foreach ($choices as $field => $choice) {
            if (! array_key_exists($field, $fields) || ! in_array($choice, ['keep', 'drop'], true)) {
                throw new InvalidArgumentException("Invalid merge choice for field [{$field}].");
            }
        }

        if ($keep::class !== $drop::class) {
            throw ValidationException::withMessages(['drop' => 'Seules deux fiches de même type peuvent être fusionnées.']);
        }
        if ($keep->getKey() === $drop->getKey()) {
            throw ValidationException::withMessages(['drop' => 'Une fiche ne peut pas être fusionnée avec elle-même.']);
        }

        try {
            return $this->transaction($keep, $drop, $choices, $fields);
        } catch (QueryException $e) {
            // Lost a race (unique index, deadlock): nothing was written.
            if (in_array($e->errorInfo[0] ?? null, ['23505', '40P01'], true) || $e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['drop' => self::RETRY]);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, string>  $choices
     * @param  array<string, string>  $fields
     */
    private function transaction(Organisation|Person $keep, Organisation|Person $drop, array $choices, array $fields): Organisation|Person
    {
        return DB::transaction(function () use ($keep, $drop, $choices, $fields) {
            // Fresh, locked copies, always in the same order (no deadlock
            // between two merges of the same pair). RLS keeps them in the organisme.
            $ids = [$keep->getKey(), $drop->getKey()];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $this->lock($keep, $id);
            }
            $k = $locked[$keep->getKey()];
            $d = $locked[$drop->getKey()];

            if ($k === null || $k->trashed() || $k->merged_into_id !== null) {
                throw ValidationException::withMessages(['keep' => 'La fiche à conserver n\'existe plus ou a déjà été fusionnée.']);
            }
            if ($d === null || $d->trashed() || $d->merged_into_id !== null) {
                throw ValidationException::withMessages(['drop' => 'La fiche à absorber n\'existe plus ou a déjà été fusionnée.']);
            }
            if (($k instanceof Person && $k->anonymised_at !== null) || ($d instanceof Person && $d->anonymised_at !== null)) {
                throw ValidationException::withMessages(['drop' => 'Une fiche anonymisée ne peut pas être fusionnée.']);
            }

            $isPerson = $k instanceof Person;
            $this->moveAffiliations($isPerson ? 'person_id' : 'organisation_id', $isPerson ? 'organisation_id' : 'person_id', $k->id, $d->id);
            $this->moveTimeline($k, $d);
            $this->uniteDas($k, $d, $isPerson);

            // Values retained on $keep, read before the absorbed fiche is scrubbed.
            foreach ($fields as $field => $label) {
                $choice = $choices[$field] ?? self::defaultChoice($k->getAttribute($field), $d->getAttribute($field));
                if ($choice === 'drop') {
                    $k->setAttribute($field, $d->getAttribute($field));
                }
            }

            // Absorbed fiche first: frees its unique values (SIRET) for $keep.
            // A person keeps no personal data (GDPR minimisation: the row only
            // serves the redirect and the source identifiers). Auditable masks
            // the values. An entreprise (legal person) is kept as is.
            $d->forceFill(['merged_into_id' => $k->id]);
            if ($d instanceof Person) {
                foreach (Person::PERSONAL_FIELDS as $field) {
                    $d->setAttribute($field, null);
                }
                $d->last_name = self::MERGED_PERSON_NAME;
            }
            $d->save();
            $d->delete();

            $last = collect([$k->last_contact_at, $d->last_contact_at])->filter()->max();
            $k->last_contact_at = $last instanceof Carbon ? $last : null;
            $k->merged_sources = [
                ...($k->merged_sources ?? []),
                ...($d->merged_sources ?? []),
                ['id' => $d->id, 'source_id' => $d->source_id, 'source_system' => $d->source_system],
            ];
            $k->save();

            $this->audit->record('crm.merged', $k, [], ['type' => $k->getMorphClass(), 'kept_id' => $k->id, 'dropped_id' => $d->id]);

            return $k->refresh();
        });
    }

    private function lock(Organisation|Person $type, string $id): Organisation|Person|null
    {
        return $type instanceof Person
            ? Person::withTrashed()->whereKey($id)->lockForUpdate()->first()
            : Organisation::withTrashed()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * Every link of $dropId (trashed ones too) goes to $keepId. When both
     * have an active link to the same counterpart, one survives: the oldest
     * started_on (the kept fiche's on a tie or unknown dates), with its empty
     * fields completed from the other, which goes to the trash first so the
     * unique index (one active link per pair) is never violated.
     *
     * @param  'person_id'|'organisation_id'  $column
     * @param  'person_id'|'organisation_id'  $counterpart
     */
    private function moveAffiliations(string $column, string $counterpart, string $keepId, string $dropId): void
    {
        $links = Affiliation::withTrashed()->where($column, $dropId)->orderBy('id')->lockForUpdate()->get();

        foreach ($links as $link) {
            $twin = $link->isActive()
                ? Affiliation::query()->where($column, $keepId)->where($counterpart, $link->getAttribute($counterpart))->whereNull('ended_on')->lockForUpdate()->first()
                : null;

            if ($twin === null) {
                $link->setAttribute($column, $keepId);
                $link->save();

                continue;
            }

            $dropIsOlder = $link->started_on !== null && ($twin->started_on === null || $link->started_on->lt($twin->started_on));
            [$survivor, $loser] = $dropIsOlder ? [$link, $twin] : [$twin, $link];

            foreach (self::LINK_FIELDS as $field) {
                if (self::isEmpty($survivor->getAttribute($field)) && ! self::isEmpty($loser->getAttribute($field))) {
                    $survivor->setAttribute($field, $loser->getAttribute($field));
                }
            }

            // The trashed duplicate link keeps no personal contact data.
            $loser->delete();
            $loser->setAttribute($column, $keepId);
            foreach (Affiliation::PERSONAL_FIELDS as $field) {
                $loser->setAttribute($field, null);
            }
            $loser->save();

            $survivor->setAttribute($column, $keepId);
            $survivor->save();
        }
    }

    private function moveTimeline(Organisation|Person $keep, Organisation|Person $drop): void
    {
        foreach ([Note::class, Attachment::class] as $model) {
            $model::withTrashed()
                ->where('notable_type', $drop->getMorphClass())->where('notable_id', $drop->id)
                ->update(['notable_id' => $keep->id]);
        }
    }

    private function uniteDas(Organisation|Person $keep, Organisation|Person $drop, bool $isPerson): void
    {
        [$table, $column, $event] = $isPerson
            ? ['person_das', 'person_id', 'crm.person_das_changed']
            : ['organisation_das', 'organisation_id', 'crm.organisation_das_changed'];

        $before = DB::table($table)->where($column, $keep->id)->pluck('das_id')->sort()->values()->all();
        $dropDas = DB::table($table)->where($column, $drop->id)->pluck('das_id')->all();
        $missing = array_values(array_diff($dropDas, $before));

        foreach ($missing as $dasId) {
            DB::table($table)->insert(['tenant_id' => $this->tenants->id(), $column => $keep->id, 'das_id' => $dasId]);
        }
        DB::table($table)->where($column, $drop->id)->delete();

        if ($missing !== []) {
            $after = collect([...$before, ...$missing])->sort()->values()->all();
            $this->audit->record($event, $keep, ['das_ids' => $before], ['das_ids' => $after]);
        }
    }
}
