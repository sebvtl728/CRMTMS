<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\TrainingModules;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Trainers\Trainer;

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
