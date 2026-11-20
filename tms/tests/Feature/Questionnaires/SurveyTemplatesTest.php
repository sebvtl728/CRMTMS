<?php

use App\Modules\Questionnaires\Permissions;
use App\Modules\Questionnaires\Settings\ColdDelay;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use App\Modules\Questionnaires\Templates\DefaultTemplates;
use App\Modules\Questionnaires\Templates\SurveyTemplates;

beforeEach(fn () => inTenant());

function surveyTemplatePayload(array $overrides = []): array
{
    return [
        'name' => 'À chaud court', 'kind' => 'hot', 'audience' => 'trainee', 'active' => true,
        'questions' => [['type' => 'rating', 'label' => 'Satisfaction globale', 'required' => true]],
        ...$overrides,
    ];
}

it('creates the three default templates once, the entreprise one inactive', function () {
    DefaultTemplates::ensure();
    DefaultTemplates::ensure();

    $templates = SurveyTemplate::query()->orderBy('name')->get();
    expect($templates)->toHaveCount(3)
        ->and($templates->where('is_default', true))->toHaveCount(3)
        ->and($templates->firstWhere('audience', 'client')->active)->toBeFalse()
        ->and(SurveyTemplates::defaultFor('hot', 'trainee')?->name)->toBe('À chaud — stagiaires')
        ->and(SurveyTemplates::defaultFor('cold', 'trainee')?->name)->toBe('À froid — stagiaires')
        ->and(SurveyTemplates::defaultFor('hot', 'client'))->toBeNull()
        ->and(collect(SurveyTemplates::defaultFor('hot', 'trainee')->questions)->pluck('type')->all())
        ->toBe(['rating', 'rating', 'rating', 'rating', 'rating', 'choice', 'text']);
});

it('creates nothing again once the organisme deleted its templates', function () {
    DefaultTemplates::ensure();
    SurveyTemplate::query()->get()->each->delete();
    DefaultTemplates::ensure();

    expect(SurveyTemplate::query()->count())->toBe(0);
});

it('keeps one default template per type and public', function () {
    $user = admin();
    DefaultTemplates::ensure();
    $mine = app(SurveyTemplates::class)->create(surveyTemplatePayload(), $user);

    app(SurveyTemplates::class)->setDefault($mine, $user);

    expect(SurveyTemplates::defaultFor('hot', 'trainee')?->id)->toBe($mine->id)
        ->and(SurveyTemplate::query()->where('kind', 'hot')->where('audience', 'trainee')->where('is_default', true)->count())->toBe(1);
});

it('refuses to make an inactive template the default one', function () {
    $template = app(SurveyTemplates::class)->create(surveyTemplatePayload(['active' => false]), admin());

    expect(fn () => app(SurveyTemplates::class)->setDefault($template, admin()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('manages the templates through the administration screen', function () {
    $this->actingAs(userWithPermissions([Permissions::MANAGE]));

    $this->get('/admin/questionnaires')->assertOk()->assertInertia(fn ($page) => $page->component('admin/questionnaires/index')
        ->has('templates', 3)->where('cold_months', 3));
    $this->post('/admin/questionnaires', surveyTemplatePayload(['name' => 'Mon modèle']))->assertRedirect();
    $template = SurveyTemplate::query()->where('name', 'Mon modèle')->sole();
    $this->put("/admin/questionnaires/{$template->id}", surveyTemplatePayload(['name' => 'Renommé', 'questions' => []]))
        ->assertSessionHasErrors('questions');
    $this->put("/admin/questionnaires/{$template->id}", surveyTemplatePayload(['name' => 'Renommé']))->assertRedirect();
    expect($template->fresh()->name)->toBe('Renommé');
    $this->delete("/admin/questionnaires/{$template->id}")->assertRedirect();
    expect(SurveyTemplate::query()->find($template->id))->toBeNull();
});

it('refuses the administration screen without the right', function () {
    $this->actingAs(userWithPermissions([Permissions::VIEW]));

    $this->get('/admin/questionnaires')->assertForbidden();
    $this->put('/admin/questionnaires/delai', ['months' => 6])->assertForbidden();
});

it('sets the delay of the cold questionnaire, 0 to 24 months', function () {
    $this->actingAs(userWithPermissions([Permissions::MANAGE]));

    expect(ColdDelay::months())->toBe(3);
    $this->put('/admin/questionnaires/delai', ['months' => 25])->assertSessionHasErrors('months');
    $this->put('/admin/questionnaires/delai', ['months' => 6])->assertRedirect();
    expect(ColdDelay::months())->toBe(6);
    $this->put('/admin/questionnaires/delai', ['months' => 0])->assertRedirect();
    expect(ColdDelay::months())->toBe(0);
});
