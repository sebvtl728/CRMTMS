<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingDuplicator;
use App\Modules\Catalogue\TrainingExport;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\CustomFields\CustomFieldDefinition;
use App\Modules\Core\CustomFields\CustomFieldDefinitions;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function tcfField(array $data): CustomFieldDefinition
{
    return app(CustomFieldDefinitions::class)->create('training', $data);
}

function tcfTraining(array $attributes = []): Training
{
    return Training::query()->create(['title' => 'HACCP', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, ...$attributes]);
}

/** The validation errors $fn throws, keyed by field. */
function tcfErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

it('offers short text, long text and choice list to the trainings, apart from the candidature fields', function () {
    tcfField(['label' => 'Lieu', 'type' => 'text']);
    tcfField(['label' => 'Matériel', 'type' => 'textarea']);
    tcfField(['label' => 'Niveau', 'type' => 'choice', 'options' => ['Initiation', 'Perfectionnement']]);
    app(CustomFieldDefinitions::class)->create('candidature', ['label' => 'Permis', 'type' => 'text']);

    expect(app(CustomFieldDefinitions::class)->active('training')->pluck('label')->all())->toBe(['Lieu', 'Matériel', 'Niveau'])
        ->and(app(CustomFieldDefinitions::class)->active('candidature')->pluck('label')->all())->toBe(['Permis']);

    foreach (['number', 'date', 'boolean', 'file', 'nir'] as $type) {
        expect(tcfErrors(fn () => tcfField(['label' => 'X', 'type' => $type]))['type'][0])->toBe('Type non proposé pour ces fiches.');
    }
    expect(tcfErrors(fn () => app(CustomFieldDefinitions::class)->create('inconnu', ['label' => 'X', 'type' => 'text'])))->toHaveKey('entity');
});

it('manages the training fields on the admin screen, in their own tab', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/admin/custom-fields', ['entity' => 'training', 'label' => 'Lieu', 'type' => 'text', 'required' => true])->assertRedirect();
    $field = CustomFieldDefinition::query()->sole();
    expect($field->entity)->toBe('training')->and($field->required_for_admission)->toBeTrue();

    $this->get('/admin/custom-fields?entity=training')->assertInertia(fn (Assert $p) => $p
        ->component('admin/custom-fields')
        ->where('entity', 'training')
        ->where('fields.0.label', 'Lieu')
        ->where('fields.0.required', true)
        ->has('types', 3)
        ->where('entities.1.value', 'training')
        ->where('entities.1.label', 'Formations'));
    $this->get('/admin/custom-fields')->assertInertia(fn (Assert $p) => $p->where('entity', 'candidature')->has('fields', 0));
    $this->get('/admin/custom-fields?entity=inconnu')->assertNotFound();

    $other = tcfField(['label' => 'Niveau', 'type' => 'text']);
    $this->post('/admin/custom-fields/reorder', ['entity' => 'training', 'ids' => [$other->id, $field->id]])->assertRedirect();
    expect(app(CustomFieldDefinitions::class)->active('training')->pluck('label')->all())->toBe(['Niveau', 'Lieu']);

    $this->actingAs(userWithPermissions(['catalogue.manage']))->get('/admin/custom-fields?entity=training')->assertForbidden();
});

it('fills the fields in the Présentation card of the form and shows them on the page', function () {
    $lieu = tcfField(['label' => 'Lieu', 'type' => 'text', 'required' => true]);
    $niveau = tcfField(['label' => 'Niveau', 'type' => 'choice', 'options' => ['Initiation', 'Perfectionnement']]);
    $notes = tcfField(['label' => 'Matériel', 'type' => 'textarea']);
    $this->actingAs(admin(twoFactor: true));
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id;

    $this->get('/catalogue/create')->assertInertia(fn (Assert $p) => $p
        ->where('custom_fields.0.label', 'Lieu')->where('custom_fields.0.required', true)
        ->where('custom_fields.1.options', ['Initiation', 'Perfectionnement']));

    $this->post('/catalogue/create', ['title' => 'HACCP', 'das_id' => $das, 'custom_values' => [$niveau->id => 'Expert']])
        ->assertSessionHasErrors(["custom_values.{$lieu->id}", "custom_values.{$niveau->id}"]);

    $this->post('/catalogue/create', ['title' => 'HACCP', 'das_id' => $das, 'custom_values' => [
        $lieu->id => ' Marseille ', $niveau->id => 'Initiation', $notes->id => '', 'inconnu' => 'x',
    ]])->assertRedirect('/catalogue');
    $training = Training::query()->sole();
    expect($training->custom_values)->toBe([$lieu->id => 'Marseille', $niveau->id => 'Initiation']);

    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.custom_values', [$lieu->id => 'Marseille', $niveau->id => 'Initiation']));
    $this->get("/catalogue/{$training->id}")->assertInertia(fn (Assert $p) => $p
        ->where('training.custom_fields', [['label' => 'Lieu', 'value' => 'Marseille'], ['label' => 'Niveau', 'value' => 'Initiation'], ['label' => 'Matériel', 'value' => null]]));

    // An update without the key keeps the values; an empty value removes the key.
    $this->put("/catalogue/{$training->id}/edit", ['title' => 'HACCP 2', 'das_id' => $das])->assertRedirect('/catalogue');
    expect($training->fresh()->custom_values)->toBe([$lieu->id => 'Marseille', $niveau->id => 'Initiation']);
    $this->put("/catalogue/{$training->id}/edit", ['title' => 'HACCP 2', 'das_id' => $das, 'custom_values' => [$lieu->id => 'Lyon', $niveau->id => '']])->assertRedirect('/catalogue');
    expect($training->fresh()->custom_values)->toBe([$lieu->id => 'Lyon']);

    // A hidden field is neither asked nor shown, its value stays.
    app(CustomFieldDefinitions::class)->hide($lieu);
    $this->get("/catalogue/{$training->id}")->assertInertia(fn (Assert $p) => $p->has('training.custom_fields', 2));
    $this->put("/catalogue/{$training->id}/edit", ['title' => 'HACCP 2', 'das_id' => $das, 'custom_values' => [$niveau->id => 'Initiation']])->assertRedirect('/catalogue');
    expect($training->fresh()->custom_values)->toBe([$lieu->id => 'Lyon', $niveau->id => 'Initiation']);
});

it('keeps a used training field: hidden, never deleted, type and used choices fixed', function () {
    $niveau = tcfField(['label' => 'Niveau', 'type' => 'choice', 'options' => ['Initiation', 'Perfectionnement']]);
    $training = tcfTraining(['custom_values' => [$niveau->id => 'Initiation']]);
    $training->delete();

    try {
        app(CustomFieldDefinitions::class)->delete($niveau);
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)
            ->and($e->getMessage())->toBe('Ce champ est rempli sur des formations : masquez-le plutôt que de le supprimer.');
    }
    expect(tcfErrors(fn () => app(CustomFieldDefinitions::class)->update($niveau, ['type' => 'text']))['type'][0])
        ->toBe('Le type ne peut plus changer : des formations ont une valeur.')
        ->and(tcfErrors(fn () => app(CustomFieldDefinitions::class)->update($niveau, ['options' => ['Perfectionnement']]))['options'][0])
        ->toBe('Ce choix est utilisé par des formations : masquez le champ plutôt que de le retirer.');

    $unused = tcfField(['label' => 'Inutile', 'type' => 'text']);
    app(CustomFieldDefinitions::class)->delete($unused);
    expect(CustomFieldDefinition::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('exports the training fields as columns and copies them with the training', function () {
    $lieu = tcfField(['label' => 'Lieu', 'type' => 'text']);
    $training = tcfTraining(['custom_values' => [$lieu->id => 'Marseille']]);
    $this->actingAs(admin(twoFactor: true));

    $export = new TrainingExport;
    expect($export->columns())->toMatchArray(["custom:{$lieu->id}" => 'Lieu'])
        ->and($export->value($training, "custom:{$lieu->id}"))->toBe('Marseille')
        ->and($export->value($training, 'custom:inconnu'))->toBeNull();
    $this->get('/catalogue/export?'.http_build_query(['columns' => ['title', "custom:{$lieu->id}"]]))->assertOk();

    $copy = app(TrainingDuplicator::class)->duplicate($training);
    expect($copy->custom_values)->toBe([$lieu->id => 'Marseille']);
});

it('prints the filled training fields in the programme PDF', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $html = null;
    Http::fake(['gotenberg:3000/*' => function (HttpRequest $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files' && ($part['filename'] ?? null) === 'index.html') {
                $html = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 programme', 200, ['Content-Type' => 'application/pdf']);
    }]);
    Company::factory()->default()->create(['name' => 'CAP FORMATION']);
    $lieu = tcfField(['label' => 'Lieu de formation', 'type' => 'text']);
    $empty = tcfField(['label' => 'Champ vide', 'type' => 'text']);
    $training = tcfTraining(['custom_values' => [$lieu->id => 'Marseille <centre>']]);

    $this->actingAs(userWithPermissions(['catalogue.view']))->get("/catalogue/{$training->id}/programme.pdf")->assertOk();

    expect($html)->toContain('Lieu de formation')->toContain('Marseille &lt;centre&gt;')->not->toContain('Champ vide');
});
