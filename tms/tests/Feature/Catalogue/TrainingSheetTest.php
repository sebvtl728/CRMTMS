<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingExport;
use App\Modules\Catalogue\TrainingModule;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sheetPayload(array $overrides = []): array
{
    return [
        'title' => 'HACCP 3 jours',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21',
        'description' => 'Maîtriser l’hygiène alimentaire.',
        'objectives' => ['Appliquer la méthode HACCP', 'Tenir un plan de maîtrise sanitaire'],
        'methods' => 'Apports théoriques et cas pratiques.',
        'evaluation' => 'QCM final.',
        'sanction' => 'Attestation de fin de formation.',
        'accessibility' => 'Locaux accessibles PMR.',
        'access_terms' => 'Inscription jusqu’à 7 jours avant.',
        'results' => 'Satisfaction 4,6/5.',
        ...$overrides,
    ];
}

it('stores and reads every field of the training sheet', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', sheetPayload())->assertRedirect('/catalogue');

    $t = Training::query()->sole();
    expect($t->description)->toBe('Maîtriser l’hygiène alimentaire.')
        ->and($t->objectives)->toBe(['Appliquer la méthode HACCP', 'Tenir un plan de maîtrise sanitaire'])
        ->and($t->methods)->toBe('Apports théoriques et cas pratiques.')
        ->and($t->evaluation)->toBe('QCM final.')
        ->and($t->sanction)->toBe('Attestation de fin de formation.')
        ->and($t->accessibility)->toBe('Locaux accessibles PMR.')
        ->and($t->access_terms)->toBe('Inscription jusqu’à 7 jours avant.')
        ->and($t->results)->toBe('Satisfaction 4,6/5.');

    $this->get("/catalogue/{$t->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.objectives', ['Appliquer la méthode HACCP', 'Tenir un plan de maîtrise sanitaire'])
        ->where('training.methods', 'Apports théoriques et cas pratiques.')
        ->where('training.results', 'Satisfaction 4,6/5.')
        ->has('options.modality', 4)
        ->has('training.modules', 0));
});

it('keeps objectives ordered, trimmed, at most 20 of 500 characters', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', sheetPayload(['objectives' => ['  B  ', '', 'A']]))->assertRedirect('/catalogue');
    expect(Training::query()->sole()->objectives)->toBe(['B', 'A']);

    $this->post('/catalogue/create', sheetPayload(['title' => 'X', 'objectives' => array_fill(0, 21, 'o')]))
        ->assertSessionHasErrors('objectives');
    $this->post('/catalogue/create', sheetPayload(['title' => 'Y', 'objectives' => [str_repeat('a', 501)]]))
        ->assertSessionHasErrors('objectives.0');
    expect(Training::query()->count())->toBe(1);
});

it('creates, reorders, edits and removes modules with a modality', function () {
    $this->actingAs(admin(twoFactor: true));
    $pres = crmItem(ReferenceList::TrainingModality, 'Présentiel')->id;
    $dist = crmItem(ReferenceList::TrainingModality, 'Distanciel (classe virtuelle)')->id;

    $this->post('/catalogue/create', sheetPayload(['modules' => [
        ['title' => 'Bases', 'duration_hours' => '7', 'modality_id' => $pres, 'content' => 'Dangers'],
        ['title' => 'Pratique', 'duration_hours' => '14', 'modality_id' => $dist, 'content' => null],
    ]]))->assertRedirect('/catalogue');

    $t = Training::query()->sole();
    $mods = app(Trainings::class)->modules($t->id);
    expect($mods)->toHaveCount(2)
        ->and($mods[0])->toMatchArray(['title' => 'Bases', 'duration_hours' => '7.00', 'modality_id' => $pres, 'content' => 'Dangers', 'position' => 0])
        ->and($mods[1]['title'])->toBe('Pratique')
        ->and($mods[1]['position'])->toBe(1)
        ->and(array_keys($mods[0]))->toBe(['id', 'title', 'duration_hours', 'modality_id', 'content', 'position']);

    // Reorder + edit one + drop none, by id.
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [
        ['id' => $mods[1]['id'], 'title' => 'Pratique 2', 'duration_hours' => '14', 'modality_id' => $dist],
        ['id' => $mods[0]['id'], 'title' => 'Bases', 'duration_hours' => '7', 'modality_id' => $pres],
    ]]))->assertRedirect('/catalogue');
    $after = app(Trainings::class)->modules($t->id);
    expect(array_column($after, 'title'))->toBe(['Pratique 2', 'Bases'])
        ->and($after[0]['id'])->toBe($mods[1]['id']);

    // Removing a module from the list deletes it.
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [
        ['id' => $mods[0]['id'], 'title' => 'Bases', 'duration_hours' => '7'],
    ]]))->assertRedirect('/catalogue');
    expect(app(Trainings::class)->modules($t->id))->toHaveCount(1);

    // Modules left untouched when the key is absent.
    $this->put("/catalogue/{$t->id}/edit", sheetPayload())->assertRedirect('/catalogue');
    expect(TrainingModule::query()->count())->toBe(1);

    // An empty list (or null) clears them.
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => []]))->assertRedirect('/catalogue');
    expect(TrainingModule::query()->count())->toBe(0);
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [['title' => 'Z']]]))->assertRedirect('/catalogue');
    expect(TrainingModule::query()->count())->toBe(1);
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => null]))->assertRedirect('/catalogue');
    expect(TrainingModule::query()->count())->toBe(0);
});

it('validates modules: title, duration, modality, count, foreign ids', function () {
    $this->actingAs(admin(twoFactor: true));
    $other = Training::query()->create(['title' => 'Autre', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    $foreign = TrainingModule::query()->create(['training_id' => $other->id, 'position' => 0, 'title' => 'M']);

    $this->post('/catalogue/create', sheetPayload(['modules' => [['title' => '', 'duration_hours' => 'abc']]]))
        ->assertSessionHasErrors(['modules.0.title', 'modules.0.duration_hours']);
    $this->post('/catalogue/create', sheetPayload(['modules' => [['title' => 'M', 'modality_id' => '0190c000-0000-7000-8000-000000000000']]]))
        ->assertSessionHasErrors('modules.0.modality_id');
    $this->post('/catalogue/create', sheetPayload(['modules' => array_fill(0, 31, ['title' => 'M'])]))
        ->assertSessionHasErrors('modules');

    // Crafted ids: not a uuid, or an id on creation, are refused (422), not a 500.
    $this->post('/catalogue/create', sheetPayload(['modules' => [['id' => 'abc', 'title' => 'M']]]))->assertSessionHasErrors('modules.0.id');
    $this->post('/catalogue/create', sheetPayload(['modules' => [['id' => $foreign->id, 'title' => 'M']]]))->assertSessionHasErrors('modules.0.id');
    expect(Training::query()->count())->toBe(1);

    $t = Training::query()->create(['title' => 'T', 'das_id' => $other->das_id]);
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [['id' => 'abc', 'title' => 'M']]]))->assertSessionHasErrors('modules.0.id');
    // The same id twice, an id the training owns: only `distinct` can refuse it.
    $owned = TrainingModule::query()->create(['training_id' => $t->id, 'position' => 0, 'title' => 'Mien']);
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [['id' => $owned->id, 'title' => 'a'], ['id' => $owned->id, 'title' => 'b']]]))->assertSessionHasErrors('modules.0.id');
    expect($owned->refresh()->title)->toBe('Mien');
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['modules' => [['id' => $foreign->id, 'title' => 'Volé']]]))
        ->assertSessionHasErrors('modules.0.id');
    expect($foreign->refresh()->title)->toBe('M');
});

it('warns, without blocking, when the modules do not add up to the duration', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', sheetPayload(['duration_hours' => '21', 'modules' => [['title' => 'A', 'duration_hours' => '7']]]))
        ->assertRedirect('/catalogue');
    $t = Training::query()->sole();

    $this->get("/catalogue/{$t->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.modules_hours', '7.00')
        ->where('training.modules_gap', true));

    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['duration_hours' => '7', 'modules' => [['title' => 'A', 'duration_hours' => '7']]]));
    $this->get("/catalogue/{$t->id}/edit")->assertInertia(fn (Assert $p) => $p->where('training.modules_gap', false));
});

it('refuses to delete a modality used by a module', function () {
    $this->actingAs(admin(twoFactor: true));
    $pres = crmItem(ReferenceList::TrainingModality, 'Présentiel');
    $this->post('/catalogue/create', sheetPayload(['modules' => [['title' => 'A', 'modality_id' => $pres->id]]]));

    expect(ReferenceUsage::isInUse($pres))->toBeTrue();
});

it('journals the sheet changes without the module content', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', sheetPayload(['modules' => [['title' => 'Bases', 'duration_hours' => '7', 'content' => 'secret contenu']]]));

    expect(AuditLog::query()->where('auditable_type', 'training_module')->where('event', 'created')->count())->toBe(1)
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('secret contenu');
    $t = Training::query()->sole();
    $this->put("/catalogue/{$t->id}/edit", sheetPayload(['methods' => 'Autre']));
    expect(AuditLog::query()->where('auditable_type', 'training')->where('event', 'updated')->count())->toBe(1);
});

it('needs catalogue.manage to change the sheet', function () {
    $t = Training::query()->create(['title' => 'T', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    $this->actingAs(userWithPermissions(['catalogue.view']));
    $this->put("/catalogue/{$t->id}/edit", sheetPayload())->assertForbidden();
    $this->post('/catalogue/create', sheetPayload())->assertForbidden();
});

it('exports the sheet columns', function () {
    $t = Training::query()->create(['title' => 'T', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'objectives' => ['A', 'B'], 'methods' => 'M']);
    $export = new TrainingExport;
    expect($export->columns())->toHaveKeys(['description', 'objectives', 'methods', 'evaluation', 'sanction', 'accessibility', 'access_terms', 'results'])
        ->and($export->value($t, 'objectives'))->toBe("A\nB")
        ->and($export->value($t, 'methods'))->toBe('M');
});
