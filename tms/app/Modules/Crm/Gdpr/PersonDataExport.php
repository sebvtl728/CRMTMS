<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Interests\FicheTraining;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Visibility\CrmVisibility;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Everything the organisme holds about a person (right of access and
 * portability, GDPR art. 15 and 20): identity, contact details, links with
 * the entreprise names, opportunities (as particulier or contact: title,
 * stage, amount), quotes (as particulier or recipient: number, date, status,
 * total TTC), actions (hers or on her opportunities as particulier:
 * type, subject, status, result, report, place), notes, consents, attachments (listed, not their
 * content), trainings of interest (« Formations d'intérêt »), DAS and dates. Trashed items are included (still held), flagged.
 * Fiches merged into her are listed by id (their data now lives here).
 * Visibility rules apply: for a restricted viewer (Responsable DAS), only
 * the links to entreprises he may see.
 */
final class PersonDataExport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Person $person, ?User $viewer = null): array
    {
        $person->loadMissing(['professionalSituation', 'fundingMode', 'origin', 'das']);
        $label = fn (?ReferenceItem $i) => $i?->label;
        $date = fn (?DateTimeInterface $d) => $d?->format(DateTimeInterface::ATOM);

        $links = Affiliation::withTrashed()->with(['organisation' => fn ($q) => $q->withTrashed(), 'decisionRole'])
            ->where('person_id', $person->id)
            ->when($viewer !== null && CrmVisibility::restricts($viewer), fn ($q) => $q->whereIn('organisation_id', CrmVisibility::organisations(Organisation::withTrashed(), $viewer)->select('organisations.id')))
            ->orderBy('created_at')->get();
        $notes = Note::withTrashed()->where('notable_type', $person->getMorphClass())->where('notable_id', $person->id)->orderBy('created_at')->get();
        $files = Attachment::withTrashed()->where('notable_type', $person->getMorphClass())->where('notable_id', $person->id)->orderBy('created_at')->get();
        $opportunities = Opportunity::withTrashed()->with('stage')
            ->where(fn ($q) => $q->where('person_id', $person->id)
                ->orWhereIn('id', DB::table('opportunity_contacts')->select('opportunity_id')->where('person_id', $person->id)))
            ->when($viewer !== null, fn ($q) => CrmVisibility::opportunities($q, $viewer))
            ->orderByRaw('person_id is null')->orderBy('created_at')->orderBy('id')->get();
        $actions = Activity::withTrashed()->with(['type', 'result'])
            ->where(fn ($q) => $q->where('person_id', $person->id)
                ->orWhereIn('opportunity_id', DB::table('opportunities')->select('id')->where('person_id', $person->id)))
            ->when($viewer !== null, fn ($q) => CrmVisibility::activities($q, $viewer))
            ->orderBy('happened_at')->orderBy('id')->get();
        $quotes = Quote::withTrashed()
            ->where(fn ($q) => $q->where('person_id', $person->id)->orWhere('contact_person_id', $person->id))
            ->when($viewer !== null, fn ($q) => CrmVisibility::quotes($q, $viewer))
            ->orderBy('issued_on')->orderBy('number')->get();
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
                'supprime' => $q->trashed(),
            ])->all(),
            'actions' => $actions->map(fn (Activity $a) => [
                'date' => $date($a->done_at ?? $a->due_at),
                'type' => $label($a->type),
                'objet' => $a->subject,
                'statut' => Activity::STATUS_LABELS[$a->status()],
                'resultat' => $label($a->result),
                'compte_rendu' => $a->report,
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
            'fiches_fusionnees' => Person::withTrashed()->where('merged_into_id', $person->id)->orderBy('id')->pluck('id')->all(),
            'dates' => [
                'creee_le' => $date($person->created_at),
                'modifiee_le' => $date($person->updated_at),
                'dernier_contact' => $date($person->last_contact_at),
            ],
        ];
    }
}
