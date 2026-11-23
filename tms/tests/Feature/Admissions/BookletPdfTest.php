<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Booklet\BookletSettings;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\TrainingModules;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Trainers\Trainer;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
    Company::factory()->default()->create(['name' => 'CAP FORMATION', 'phone' => '02 28 44 32 14']);
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $this->html = [];
    $this->merged = 0;
    Http::fake(['gotenberg:3000/*' => function (HttpRequest $request) {
        if (str_ends_with($request->url(), '/merge')) {
            $this->merged++;

            return Http::response('%PDF-1.7 livret complet', 200, ['Content-Type' => 'application/pdf']);
        }
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $this->html[] = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 partie', 200, ['Content-Type' => 'application/pdf']);
    }]);
});

/** An admitted apprentice of « Employé polyvalent en restauration », with contract, dossier, séances and a trainer. */
function bookletApprentice(): Candidature
{
    $training = adTraining('Employé polyvalent en restauration');
    $training->update(['competences' => Competences::parse("AT Préparer et dresser des entrées\nCP Réaliser la mise en place des entrées\n- Peser les produits\n")]);
    TrainingModules::sync($training, [['title' => 'Mise en place', 'duration_hours' => '50'], ['title' => 'Dressage', 'duration_hours' => '42']]);

    $session = app(SessionWriter::class)->create([
        'training_id' => $training->id, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-03-31', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
    $person = adPerson('Durand', ['is_individual' => true, 'address_line1' => '3 rue des Lilas', 'postal_code' => '44000', 'city' => 'Nantes', 'mobile' => '06 11 22 33 44']);
    $c = adCandidature([
        'person_id' => $person->id, 'training_id' => $training->id, 'target_session_id' => $session->id,
        'legal_representative' => ['last_name' => 'Durand', 'first_name' => 'Marc', 'relationship' => 'Père', 'phone' => '06 99 88 77 66', 'email' => null],
    ]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());
    $employer = Organisation::query()->create(['name' => 'Hôtel du Parc', 'city' => 'Nantes', 'phone' => '02 40 00 00 00']);
    app(AlternanceWriter::class)->save($c->fresh(), [
        'contract_type' => 'apprenticeship', 'employer_organisation_id' => $employer->id,
        'tutor_person_id' => adPerson('Martin', ['first_name' => 'Paul', 'birth_date' => null, 'email' => 'paul.martin@example.test'])->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-03-31',
        'opco_organisation_id' => Organisation::query()->create(['name' => 'AKTO'])->id,
    ], admin());
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Admitted, admin());
    $c = $c->fresh();
    $defaults = app(DossierCreation::class)->defaults($c);
    app(DossierCreation::class)->create($c, [...$defaults, 'funding' => [...$defaults['funding'], 'funding_type_id' => crmItem(ReferenceList::FundingType, 'OPCO')->id, 'amount' => '6000.00']], admin());
    $c->forceFill(['dispensed_module_ids' => [$training->modules()->where('title', 'Dressage')->value('id')]])->save();

    $module = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 0, 'title' => 'Pratique culinaire', 'planned_minutes' => 840, 'dating_mode' => 'seances']);
    $trainer = Trainer::query()->create(['person_id' => adPerson('Cauret', ['first_name' => 'Laurent', 'birth_date' => null])->id, 'status' => 'employee']);
    foreach (['2027-01-05', '2027-01-06', '2027-02-02'] as $day) {
        Seance::query()->create(['training_session_id' => $session->id, 'session_module_id' => $module->id, 'date' => $day,
            'starts_at' => '08:30', 'ends_at' => '12:30', 'trainer_id' => $trainer->id]);
    }

    return $c->fresh();
}

it('prints the booklet already filled: partners, pathway, planning, monthly pages and grids', function () {
    BookletSettings::save(['team' => [['role' => 'Responsable de formation', 'name' => 'Franck Gardie-Rondeau', 'phone' => null, 'email' => null]], 'director_note' => 'Fabrice Robert, directeur général']);
    $c = bookletApprentice();

    $response = $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('livret-apprentissage-')
        ->and($this->merged)->toBe(0)
        ->and($this->html)->toHaveCount(1);
    $html = $this->html[0];
    $missing = array_values(array_filter([
        'Employé polyvalent en restauration', 'Léa', 'DURAND', '3 rue des Lilas 44000 Nantes', '06 11 22 33 44',
        'Marc', 'Père', 'Hôtel du Parc', 'Paul Martin', 'paul.martin@example.test', 'AKTO', 'CAP FORMATION',
        'Franck Gardie-Rondeau', 'Fabrice Robert, directeur général', 'Laurent Cauret', 'Pratique culinaire',
        'Mise en place', 'Dressage', 'Janvier 2027', 'Février 2027', 'Mars 2027', 'Travaux effectués en entreprise',
        'Travaux effectués en centre de formation', 'Réaliser la mise en place des entrées', 'Peser les produits',
        '04/01/2027', '31/03/2027',
    ], fn (string $text) => ! str_contains($html, e($text))));
    expect($missing)->toBe([]);
    // Centre pages only for the months with séances: January (8 h) and February (4 h), not March.
    expect(substr_count($html, 'Travaux effectués en centre de formation'))->toBe(2)
        ->and(substr_count($html, 'Travaux effectués en entreprise'))->toBe(3)
        ->and($html)->toContain('12 h');
});

it('merges the annexes of the organisme after the pathway and before the monthly pages', function () {
    $this->actingAs(admin(twoFactor: true));
    BookletSettings::addAnnex('Règlement intérieur', UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\nannexe"));
    $c = bookletApprentice();

    $this->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertOk()->assertContent('%PDF-1.7 livret complet');
    expect($this->merged)->toBe(1)->and($this->html)->toHaveCount(2)
        ->and($this->html[0])->toContain('Planning')->and($this->html[0])->not->toContain('Travaux effectués')
        ->and($this->html[1])->toContain('Travaux effectués en entreprise');
});

it('needs a contract and the right to see the candidature', function () {
    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/livret.pdf")
        ->assertStatus(409);
    $this->actingAs(adUser(['crm.opportunities.view' => null]))->get("/admissions/candidatures/{$c->id}/livret.pdf")->assertNotFound();
});
