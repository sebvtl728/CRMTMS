<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Gdpr\AnonymiseExpired;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\GdprPending;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Gdpr\RetentionPolicy;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions as CrmPermissions;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Gdpr\FicheReferences;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';

beforeEach(function () {
    inTenant();
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true));
});

// ---------------------------------------------------------------- anonymisation

it('clears the adaptation needs and notes of her enrolments and the notes of her fundings and own dossiers', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'is_individual' => true]);
    $other = makePerson('Martin');
    $session = l2Session();
    $hotel = l2Dossier();
    $mine = l2Enrol($hotel, $session, $lea, ['adaptation_needs' => 'Fauteuil roulant', 'notes' => 'Allergie']);
    $theirs = l2Enrol($hotel, $session, $other, ['adaptation_needs' => 'Malentendant', 'notes' => 'Autre']);
    $own = l2Dossier($lea, null, ['notes' => 'Particulier en reconversion']);
    $hotel->forceFill(['contact_person_id' => $lea->id, 'notes' => 'Dossier de l\'hôtel'])->save();
    $paidByHer = l2Funding($own, ['funder_organisation_id' => null, 'funder_person_id' => $lea->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Personnel')->id, 'notes' => 'Paie en 3 fois']);
    $forHer = l2Funding($hotel, ['enrolment_id' => $mine->id, 'notes' => 'Prise en charge de Léa']);
    $forOther = l2Funding($hotel, ['enrolment_id' => $theirs->id, 'notes' => 'Reste']);

    app(Anonymiser::class)->anonymise($lea, 'request');

    $mine->refresh();
    expect($mine->adaptation_needs)->toBeNull()->and($mine->notes)->toBeNull()
        // The enrolment itself stays (statistics, BPF, invoicing).
        ->and($mine->status)->toBe(EnrolmentStatus::Enrolled)->and($mine->person_id)->toBe($lea->id)
        ->and($theirs->refresh()->adaptation_needs)->toBe('Malentendant')->and($theirs->notes)->toBe('Autre')
        ->and($paidByHer->refresh()->notes)->toBeNull()->and($paidByHer->amount)->toBe('600.00')
        ->and($forHer->refresh()->notes)->toBeNull()
        ->and($forOther->refresh()->notes)->toBe('Reste')
        ->and($own->refresh()->notes)->toBeNull()
        // An entreprise's dossier keeps its notes; she stays its contact (anonymised).
        ->and($hotel->refresh()->notes)->toBe('Dossier de l\'hôtel');

    // The journal of the anonymisation holds no value.
    expect(DB::table('audit_logs')->where('new_values', 'like', '%Fauteuil%')->exists())->toBeFalse();
});

// ---------------------------------------------------------------- retention

/** A person whose last contact (and creation) is $years years old. */
function l2Aged(string $name, int $years, array $attributes = []): Person
{
    $person = makePerson($name, $attributes);
    $at = now()->subYears($years)->subDay();
    DB::table('people')->where('id', $person->id)->update(['created_at' => $at, 'last_contact_at' => $at]);

    return $person->refresh();
}

/** @return list<string> */
function l2Expired(): array
{
    return RetentionPolicy::expiredPeople()->orderBy('last_name')->pluck('last_name')->all();
}

it('never lists for the RGPD retention a person enrolled on a session of the period', function () {
    $enrolled = l2Aged('Inscrite', 6);
    $cancelled = l2Aged('Annulee', 6);
    $old = l2Aged('Ancienne', 6);
    $hotel = l2Dossier();
    DB::table('dossiers')->where('id', $hotel->id)->update(['created_at' => now()->subYears(6)]);
    l2Enrol($hotel, l2Session(), $enrolled);
    app(Enrolments::class)->cancel(l2Enrol($hotel, l2Session(), $cancelled));
    // A session finished 4 years ago, beyond the 3 years of the organisme.
    $past = l2Session();
    DB::table('training_sessions')->where('id', $past->id)->update(['starts_on' => '2022-11-01', 'ends_on' => '2022-11-03']);
    l2Enrol($hotel, $past->refresh(), $old);
    // A session finished 2 years ago, within the period.
    $recent = l2Aged('Recente', 6);
    $within = l2Session();
    DB::table('training_sessions')->where('id', $within->id)->update(['starts_on' => '2024-11-01', 'ends_on' => '2024-11-03']);
    l2Enrol($hotel, $within->refresh(), $recent);

    expect(l2Expired())->toBe(['Ancienne', 'Annulee']);
});

it('never lists the particulier, the contact or the funder of a dossier of the period', function () {
    $particulier = l2Aged('Particulier', 6, ['is_individual' => true]);
    $contact = l2Aged('Contact', 6);
    $funder = l2Aged('Financeur', 6);
    $oldContact = l2Aged('AncienContact', 6);
    $own = l2Dossier($particulier);
    $own->forceFill(['contact_person_id' => $contact->id])->save();
    l2Funding($own, ['funder_organisation_id' => null, 'funder_person_id' => $funder->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Personnel')->id]);
    $old = l2Dossier();
    $old->forceFill(['contact_person_id' => $oldContact->id])->save();
    DB::table('dossiers')->where('id', $old->id)->update(['created_at' => now()->subYears(4)]);

    expect(l2Expired())->toBe(['AncienContact']);

    DB::table('dossiers')->where('id', $own->id)->update(['created_at' => now()->subYears(4)]);
    DB::table('fundings')->where('dossier_id', $own->id)->update(['created_at' => now()->subYears(4)]);
    expect(l2Expired())->toBe(['AncienContact', 'Contact', 'Financeur', 'Particulier']);
});

it('takes off the pending list a person enrolled since she was listed', function () {
    Mail::fake();
    $lea = l2Aged('Durand', 6);
    AnonymiseExpired::dispatch();
    expect(GdprPending::query()->pluck('person_id')->all())->toBe([$lea->id]);

    l2Enrol(l2Dossier(), l2Session(), $lea);
    AnonymiseExpired::dispatch();

    expect(GdprPending::query()->count())->toBe(0)->and($lea->refresh()->anonymised_at)->toBeNull();
});

// ---------------------------------------------------------------- RGPD export

it('lists her enrolments and her dossiers in the RGPD export, complete', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'is_individual' => true]);
    $session = l2Session(null, ['kind' => 'inter']);
    $hotel = l2Dossier();
    $hotel->forceFill(['contact_person_id' => $lea->id])->save();
    $enrolment = l2Enrol($hotel, $session, $lea, ['adaptation_needs' => 'Fauteuil roulant']);
    app(Enrolments::class)->abandon($enrolment, CarbonImmutable::parse('2026-12-08'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    $own = l2Dossier($lea);
    $own->delete();

    $export = PersonDataExport::for($lea->refresh());

    expect($export['inscriptions'])->toHaveCount(1)
        ->and($export['inscriptions'][0])->toMatchArray([
            'session' => $session->number, 'formation' => 'HACCP 3 jours',
            'debut_session' => '2026-12-07', 'fin_session' => '2026-12-09',
            'dossier' => $hotel->number, 'statut' => 'Abandon',
            'entree' => '2026-12-07', 'sortie' => '2026-12-08', 'motif_abandon' => 'Maladie',
            'besoins_adaptation' => 'Fauteuil roulant',
        ])
        ->and(collect($export['dossiers'])->map(fn (array $d) => [$d['numero'], $d['role'], $d['supprime']])->all())->toBe([
            [$hotel->number, 'Contact', false],
            [$own->number, 'Contractant (particulier)', true],
        ]);
});

it('adds to the RGPD export her BPF category, her share of the price, her fundings and the lines of her own dossiers', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'is_individual' => true]);
    $own = l2Dossier($lea);
    l2Enrol($own, l2Session(), $lea, ['bpf_category_id' => crmItem(ReferenceList::BpfCategory, 'Particuliers')->id]);
    l2Funding($own, [
        'funder_organisation_id' => null, 'funder_person_id' => $lea->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Personnel')->id,
        'amount' => '400,00', 'status' => 'agreed', 'agreement_number' => 'ACC-42', 'agreed_on' => '2026-11-10', 'notes' => 'Paie en 2 fois',
    ]);
    // A funding by an entreprise is not hers.
    l2Funding($own, ['amount' => '100,00']);

    $export = PersonDataExport::for($lea->refresh());

    expect($export['inscriptions'][0])->toMatchArray(['categorie_bpf' => 'Particuliers', 'part_du_prix' => '1000.00'])
        ->and($export['financements'])->toBe([[
            'dossier' => $own->number, 'type' => 'Personnel', 'montant' => '400.00', 'etat' => 'Accordé',
            'numero_accord' => 'ACC-42', 'date_accord' => '2026-11-10', 'notes' => 'Paie en 2 fois',
        ]])
        ->and($export['dossiers'][0])->toMatchArray(['numero' => $own->number, 'montant_ht' => '1000.00'])
        ->and($export['dossiers'][0]['lignes'])->toBe([[
            'libelle' => 'HACCP 3 jours', 'quantite' => '1.00', 'unite' => 'forfait', 'prix_unitaire' => '1000.00', 'remise_pourcent' => '0.00', 'total_ht' => '1000.00',
        ]]);
});

it('keeps the export to users who see the whole organisme in the dossiers and sessions too', function () {
    $crmAll = [CrmPermissions::GDPR => null];
    foreach (CrmPermissions::views() as $view) {
        $crmAll[$view] = null;
    }
    $lea = makePerson('Durand');

    $withoutSessions = l2User($crmAll);
    $dasSessions = l2User([...$crmAll, Permissions::DOSSIERS_VIEW => null, Permissions::SESSIONS_VIEW => AccessScope::Das]);
    // The candidatures too (Admissions): their export holds the sensitive data.
    $everything = l2User([...$crmAll, Permissions::DOSSIERS_VIEW => null, Permissions::SESSIONS_VIEW => null,
        App\Modules\Admissions\Permissions::CANDIDATURES_VIEW => null, App\Modules\Admissions\Permissions::SENSITIVE_VIEW => null, App\Modules\Documents\Permissions::VIEW => null]);

    expect(PersonDataExport::allowedFor($withoutSessions))->toBeFalse()
        ->and(PersonDataExport::allowedFor($dasSessions))->toBeFalse()
        ->and(PersonDataExport::allowedFor($everything))->toBeTrue();
    $this->actingAs($everything)->get("/crm/people/{$lea->id}/gdpr-export")->assertOk();
});

// ---------------------------------------------------------------- import undo (LinkedData)

it('keeps a person enrolled on a session and an entreprise of a session when an import is undone', function () {
    $batch = runImport("Nom;Prénom\nDurand;Léa\nMartin;Paul\n", ImportTarget::People);
    $orgBatch = runImport("Raison sociale\nHôtel du Lac\nCafé des Arts\n", ImportTarget::Organisations);
    $lea = Person::query()->where('last_name', 'Durand')->sole();
    $lac = Organisation::query()->where('name', 'Hôtel du Lac')->sole();
    l2Enrol(l2Dossier(), l2Session(), $lea);
    l2Session(null, ['kind' => 'intra', 'organisation_id' => $lac->id, 'capacity' => null]);

    expect(LinkedData::keys())->toContain('sessions.references');

    $this->travel(1)->minutes();
    UndoImport::dispatch($batch->id);
    UndoImport::dispatch($orgBatch->id);

    expect(Person::query()->pluck('last_name')->all())->toBe(['Durand'])
        ->and(Organisation::query()->where('name', 'Hôtel du Lac')->exists())->toBeTrue()
        ->and(Organisation::query()->where('name', 'Café des Arts')->exists())->toBeFalse()
        ->and(rowMessages($batch, 2))->toBe([UndoImport::KEPT_LINKED]);
});

it('sees a dossier, a funder and a contact as linked data', function () {
    $particulier = makePerson('Durand', ['is_individual' => true]);
    $contact = makePerson('Martin');
    $funder = Organisation::query()->create(['name' => 'OPCO Akto']);
    $nothing = makePerson('Personne');
    $dossier = l2Dossier($particulier);
    $dossier->forceFill(['contact_person_id' => $contact->id])->save();
    l2Funding($dossier, ['funder_organisation_id' => $funder->id]);

    expect(LinkedData::any($particulier))->toBeTrue()
        ->and(LinkedData::any($contact))->toBeTrue()
        ->and(LinkedData::any($funder))->toBeTrue()
        ->and(LinkedData::any($nothing))->toBeFalse();
});

// ---------------------------------------------------------------- merge

it('repoints sessions, dossiers, enrolments and fundings to the kept fiche on a merge', function () {
    $keepOrg = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $dropOrg = Organisation::query()->create(['name' => 'Hotel du parc']);
    $session = l2Session(null, ['kind' => 'intra', 'organisation_id' => $dropOrg->id, 'capacity' => null]);
    $dossier = l2Dossier($dropOrg);
    $funding = l2Funding($dossier, ['funder_organisation_id' => $dropOrg->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Entreprise')->id]);

    $keepPerson = makePerson('Durand', ['first_name' => 'Léa', 'is_individual' => true]);
    $dropPerson = makePerson('Durand', ['first_name' => 'Lea', 'is_individual' => true]);
    $enrolment = l2Enrol($dossier, $session, $dropPerson);
    $own = l2Dossier($dropPerson);
    $dossier->forceFill(['contact_person_id' => $dropPerson->id])->save();
    $paid = l2Funding($own, ['funder_organisation_id' => null, 'funder_person_id' => $dropPerson->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Personnel')->id]);

    app(Merger::class)->organisations($keepOrg, $dropOrg, []);
    app(Merger::class)->people($keepPerson, $dropPerson, []);

    expect($session->refresh()->organisation_id)->toBe($keepOrg->id)
        ->and($dossier->refresh()->account_organisation_id)->toBe($keepOrg->id)
        ->and($dossier->contact_person_id)->toBe($keepPerson->id)
        ->and($funding->refresh()->funder_organisation_id)->toBe($keepOrg->id)
        ->and($enrolment->refresh()->person_id)->toBe($keepPerson->id)
        ->and($own->refresh()->account_person_id)->toBe($keepPerson->id)
        ->and($paid->refresh()->funder_person_id)->toBe($keepPerson->id);
});

it('refuses to merge two people enrolled on the same session, writing nothing', function () {
    $keep = makePerson('Durand', ['first_name' => 'Léa']);
    $drop = makePerson('Durand', ['first_name' => 'Lea']);
    $session = l2Session();
    $dossier = l2Dossier();
    l2Enrol($dossier, $session, $keep);
    $dropped = l2Enrol($dossier, $session, $drop);

    try {
        app(Merger::class)->people($keep, $drop, []);
        $this->fail('Merged two trainees of the same session.');
    } catch (ValidationException $e) {
        expect($e->errors()['drop'][0])->toBe(str_replace(':session', $session->number, FicheReferences::SAME_SESSION));
    }
    expect($drop->refresh()->merged_into_id)->toBeNull()->and($dropped->refresh()->person_id)->toBe($drop->id);

    // Once one of the two enrolments is cancelled, the merge goes through.
    app(Enrolments::class)->cancel($dropped);
    app(Merger::class)->people($keep, $drop, []);
    expect($dropped->refresh()->person_id)->toBe($keep->id)
        ->and(Enrolment::query()->where('person_id', $keep->id)->count())->toBe(2);
});

// ---------------------------------------------------------------- trash

it('lets a trainee go to the trash: her enrolments stay and show her as trashed', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $session = l2Session();
    $enrolment = l2Enrol(l2Dossier(), $session, $lea);

    $this->delete("/crm/people/{$lea->id}")->assertRedirect();

    expect($lea->refresh()->trashed())->toBeTrue()
        ->and($enrolment->refresh()->status)->toBe(EnrolmentStatus::Enrolled);
    $this->get("/sessions/{$session->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('enrolments.0.person.trashed', true));

    $this->post("/crm/people/{$lea->id}/restore")->assertRedirect();
    expect($lea->refresh()->trashed())->toBeFalse();
});
