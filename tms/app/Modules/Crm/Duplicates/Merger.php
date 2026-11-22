<?php

namespace App\Modules\Crm\Duplicates;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\CalendarAnnouncements;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Crm\Quotes\Quote;
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
 * attachments moved, opportunities and opportunity contacts moved (a
 * person holding opportunities stays a particulier), quotes moved (account
 * and recipient; the frozen recipient copy is kept), brochure sendings moved, actions moved,
 * references of other modules repointed (Crm\Public\FicheLinks::merge), DAS
 * and trainings of interest united, source identifiers kept in merged_sources, $drop trashed for
 * good with merged_into_id, journal crm.merged (ids only).
 */
class Merger
{
    /** @var array<string, string> mergeable fields of an entreprise => French label */
    public const ORGANISATION_FIELDS = [
        'name' => 'Raison sociale', 'siret' => 'SIRET', 'naf_code' => 'Code NAF', 'idcc' => 'IDCC', 'status' => 'Statut',
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
        'birth_date' => 'Date de naissance', 'professional_situation_id' => 'Situation professionnelle', 'education_level_id' => 'Niveau de diplôme', 'funding_mode_id' => 'Mode de financement',
        'origin_id' => 'Origine', 'project' => 'Projet',
    ];

    /** Placeholder of the (NOT NULL) last name of an absorbed, scrubbed person. */
    public const RETRY = 'La fusion n\'a pas pu aboutir, réessayez.';

    public const MERGED_PERSON_NAME = 'Fusionnée';

    /** Affiliation fields completed from the duplicate link when empty on the surviving one. */
    private const LINK_FIELDS = ['job_title', 'decision_role_id', 'work_email', 'work_phone', 'started_on'];

    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants, private readonly FicheTrainings $interests) {}

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
            $this->moveOpportunities($k, $d);
            $this->moveQuotes($k, $d);
            $this->moveBrochureSendings($k, $d);
            $this->moveActivities($k, $d);
            // References held by other modules (Sessions: sessions, dossiers, inscriptions, financements).
            FicheLinks::merge($isPerson ? 'person' : 'organisation', $k->id, $d->id);
            $this->uniteDas($k, $d, $isPerson);
            if (! $isPerson) {
                $this->uniteTypes($k, $d);
            }
            $this->interests->merge($k, $d);
            // Calendars (Outlook) now show the kept fiche (moves above are raw SQL).
            CalendarAnnouncements::announceFor($isPerson ? 'person_id' : 'organisation_id', [$k->id, $d->id]);

            // Values retained on $keep, read before the absorbed fiche is scrubbed.
            foreach ($fields as $field => $label) {
                $choice = $choices[$field] ?? self::defaultChoice($k->getAttribute($field), $d->getAttribute($field));
                if ($choice === 'drop') {
                    $k->setAttribute($field, $d->getAttribute($field));
                }
            }

            // The account of an opportunity is a particulier: a person holding
            // opportunities stays one, whatever the choice for the flag.
            if ($k instanceof Person && Opportunity::withTrashed()->where('person_id', $k->id)->exists()) {
                $k->is_individual = true;
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

            // GDPR: the opposition to prospecting survives the merge (the
            // earliest one), and the consent history follows the person
            // (append-only rows: moved in SQL, not through the model). The
            // legal basis stays the kept fiche's.
            if ($k instanceof Person && $d instanceof Person) {
                $optOut = collect([$k->prospecting_opt_out_at, $d->prospecting_opt_out_at])->filter()->min();
                $k->prospecting_opt_out_at = $optOut instanceof Carbon ? $optOut : null;
                DB::table('consents')->where('person_id', $d->id)->update(['person_id' => $k->id]);
            }

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
            // « Référent » is a flag, not an empty-or-not field: either link carries it.
            $survivor->is_referent = $survivor->is_referent || $loser->is_referent;

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

    /**
     * Opportunities (trashed ones too, with their stage history and their
     * trainings, which hang on the opportunity: nothing to move) follow the
     * fiche. A person's contact rows go to the kept person, once per
     * opportunity.
     */
    private function moveOpportunities(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $column = $keep instanceof Person ? 'person_id' : 'organisation_id';
        Opportunity::withTrashed()->where($column, $drop->id)->update([$column => $keep->id]);

        if ($keep instanceof Person) {
            DB::table('opportunity_contacts')->where('person_id', $drop->id)
                ->whereIn('opportunity_id', DB::table('opportunity_contacts')->select('opportunity_id')->where('person_id', $keep->id))
                ->delete();
            DB::table('opportunity_contacts')->where('person_id', $drop->id)->update(['person_id' => $keep->id]);
        }
    }

    /**
     * Quotes (trashed ones too) follow the fiche: as account, and for a
     * person as recipient. The copy of the recipient of a sent quote
     * (recipient_snapshot) is a frozen document: left as it is.
     */
    private function moveQuotes(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $columns = $keep instanceof Person ? ['person_id', 'contact_person_id'] : ['organisation_id'];
        foreach ($columns as $column) {
            Quote::withTrashed()->where($column, $drop->id)->toBase()->update([$column => $keep->id, 'updated_at' => now()]);
        }
    }

    /**
     * Brochures sent from the fiche, and for a person those sent to her,
     * follow the fiche.
     */
    private function moveBrochureSendings(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $columns = $keep instanceof Person ? ['person_id', 'recipient_person_id'] : ['organisation_id'];
        foreach ($columns as $column) {
            DB::table('brochure_sendings')->where($column, $drop->id)->update([$column => $keep->id, 'updated_at' => now()]);
        }
    }

    /**
     * Actions (trashed ones too) follow the fiche. Written in SQL: neither
     * the journal nor the contact date need them (merged at the end).
     */
    private function moveActivities(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $column = $keep instanceof Person ? 'person_id' : 'organisation_id';
        Activity::withTrashed()->where($column, $drop->id)->toBase()->update([$column => $keep->id, 'updated_at' => now()]);
    }

    /** The kept entreprise takes the types of the dropped one too (organisation_types). */
    private function uniteTypes(Organisation|Person $keep, Organisation|Person $drop): void
    {
        $have = DB::table('organisation_types')->where('organisation_id', $keep->id)->pluck('type_id')->all();
        foreach (DB::table('organisation_types')->where('organisation_id', $drop->id)->pluck('type_id') as $typeId) {
            if (! in_array($typeId, $have, true)) {
                DB::table('organisation_types')->insert(['tenant_id' => $this->tenants->id(), 'organisation_id' => $keep->id, 'type_id' => $typeId]);
            }
        }
        DB::table('organisation_types')->where('organisation_id', $drop->id)->delete();
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
