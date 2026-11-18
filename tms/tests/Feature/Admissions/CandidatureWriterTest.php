<?php

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\People\Person;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('creates a candidature numbered, identified, with the RNCP code of its training', function () {
    $by = admin();
    $c = adCandidature([], $by);

    expect($c->number)->toMatch('/^C-\d{4}-0001$/')
        ->and($c->status)->toBe(CandidatureStatus::Identified)
        ->and($c->created_by)->toBe($by->id)
        ->and($c->rncp_code)->toBe('RNCP36004')
        ->and($c->das_id)->toBe(adTraining()->das_id);
});

it('keeps an RNCP code typed by the user and proposes the new training\'s when the training changes', function () {
    $c = adCandidature(['rncp_code' => 'rncp 99999']);
    expect($c->rncp_code)->toBe('RNCP99999');

    $other = adTraining('BP Arts de la cuisine', 'RNCP12345');
    $c = app(CandidatureWriter::class)->update($c, ['training_id' => $other->id], admin());
    expect($c->rncp_code)->toBe('RNCP12345');

    $c = app(CandidatureWriter::class)->update($c, ['training_id' => adTraining()->id, 'rncp_code' => 'RNCP11111'], admin());
    expect($c->rncp_code)->toBe('RNCP11111');
});

it('needs a DAS when there is no training, and refuses unknown references', function () {
    expect(fn () => app(CandidatureWriter::class)->create(['person_id' => adPerson()->id], admin()))
        ->toThrow(ValidationException::class);

    try {
        app(CandidatureWriter::class)->create(['person_id' => adPerson()->id, 'training_id' => '0190a8c4-0000-7000-8000-000000000000'], admin());
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('training_id');
    }

    $c = app(CandidatureWriter::class)->create(['person_id' => adPerson()->id, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id], admin());
    expect($c->training_id)->toBeNull()->and($c->rncp_code)->toBeNull();
});

it('refuses an anonymised or unknown person', function () {
    $person = adPerson();
    Person::query()->whereKey($person->id)->update(['anonymised_at' => now()]);

    expect(fn () => adCandidature(['person_id' => $person->id]))->toThrow(ValidationException::class);
});

it('writes the education level and situation on the person, never on the candidature', function () {
    $person = adPerson();
    $level = crmItem(ReferenceList::EducationLevel, 'Niveau 3 (CAP, BEP)');
    adCandidature(['person_id' => $person->id, 'education_level_id' => $level->id]);

    expect($person->fresh()->education_level_id)->toBe($level->id);
});

it('ignores the sensitive data of a user without the right, and keeps them out of the audit', function () {
    $commercial = commercial();
    $c = adCandidature(['rqth' => true, 'adaptation_needs' => 'Fauteuil roulant', 'interview_report' => 'RAS'], $commercial);
    expect($c->rqth)->toBeFalse()->and($c->adaptation_needs)->toBeNull()->and($c->interview_report)->toBeNull();

    $c = app(CandidatureWriter::class)->update($c, ['rqth' => true, 'adaptation_needs' => 'Fauteuil roulant', 'last_diploma' => 'Brevet'], direction());
    expect($c->rqth)->toBeTrue()->and($c->adaptation_needs)->toBe('Fauteuil roulant');

    $log = AuditLog::query()->where('auditable_id', $c->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($log->new_values['adaptation_needs'])->toBe('[donnée personnelle]')
        ->and($log->new_values['last_diploma'])->toBe('[donnée personnelle]');
});

it('stores the legal representative of a minor', function () {
    $c = adCandidature(['legal_representative' => ['last_name' => 'Durand', 'first_name' => 'Marc', 'relationship' => 'Père', 'phone' => '06 12 34 56 78', 'email' => 'marc@example.test']]);

    expect($c->legal_representative['relationship'])->toBe('Père');
});

it('creates and updates through the routes, within the rights', function () {
    $person = adPerson();
    $this->actingAs(commercial())
        ->post('/admissions/candidatures', ['person_id' => $person->id, 'training_id' => adTraining()->id])
        ->assertRedirect();
    $c = Candidature::query()->sole();

    $this->put("/admissions/candidatures/{$c->id}", ['last_diploma' => 'CAP Pâtissier'])->assertRedirect();
    expect($c->fresh()->last_diploma)->toBe('CAP Pâtissier');

    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))
        ->put("/admissions/candidatures/{$c->id}", ['last_diploma' => 'X'])->assertForbidden();
    $this->post('/admissions/candidatures', ['person_id' => $person->id, 'training_id' => adTraining()->id])->assertForbidden();
});

it('puts a candidature in the trash and restores it', function () {
    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->delete("/admissions/candidatures/{$c->id}")->assertRedirect();
    expect(Candidature::query()->count())->toBe(0);

    $this->post("/admissions/candidatures/{$c->id}/restore")->assertRedirect();
    expect(Candidature::query()->count())->toBe(1);

    $this->actingAs(commercial())->delete("/admissions/candidatures/{$c->id}")->assertForbidden();
});
