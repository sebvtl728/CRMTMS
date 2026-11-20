<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Catalogue\Brochures;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Brochures\BrochureSending;
use App\Modules\Crm\Interests\FicheTraining;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the organisme holds about a person (right of access and
 * portability, GDPR art. 15 and 20): identity, contact details, links with
 * the entreprise names, opportunities (as particulier or contact: title,
 * stage, amount), quotes (as particulier or recipient: number, date, status,
 * total TTC), brochure sendings (« plaquettes »: made to her or from her
 * fiche: date, brochures, status, address, subject, message), actions (hers or on her opportunities as particulier:
 * type, subject, status, result, report, place), notes, consents, attachments (listed, not their
 * content), trainings of interest (« Formations d'intérêt »), the sections
 * of other modules (FicheLinks: her enrolments and dossiers), DAS and dates. Trashed items are included (still held), flagged.
 * Fiches merged into her are listed by id (their data now lives here).
 * Always complete (RGPD art. 15): nothing is left out for the viewer's
 * scopes; hence reserved to users who see the whole organisme (allowedFor).
 */
final class PersonDataExport
{
    public const INCOMPLETE = 'L\'export RGPD doit être complet : il est réservé aux rôles qui voient tout l\'organisme.';

    /**
     * The four domain view rights on « Tout l'organisme », and those of the
     * modules that add sections (FicheLinks::registerExport: Dossiers,
     * Sessions et inscriptions): the complete file shows nothing the user
     * does not already see.
     */
    public static function allowedFor(User $user): bool
    {
        foreach ([...Permissions::views(), ...FicheLinks::exportPermissions()] as $permission) {
            if (Scopes::of($user, $permission) !== AccessScope::All) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(Person $person): array
    {
        $person->loadMissing(['professionalSituation', 'fundingMode', 'origin', 'das']);
        $label = fn (?ReferenceItem $i) => $i?->label;
        $date = fn (?DateTimeInterface $d) => $d?->format(DateTimeInterface::ATOM);

        $links = Affiliation::withTrashed()->with(['organisation' => fn ($q) => $q->withTrashed(), 'decisionRole'])
            ->where('person_id', $person->id)
            ->orderBy('created_at')->get();
        $notes = Note::withTrashed()->where('notable_type', $person->getMorphClass())->where('notable_id', $person->id)->orderBy('created_at')->get();
        $files = Attachment::withTrashed()->where('notable_type', $person->getMorphClass())->where('notable_id', $person->id)->orderBy('created_at')->get();
        $opportunities = Opportunity::withTrashed()->with('stage')
            ->where(fn ($q) => $q->where('person_id', $person->id)
                ->orWhereIn('id', DB::table('opportunity_contacts')->select('opportunity_id')->where('person_id', $person->id)))
            ->orderByRaw('person_id is null')->orderBy('created_at')->orderBy('id')->get();
        $actions = Activity::withTrashed()->with(['type', 'result'])
            ->where(fn ($q) => $q->where('person_id', $person->id)
                ->orWhereIn('opportunity_id', DB::table('opportunities')->select('id')->where('person_id', $person->id)))
            ->orderBy('happened_at')->orderBy('id')->get();
        $quotes = Quote::withTrashed()
            ->where(fn ($q) => $q->where('person_id', $person->id)->orWhere('contact_person_id', $person->id))
            ->orderBy('issued_on')->orderBy('number')->get();
        $sendings = BrochureSending::query()
            ->where(fn ($q) => $q->where('recipient_person_id', $person->id)->orWhere('person_id', $person->id))
            ->orderBy('created_at')->orderBy('id')->get();
        $sentVersions = self::sentVersions($sendings);
        $trainings = app(FicheTrainings::class)->rows($person);
        $addedOn = FicheTraining::query()->where('person_id', $person->id)->pluck('created_at', 'training_id');
        $consents = Consent::query()->where('person_id', $person->id)->orderBy('recorded_at')->get();

        return [
            'exporte_le' => now()->format(DateTimeInterface::ATOM),
            'identite' => [
                'id' => $person->id,
                'civilite' => $person->civility,
                'prenom' => $person->first_name,
                'nom' => $person->last_name,
                'date_de_naissance' => $person->birth_date?->toDateString(),
                'particulier' => $person->is_individual,
                'projet' => $person->project,
                'besoins_futurs' => $person->future_needs,
                'statut' => $person->status->label(),
                'situation_professionnelle' => $label($person->professionalSituation),
                'mode_de_financement' => $label($person->fundingMode),
                'origine' => $label($person->origin),
                'das' => $person->das->pluck('label')->all(),
                'base_legale' => $person->legal_basis->label(),
                'opposition_prospection_le' => $date($person->prospecting_opt_out_at),
                'anonymisee_le' => $date($person->anonymised_at),
            ],
            'coordonnees' => [
                'email' => $person->email,
                'email_secondaire' => $person->email_secondary,
                'telephone' => $person->phone,
                'mobile' => $person->mobile,
                'adresse' => $person->address_line1,
                'complement_adresse' => $person->address_line2,
                'code_postal' => $person->postal_code,
                'ville' => $person->city,
                'pays' => $person->country,
            ],
            'rattachements' => $links->map(fn (Affiliation $a) => [
                'entreprise' => $a->organisation?->name,
                'fonction' => $a->job_title,
                'role_decision' => $label($a->decisionRole),
                'email_professionnel' => $a->work_email,
                'telephone_professionnel' => $a->work_phone,
                'referent' => $a->is_referent,
                'debut' => $a->started_on?->toDateString(),
                'fin' => $a->ended_on?->toDateString(),
                'supprime' => $a->trashed(),
            ])->all(),
            'formations_d_interet' => array_map(fn (array $t) => [
                'formation' => $t['title'],
                'code' => $t['code'],
                'das' => $t['das'],
                'ajoutee_le' => $date($addedOn[$t['id']] ?? null),
            ], $trainings),
            'opportunites' => $opportunities->map(fn (Opportunity $o) => [
                'titre' => $o->title,
                'role' => $o->person_id === $person->id ? 'Compte (particulier)' : 'Contact',
                'etape' => $label($o->stage),
                'montant' => $o->amount,
                'supprimee' => $o->trashed(),
            ])->all(),
            'devis' => $quotes->map(fn (Quote $q) => [
                'numero' => $q->number,
                'date' => $q->issued_on->toDateString(),
                'role' => $q->person_id === $person->id ? 'Compte (particulier)' : 'Destinataire',
                'statut' => $q->status->label(),
                'total_ttc' => $q->total_ttc,
                'devis_signe' => $q->signed_path !== null ? 'oui' : 'non',
                'supprime' => $q->trashed(),
            ])->all(),
            'plaquettes' => $sendings->map(fn (BrochureSending $b) => [
                'date' => $date($b->created_at),
                'role' => $b->recipient_person_id === $person->id ? 'Destinataire' : 'Envoyée depuis sa fiche',
                'plaquettes' => $sentVersions[$b->id] ?? [],
                'statut' => BrochureSending::STATUS_LABELS[$b->email_status] ?? $b->email_status,
                'envoyee_le' => $date($b->sent_at),
                'adresse_destinataire' => $b->recipient_email,
                'objet' => $b->subject,
                'message' => $b->message,
            ])->all(),
            'actions' => $actions->map(fn (Activity $a) => [
                'date' => $date($a->done_at ?? $a->due_at),
                'type' => $label($a->type),
                'objet' => $a->subject,
                'statut' => Activity::STATUS_LABELS[$a->status()],
                'resultat' => $label($a->result),
                'motif' => $a->purpose,
                'compte_rendu' => $a->report,
                'suite_a_donner' => $a->next_steps,
                'lieu' => $a->location,
                'supprimee' => $a->trashed(),
            ])->all(),
            'notes' => $notes->map(fn (Note $n) => [
                'date' => $date($n->created_at),
                'texte' => $n->body,
                'supprimee' => $n->trashed(),
            ])->all(),
            'consentements' => $consents->map(fn (Consent $c) => [
                'canal' => $c->channel->label(),
                'accorde' => $c->granted,
                'source' => $c->source,
                'enregistre_le' => $date($c->recorded_at),
            ])->all(),
            'pieces_jointes' => $files->map(fn (Attachment $f) => [
                'nom' => $f->original_name,
                'type' => $f->mime,
                'taille_octets' => $f->size,
                'ajoutee_le' => $date($f->created_at),
                'supprimee' => $f->trashed(),
            ])->all(),
            // Sections of other modules (Sessions: « inscriptions », « dossiers »).
            ...FicheLinks::export($person->id),
            'fiches_fusionnees' => Person::withTrashed()->where('merged_into_id', $person->id)->orderBy('id')->pluck('id')->all(),
            'dates' => [
                'creee_le' => $date($person->created_at),
                'modifiee_le' => $date($person->updated_at),
                'dernier_contact' => $date($person->last_contact_at),
            ],
        ];
    }

    /**
     * Brochures of each sending: title and file (name of the version sent).
     *
     * @param  EloquentCollection<int, BrochureSending>  $sendings
     * @return array<string, list<array{titre: string, version: string|null}>> sending id => brochures
     */
    private static function sentVersions(EloquentCollection $sendings): array
    {
        $links = DB::table('brochure_sending_versions')->whereIn('brochure_sending_id', $sendings->pluck('id')->all())
            ->orderBy('position')->get(['brochure_sending_id', 'brochure_version_id'])->groupBy('brochure_sending_id');
        $ids = $links->flatten(1)->pluck('brochure_version_id')->unique()->values()->all();
        $titles = app(Brochures::class)->sent($ids);
        $files = collect(app(Brochures::class)->versions($ids))->keyBy('version_id');

        $rows = [];
        foreach ($links as $sendingId => $versions) {
            foreach ($versions as $link) {
                $rows[$sendingId][] = [
                    'titre' => $titles[$link->brochure_version_id]['title'] ?? '',
                    'version' => $files[$link->brochure_version_id]['original_name'] ?? null,
                ];
            }
        }

        return $rows;
    }
}
