<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function levelId(string $code): string
{
    return ReferenceItem::query()->where('list', ReferenceList::EducationLevel)->where('code', $code)->firstOrFail()->id;
}

it('saves and shows the education level and the professional situation on the person form', function () {
    $person = makePerson('Martin');
    $level = levelId('niveau-5-bac-2');
    $situation = crmItem(ReferenceList::ProfessionalSituation, 'Salarié');
    $this->actingAs(commercial());

    $this->get("/crm/people/{$person->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->has('options.education_level', 7)->where('person.education_level_id', null));

    $this->put("/crm/people/{$person->id}", ['last_name' => 'Martin', 'education_level_id' => $level, 'professional_situation_id' => $situation->id])->assertRedirect();

    expect($person->fresh()->education_level_id)->toBe($level);
    $this->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->where('person.education_level.label', 'Niveau 5 (bac +2)')->where('person.professional_situation.label', 'Salarié'));

    $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', $person->id)->latest('created_at')->first();
    expect($log?->new_values)->toHaveKey('education_level_id');
});

it('rejects an education level that is not of the list', function () {
    $person = makePerson('Martin');
    $this->actingAs(commercial());

    $this->putJson("/crm/people/{$person->id}", ['last_name' => 'Martin', 'education_level_id' => crmItem(ReferenceList::ProfessionalSituation, 'Salarié')->id])
        ->assertJsonValidationErrors('education_level_id');
});

it('updates the training profile through the public service, journaled', function () {
    $person = makePerson('Durand', ['birth_date' => '1990-01-01']);
    $situation = crmItem(ReferenceList::ProfessionalSituation, "Demandeur d'emploi");

    app(Trainees::class)->updateProfile($person->id, ['education_level_id' => levelId('niveau-3-cap-bep'), 'professional_situation_id' => $situation->id, 'birth_date' => '1991-02-03']);

    $fresh = $person->fresh();
    expect($fresh->education_level_id)->toBe(levelId('niveau-3-cap-bep'))->and($fresh->professional_situation_id)->toBe($situation->id)
        ->and($fresh->birth_date->toDateString())->toBe('1991-02-03');

    $log = AuditLog::query()->where('auditable_id', $person->id)->latest('created_at')->firstOrFail();
    expect($log->new_values)->toHaveKeys(['education_level_id', 'professional_situation_id'])
        ->and(json_encode($log->new_values))->not->toContain('1991-02-03');

    // A null clears.
    app(Trainees::class)->updateProfile($person->id, ['education_level_id' => null, 'professional_situation_id' => null, 'birth_date' => null]);
    expect($person->fresh()->education_level_id)->toBeNull()->and($person->fresh()->birth_date)->toBeNull();
});

it('keeps the fields absent from the changes and refuses unknown keys', function () {
    $situation = crmItem(ReferenceList::ProfessionalSituation, 'Salarié');
    $person = makePerson('Partiel', ['birth_date' => '1990-01-01', 'professional_situation_id' => $situation->id, 'education_level_id' => levelId('niveau-3-cap-bep')]);
    $trainees = app(Trainees::class);

    $trainees->updateProfile($person->id, ['education_level_id' => levelId('niveau-7-bac-5')]);
    $fresh = $person->fresh();
    expect($fresh->education_level_id)->toBe(levelId('niveau-7-bac-5'))->and($fresh->professional_situation_id)->toBe($situation->id)
        ->and($fresh->birth_date->toDateString())->toBe('1990-01-01');

    $trainees->updateProfile($person->id, ['birth_date' => null]);
    expect($person->fresh()->birth_date)->toBeNull()->and($person->fresh()->professional_situation_id)->toBe($situation->id);

    expect(fn () => $trainees->updateProfile($person->id, ['last_name' => 'X']))->toThrow(InvalidArgumentException::class);
    expect($person->fresh()->last_name)->toBe('Partiel');
});

it('refuses an invalid profile in French', function () {
    $person = makePerson('Durand');
    $trainees = app(Trainees::class);

    $bad = fn (?string $level, ?string $situation, ?string $birth) => fn () => $trainees->updateProfile($person->id, ['education_level_id' => $level, 'professional_situation_id' => $situation, 'birth_date' => $birth]);

    expect($bad('pas-un-uuid', null, null))->toThrow(ValidationException::class);
    expect($bad(crmItem(ReferenceList::ProfessionalSituation, 'Salarié')->id, null, null))->toThrow(ValidationException::class);
    expect($bad(null, levelId('niveau-3-cap-bep'), null))->toThrow(ValidationException::class);
    expect($bad(null, null, '2999-01-01'))->toThrow(ValidationException::class, 'La date de naissance ne peut pas être dans le futur.');
    expect($bad(null, null, '01/02/1990'))->toThrow(ValidationException::class);

    // An inactive value is refused, except the one already stored.
    $level = ReferenceItem::query()->findOrFail(levelId('niveau-3-cap-bep'));
    $trainees->updateProfile($person->id, ['education_level_id' => $level->id]);
    $level->update(['is_active' => false]);
    $trainees->updateProfile($person->id, ['education_level_id' => $level->id]);
    expect($person->fresh()->education_level_id)->toBe($level->id);
    $other = ReferenceItem::query()->findOrFail(levelId('niveau-4-baccalaureat'));
    $other->update(['is_active' => false]);
    expect($bad($other->id, null, null))->toThrow(ValidationException::class);
});

it('does not touch an anonymised or unknown person', function () {
    $person = makePerson('Anonyme');
    Person::query()->whereKey($person->id)->update(['anonymised_at' => now()]);
    $trainees = app(Trainees::class);

    expect(fn () => $trainees->updateProfile($person->id, []))->toThrow(ModelNotFoundException::class);
    expect(fn () => $trainees->updateProfile('00000000-0000-7000-8000-000000000000', []))->toThrow(ModelNotFoundException::class);
    expect(fn () => $trainees->updateProfile('nope', []))->toThrow(ModelNotFoundException::class);
});

it('does not return an anonymised person from the fiches service', function () {
    $live = makePerson('Vivante');
    $anonymised = makePerson('Effacée');
    Person::query()->whereKey($anonymised->id)->update(['anonymised_at' => now()]);

    expect(app(Fiches::class)->person($live->id)['id'] ?? null)->toBe($live->id)
        ->and(app(Fiches::class)->person($anonymised->id))->toBeNull();
});
