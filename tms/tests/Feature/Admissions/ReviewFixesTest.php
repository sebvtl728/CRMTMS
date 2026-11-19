<?php

use App\Modules\Admissions\Candidatures\CandidatureListQuery;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\People\Person;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('saves the orientation of a candidate whose CRM fiche is in the trash or anonymised', function () {
    $c = adCandidature();
    $person = Person::query()->findOrFail($c->person_id);
    $person->delete();

    $this->actingAs(commercial())->put("/admissions/candidatures/{$c->id}", [
        'rncp_code' => 'RNCP11111', 'education_level_id' => null, 'professional_situation_id' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($c->fresh()->rncp_code)->toBe('RNCP11111');

    Person::withTrashed()->whereKey($person->id)->update(['anonymised_at' => now()]);
    $this->put("/admissions/candidatures/{$c->id}", ['last_diploma' => 'CAP', 'education_level_id' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect($c->fresh()->last_diploma)->toBe('CAP');

    // Changing the profile of such a person is refused, in French.
    $this->put("/admissions/candidatures/{$c->id}", ['education_level_id' => crmItem(ReferenceList::EducationLevel, 'Niveau 3 (CAP, BEP)')->id])
        ->assertSessionHasErrors(['education_level_id' => 'La fiche de cette personne est à la corbeille ou anonymisée : son niveau et sa situation ne changent plus.']);
});

it('finds a candidate by name among many namesakes', function () {
    foreach (range(1, 60) as $i) {
        makePerson('Martin', ['first_name' => 'Aaron'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }
    $zoe = adCandidature(['person_id' => makePerson('Martin', ['first_name' => 'Zoé'])->id]);

    expect(CandidatureListQuery::build(admin(), CandidatureListQuery::filters(['q' => 'martin zoé']))->pluck('candidatures.id')->all())->toBe([$zoe->id])
        ->and(CandidatureListQuery::build(admin(), CandidatureListQuery::filters(['q' => 'martin']))->pluck('candidatures.id')->all())->toBe([$zoe->id]);

    // A candidate whose fiche is in the trash is still found.
    Person::query()->whereKey($zoe->person_id)->delete();
    expect(CandidatureListQuery::build(admin(), CandidatureListQuery::filters(['q' => 'zoé']))->pluck('candidatures.id')->all())->toBe([$zoe->id]);
});

it('keeps a user limited to his DAS from moving a candidature to another DAS', function () {
    $user = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das, Permissions::CANDIDATURES_UPDATE => AccessScope::Das]);
    followDas($user, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $c = adCandidature();
    $other = adTraining('Paie et RH', 'RNCP22222', 'Métiers supports');

    try {
        app(CandidatureWriter::class)->update($c, ['training_id' => $other->id], $user);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['training_id'][0])->toBe('Cette formation relève d\'un DAS que vous ne suivez pas.');
    }
    expect($c->fresh()->training_id)->toBe(adTraining()->id);

    // With a training, the DAS is the training's.
    $c = app(CandidatureWriter::class)->update($c, ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id], admin());
    expect($c->das_id)->toBe(adTraining()->das_id);
});
