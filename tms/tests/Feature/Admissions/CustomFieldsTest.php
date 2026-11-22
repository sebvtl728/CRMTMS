<?php

use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Admissions\CustomFields\CustomFieldDefinition;
use App\Modules\Admissions\CustomFields\CustomFieldDefinitions;
use App\Modules\Admissions\CustomFields\CustomFieldValues;
use App\Modules\Core\Audit\AuditLog;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

function adField(array $data): CustomFieldDefinition
{
    return app(CustomFieldDefinitions::class)->create($data);
}

/** The validation errors $fn throws, keyed by field. */
function adErrors(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }
    test()->fail('expected a validation error');
}

it('creates a field of each type, in order, a choice needing its options', function () {
    foreach (['text', 'textarea', 'number', 'date', 'boolean', 'file'] as $type) {
        adField(['label' => "Champ {$type}", 'type' => $type]);
    }
    adField(['label' => 'Permis', 'type' => 'choice', 'options' => ['B', 'Aucun']]);

    expect(app(CustomFieldDefinitions::class)->active()->pluck('label')->all())
        ->toBe(['Champ text', 'Champ textarea', 'Champ number', 'Champ date', 'Champ boolean', 'Champ file', 'Permis']);

    expect(adErrors(fn () => adField(['label' => 'Vide', 'type' => 'choice', 'options' => []])))->toHaveKey('options');
    expect(adErrors(fn () => adField(['label' => '', 'type' => 'text'])))->toHaveKey('label');
});

it('validates and stores the values of a candidature', function () {
    $number = adField(['label' => 'Pointure', 'type' => 'number']);
    $permis = adField(['label' => 'Permis', 'type' => 'choice', 'options' => ['B', 'Aucun']]);
    $car = adField(['label' => 'Véhiculé', 'type' => 'boolean']);
    $date = adField(['label' => 'Disponible le', 'type' => 'date']);

    $c = adCandidature(['custom_values' => [$number->id => '42,5', $permis->id => 'B', $car->id => '1', $date->id => '2027-01-04', 'inconnu' => 'x']]);
    expect($c->custom_values)->toBe([$number->id => '42.5', $permis->id => 'B', $car->id => true, $date->id => '2027-01-04']);

    $errors = adErrors(fn () => app(CandidatureWriter::class)->update($c, ['custom_values' => [$number->id => 'beaucoup', $permis->id => 'A']], admin()));
    expect($errors["custom_values.{$number->id}"][0])->toBe('Le champ « Pointure » attend un nombre.')
        ->and($errors["custom_values.{$permis->id}"][0])->toBe('Choisissez une valeur de la liste « Permis ».');

    // An empty value removes the key, the others are kept.
    $c = app(CandidatureWriter::class)->update($c, ['custom_values' => [$number->id => '']], admin());
    expect($c->custom_values)->toBe([$permis->id => 'B', $car->id => true, $date->id => '2027-01-04']);

    $log = AuditLog::query()->where('auditable_id', $c->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($log->new_values['custom_values'])->toBe('[donnée personnelle]');
});

it('deletes an unused field, hides a used one', function () {
    $unused = adField(['label' => 'Inutile', 'type' => 'text']);
    app(CustomFieldDefinitions::class)->delete($unused);
    expect(CustomFieldDefinition::query()->whereKey($unused->id)->exists())->toBeFalse();

    $used = adField(['label' => 'Taille', 'type' => 'text']);
    adCandidature(['custom_values' => [$used->id => 'M']]);
    try {
        app(CustomFieldDefinitions::class)->delete($used);
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)
            ->and($e->getMessage())->toBe('Ce champ est rempli sur des candidatures : masquez-le plutôt que de le supprimer.');
    }

    app(CustomFieldDefinitions::class)->hide($used);
    expect(app(CustomFieldDefinitions::class)->active()->pluck('id')->all())->not->toContain($used->id);
    app(CustomFieldDefinitions::class)->unhide($used->fresh());
    expect(app(CustomFieldDefinitions::class)->active()->pluck('id')->all())->toContain($used->id);
});

it('a file field is used once a candidature holds its file', function () {
    $field = adField(['label' => 'CV', 'type' => 'file']);
    $c = adCandidature();
    CandidatureFile::query()->create(['candidature_id' => $c->id, 'kind' => "custom:{$field->id}", 'name' => 'cv.pdf', 'size' => 10, 'path' => 'x']);

    expect(fn () => app(CustomFieldDefinitions::class)->delete($field))->toThrow(HttpException::class);
});

it('refuses to change the type of a used field or to remove a used choice', function () {
    $permis = adField(['label' => 'Permis', 'type' => 'choice', 'options' => ['B', 'Aucun']]);
    adCandidature(['custom_values' => [$permis->id => 'B']]);

    expect(adErrors(fn () => app(CustomFieldDefinitions::class)->update($permis, ['type' => 'text']))['type'][0])
        ->toBe('Le type ne peut plus changer : des candidatures ont une valeur.');
    expect(adErrors(fn () => app(CustomFieldDefinitions::class)->update($permis, ['options' => ['Aucun']]))['options'][0])
        ->toBe('Ce choix est utilisé par des candidatures : masquez le champ plutôt que de le retirer.');

    $permis = app(CustomFieldDefinitions::class)->update($permis, ['label' => 'Permis de conduire', 'options' => ['B', 'Aucun', 'A']]);
    expect($permis->label)->toBe('Permis de conduire')->and($permis->options)->toBe(['B', 'Aucun', 'A']);
});

it('reorders the fields', function () {
    $a = adField(['label' => 'A', 'type' => 'text']);
    $b = adField(['label' => 'B', 'type' => 'text']);
    app(CustomFieldDefinitions::class)->reorder([$b->id, $a->id]);

    expect(app(CustomFieldDefinitions::class)->active()->pluck('label')->all())->toBe(['B', 'A']);
});

it('refuses the admission while a field required for it is empty, a hidden one apart', function () {
    $cv = adField(['label' => 'CV', 'type' => 'file', 'required_for_admission' => true]);
    $permis = adField(['label' => 'Permis', 'type' => 'choice', 'options' => ['B'], 'required_for_admission' => true]);
    $old = adField(['label' => 'Ancien', 'type' => 'text', 'required_for_admission' => true]);
    app(CustomFieldDefinitions::class)->hide($old);
    $c = adCandidature();

    $errors = adErrors(fn () => app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin()));
    expect($errors['custom_values'][0])->toBe('À remplir avant l\'admission : CV, Permis.');

    CandidatureFile::query()->create(['candidature_id' => $c->id, 'kind' => "custom:{$cv->id}", 'name' => 'cv.pdf', 'size' => 10, 'path' => 'x']);
    $c = app(CandidatureWriter::class)->update($c, ['custom_values' => [$permis->id => 'B']], admin());
    expect(CustomFieldValues::missingForAdmission($c))->toBe([]);

    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
    expect($c->fresh()->status)->toBe(CandidatureStatus::Admitted);
});

it('manages the fields through the admin screen, for settings.manage only', function () {
    $this->actingAs(admin(twoFactor: true))->post('/admin/custom-fields', ['label' => 'Permis', 'type' => 'choice', 'options' => ['B']])->assertRedirect();
    $field = CustomFieldDefinition::query()->sole();

    $this->get('/admin/custom-fields')->assertInertia(fn (Assert $p) => $p
        ->component('admin/custom-fields')
        ->where('fields.0.label', 'Permis')
        ->where('fields.0.used', false)
        ->has('types', 8));
    $this->put("/admin/custom-fields/{$field->id}", ['label' => 'Permis de conduire'])->assertRedirect();
    $this->post("/admin/custom-fields/{$field->id}/hide")->assertRedirect();
    expect($field->fresh()->hidden_at)->not->toBeNull();
    $this->delete("/admin/custom-fields/{$field->id}")->assertRedirect();
    expect(CustomFieldDefinition::query()->count())->toBe(0);

    $this->actingAs(commercial())->get('/admin/custom-fields')->assertForbidden();
});

it('shows the active fields with their value on the candidature page', function () {
    $permis = adField(['label' => 'Permis', 'type' => 'choice', 'options' => ['B']]);
    $c = adCandidature(['custom_values' => [$permis->id => 'B']]);

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('custom_fields.0.label', 'Permis')
        ->where('custom_fields.0.value', 'B')
        ->where('custom_fields.0.options', ['B']));
});

it('explains that a long identifier (social security number) belongs in a text field', function () {
    $number = adField(['label' => 'Numéro de sécurité sociale', 'type' => 'number']);
    $c = adCandidature();

    $errors = adErrors(fn () => app(CandidatureWriter::class)->update($c, ['custom_values' => [$number->id => '1 85 05 78 006 084 36']], admin()));
    expect($errors["custom_values.{$number->id}"][0])->toBe(
        'Le champ « Numéro de sécurité sociale » attend un nombre de 12 chiffres au plus. Pour un numéro (sécurité sociale, téléphone…), donnez-lui le type Texte dans Administration › Champs personnalisés.'
    );
});

it('checks a social security number: 15 characters, Corsica, control key', function () {
    $nir = adField(['label' => 'N° de sécurité sociale', 'type' => 'nir']);

    $c = adCandidature(['custom_values' => [$nir->id => '1 85 05 78 006 084 91']]);
    expect($c->custom_values[$nir->id])->toBe('185057800608491');

    $corse = adCandidature(['custom_values' => [$nir->id => '2 69 02 2a 005 123 33']]);
    expect($corse->custom_values[$nir->id])->toBe('269022A00512333');

    foreach (['185057800608490', '18505780060849', 'abcdefghijklmno', '585057800608491'] as $wrong) {
        $errors = adErrors(fn () => app(CandidatureWriter::class)->update($c, ['custom_values' => [$nir->id => $wrong]], admin()));
        expect($errors["custom_values.{$nir->id}"][0])->toBe('Le numéro de sécurité sociale « N° de sécurité sociale » n\'est pas valide : vérifiez les 15 chiffres, clé comprise.');
    }
});

it('keeps a social security number away from users without the sensitive data right', function () {
    $nir = adField(['label' => 'N° de sécurité sociale', 'type' => 'nir']);
    $c = adCandidature(['custom_values' => [$nir->id => '185057800608491']]);

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('custom_fields.0.type', 'nir')
        ->where('custom_fields.0.value', null)
        ->where('custom_fields.0.hidden', true));
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('custom_fields.0.value', '185057800608491')
        ->where('custom_fields.0.hidden', false));

    // Written without the right: ignored, the stored number stays.
    app(CandidatureWriter::class)->update($c, ['custom_values' => [$nir->id => '269022A00512333']], commercial());
    expect($c->refresh()->custom_values[$nir->id])->toBe('185057800608491');
});
