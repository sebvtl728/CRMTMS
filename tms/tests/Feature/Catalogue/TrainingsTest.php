<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

afterEach(fn () => TrainingUsage::flush());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trainingPayload(array $overrides = []): array
{
    return [
        'code' => 'haccp-3j',
        'title' => 'HACCP 3 jours',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21',
        'duration_days' => '3',
        'audience' => 'Équipes de cuisine',
        'prerequisites' => 'Aucun',
        'price_company' => '1 250,50',
        'price_individual' => '990',
        'funding_mode_ids' => [crmItem(ReferenceList::FundingMode, 'CPF')->id, crmItem(ReferenceList::FundingMode, 'OPCO')->id],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeTraining(string $title, array $attributes = []): Training
{
    return Training::query()->create([
        'title' => $title,
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        ...$attributes,
    ]);
}

it('lets an administrator create, edit, deactivate and delete a training', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin)->get('/catalogue/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/form')->where('training', null)->has('options.das', 4)->has('options.funding_mode', 7));

    $this->post('/catalogue/create', trainingPayload())->assertRedirect('/catalogue');

    $training = Training::query()->sole();
    expect($training->code)->toBe('HACCP-3J')
        ->and($training->title)->toBe('HACCP 3 jours')
        ->and($training->duration_hours)->toBe('21.00')
        ->and($training->duration_days)->toBe('3.00')
        ->and($training->price_company)->toBe('1250.50')
        ->and($training->price_individual)->toBe('990.00')
        ->and($training->is_active)->toBeTrue()
        ->and($training->created_by)->toBe($admin->id)
        ->and($training->fundingModes()->pluck('reference_items.label')->sort()->values()->all())->toBe(['CPF', 'OPCO'])
        ->and(AuditLog::query()->where('event', 'created')->where('auditable_type', 'training')->count())->toBe(1);

    $this->get("/catalogue/{$training->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/form')
            ->where('training.code', 'HACCP-3J')
            ->where('training.price_company', '1250.50')
            ->where('training.funding_mode_ids', fn ($ids) => count($ids) === 2));

    $this->put("/catalogue/{$training->id}/edit", trainingPayload([
        'title' => 'HACCP 2 jours', 'duration_hours' => '14,5', 'price_individual' => '', 'funding_mode_ids' => [],
    ]))->assertRedirect('/catalogue');

    $training->refresh();
    expect($training->title)->toBe('HACCP 2 jours')
        ->and($training->duration_hours)->toBe('14.50')
        ->and($training->price_individual)->toBeNull()
        ->and($training->fundingModes()->count())->toBe(0);

    $this->post("/catalogue/{$training->id}/toggle")->assertRedirect();
    expect($training->refresh()->is_active)->toBeFalse();
    $this->post("/catalogue/{$training->id}/toggle")->assertRedirect();
    expect($training->refresh()->is_active)->toBeTrue();

    $this->delete("/catalogue/{$training->id}")->assertRedirect('/catalogue');
    expect(Training::query()->count())->toBe(0)
        ->and(Training::withTrashed()->count())->toBe(1);
});

it('needs catalogue.delete to delete a training', function () {
    $training = makeTraining('Hygiène');

    $this->actingAs(userWithPermissions(['catalogue.view', 'catalogue.manage']));
    $this->get('/catalogue')->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('can.delete', false));
    $this->delete("/catalogue/{$training->id}")->assertForbidden();
    expect(Training::query()->count())->toBe(1);

    $this->actingAs(direction(twoFactor: true))->get('/catalogue')->assertInertia(fn (Assert $page) => $page->where('can.delete', true));
    $this->delete("/catalogue/{$training->id}")->assertRedirect('/catalogue');
    expect(Training::query()->count())->toBe(0);
});

it('lets direction manage the catalogue', function () {
    $this->actingAs(direction(twoFactor: true))->post('/catalogue/create', trainingPayload())->assertRedirect('/catalogue');

    expect(Training::query()->count())->toBe(1);
});

it('lets a commercial read the catalogue but not change it', function () {
    $training = makeTraining('Hygiène');

    $this->actingAs(commercial())->get('/catalogue')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/index')
            ->has('trainings.data', 1)
            ->where('can.manage', false)
            ->where('can.export', true));

    $this->get('/catalogue/create')->assertForbidden();
    $this->post('/catalogue/create', trainingPayload())->assertForbidden();
    $this->get("/catalogue/{$training->id}/edit")->assertForbidden();
    $this->put("/catalogue/{$training->id}/edit", trainingPayload())->assertForbidden();
    $this->post("/catalogue/{$training->id}/toggle")->assertForbidden();
    $this->delete("/catalogue/{$training->id}")->assertForbidden();

    expect(Training::query()->count())->toBe(1)
        ->and($training->refresh()->is_active)->toBeTrue();
});

it('grants catalogue.view to every crm role and catalogue.manage to admin and direction', function () {
    expect(Permissions::all())->toContain('catalogue.view', 'catalogue.manage');

    foreach (Role::cases() as $role) {
        $defaults = Permissions::defaultsFor($role);
        expect($defaults)->toContain('catalogue.view');
        expect(in_array('catalogue.manage', $defaults, true))->toBe(in_array($role, [Role::AdminOrganisme, Role::Direction], true));
    }

    foreach ([Role::Assistant, Role::ResponsableDas, Role::ChargeRelations] as $role) {
        $this->actingAs(userWithRole($role))->get('/catalogue')->assertOk();
    }
});

it('keeps the code unique per organisme among trainings not deleted', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', trainingPayload())->assertRedirect();

    $this->post('/catalogue/create', trainingPayload(['code' => ' Haccp-3J ', 'title' => 'Autre']))
        ->assertSessionHasErrors(['code' => 'Ce code est déjà utilisé par une autre formation.']);
    expect(Training::query()->count())->toBe(1);

    // Another organisme may use the same code.
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    app(TenantContext::class)->run($other, fn () => Training::query()->create([
        'code' => 'HACCP-3J', 'title' => 'Chez eux', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id,
    ]));
    inTenant();

    // Editing a training keeps its own code.
    $training = Training::query()->sole();
    $this->put("/catalogue/{$training->id}/edit", trainingPayload(['title' => 'Renommée']))->assertRedirect('/catalogue');

    // Reusable once the training is deleted.
    $training->delete();
    $this->post('/catalogue/create', trainingPayload(['title' => 'Nouvelle version']))->assertRedirect();
    expect(Training::query()->sole()->title)->toBe('Nouvelle version');

    // The database refuses a duplicate even without the validation.
    expect(fn () => makeTraining('Doublon', ['code' => 'HACCP-3J']))->toThrow(QueryException::class);
});

it('reads amounts and durations typed the french way and refuses the others', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/catalogue/create', trainingPayload(['price_company' => '1 250,50 €', 'price_individual' => '1 200,00', 'duration_days' => '2,5']))
        ->assertRedirect();
    $training = Training::query()->sole();
    expect($training->price_company)->toBe('1250.50')
        ->and($training->price_individual)->toBe('1200.00')
        ->and($training->duration_days)->toBe('2.50');

    $this->post('/catalogue/create', trainingPayload(['code' => 'X1', 'price_company' => 'douze', 'price_individual' => '-5', 'duration_hours' => '1,234']))
        ->assertSessionHasErrors(['price_company', 'price_individual', 'duration_hours']);
    $this->post('/catalogue/create', trainingPayload(['code' => 'X2', 'title' => '', 'das_id' => null]))
        ->assertSessionHasErrors(['title', 'das_id']);
    $this->post('/catalogue/create', trainingPayload(['code' => str_repeat('A', 21)]))
        ->assertSessionHasErrors('code');
    expect(Training::query()->count())->toBe(1);
});

it('refuses an inactive das or funding mode for a new value but keeps the stored ones', function () {
    $this->actingAs(admin(twoFactor: true));
    $das = crmItem(ReferenceList::Das, 'Métiers supports');
    $cpf = crmItem(ReferenceList::FundingMode, 'CPF');
    $training = makeTraining('Bureautique', ['das_id' => $das->id]);
    $training->fundingModes()->sync([$cpf->id => ['tenant_id' => tenant()->id]]);
    $das->update(['is_active' => false]);
    $cpf->update(['is_active' => false]);

    $this->post('/catalogue/create', trainingPayload(['das_id' => $das->id, 'funding_mode_ids' => [$cpf->id]]))
        ->assertSessionHasErrors(['das_id', 'funding_mode_ids.0']);

    $this->put("/catalogue/{$training->id}/edit", trainingPayload(['code' => '', 'das_id' => $das->id, 'funding_mode_ids' => [$cpf->id]]))
        ->assertRedirect('/catalogue');
    expect($training->refresh()->code)->toBeNull();
});

it('marks the das and funding modes used by a training as in use', function () {
    $cpf = crmItem(ReferenceList::FundingMode, 'CPF');
    $das = crmItem(ReferenceList::Das, 'Compétences transversales');
    expect(ReferenceUsage::isInUse($das))->toBeFalse()
        ->and(ReferenceUsage::isInUse($cpf))->toBeFalse();

    $training = makeTraining('Management', ['das_id' => $das->id]);
    $training->fundingModes()->sync([$cpf->id => ['tenant_id' => tenant()->id]]);

    expect(ReferenceUsage::isInUse($das))->toBeTrue()
        ->and(ReferenceUsage::isInUse($cpf))->toBeTrue();
});

it('refuses to delete a training another module uses', function () {
    $used = makeTraining('Utilisée');
    $free = makeTraining('Libre');
    $seen = [];
    TrainingUsage::register('test.lines', function (string $trainingId) use ($used, &$seen): bool {
        $seen[] = $trainingId;

        return $trainingId === $used->id;
    });

    $this->actingAs(admin(twoFactor: true))->from('/catalogue')->delete("/catalogue/{$used->id}")
        ->assertRedirect('/catalogue')
        ->assertSessionHasErrors(['training' => 'Cette formation est utilisée (opportunités, devis, sessions) : désactivez-la plutôt.']);
    expect($used->refresh()->trashed())->toBeFalse()
        ->and($seen)->toBe([$used->id]);

    $this->delete("/catalogue/{$free->id}")->assertRedirect('/catalogue');
    expect($free->refresh()->trashed())->toBeTrue()
        ->and(TrainingUsage::isUsed($used->id))->toBeTrue()
        ->and(TrainingUsage::isUsed($free->id))->toBeFalse();
});

it('offers active trainings only as choices but still finds an inactive one', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $active = makeTraining('Hygiène alimentaire', ['code' => 'HYG', 'price_company' => '1200', 'price_individual' => '800.5', 'duration_hours' => '14', 'duration_days' => '2']);
    $inactive = makeTraining('Ancienne hygiène', ['code' => 'OLD', 'is_active' => false]);
    makeTraining('Bureautique', ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);

    $trainings = app(Trainings::class);
    expect(array_map(fn (TrainingData $t) => $t->title, $trainings->options()))->toBe(['Bureautique', 'Hygiène alimentaire'])
        ->and(array_map(fn (TrainingData $t) => $t->title, $trainings->search('hygiene', null)))->toBe(['Hygiène alimentaire'])
        ->and(array_map(fn (TrainingData $t) => $t->title, $trainings->search('', $das->id)))->toBe(['Hygiène alimentaire'])
        ->and($trainings->search('hyg', crmItem(ReferenceList::Das, 'Métiers supports')->id))->toBe([]);

    $data = $trainings->find($active->id);
    expect($data)->toBeInstanceOf(TrainingData::class)
        ->and($data?->code)->toBe('HYG')
        ->and($data?->das_id)->toBe($das->id)
        ->and($data?->das_label)->toBe('Hôtellerie-restauration')
        ->and($data?->duration_hours)->toBe('14.00')
        ->and($data?->duration_days)->toBe('2.00')
        ->and($data?->price_company_cents)->toBe(120000)
        ->and($data?->price_individual_cents)->toBe(80050)
        ->and($data?->is_active)->toBeTrue();

    expect($trainings->find($inactive->id)?->is_active)->toBeFalse()
        ->and($trainings->find($inactive->id)?->price_company_cents)->toBeNull()
        ->and($trainings->find('not-a-uuid'))->toBeNull();

    $inactive->delete();
    expect($trainings->find($inactive->id))->toBeNull();
});

it('filters and sorts the list', function () {
    $cpf = crmItem(ReferenceList::FundingMode, 'CPF');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    $a = makeTraining('Accueil client', ['code' => 'C', 'price_company' => '300']);
    $b = makeTraining('Bureautique', ['code' => 'A', 'das_id' => $supports->id, 'price_company' => '100']);
    makeTraining('Cuisine', ['code' => 'B', 'is_active' => false, 'price_company' => '200']);
    $b->fundingModes()->sync([$cpf->id => ['tenant_id' => tenant()->id]]);
    $a->fundingModes()->sync([$cpf->id => ['tenant_id' => tenant()->id]]);

    $this->actingAs(commercial());
    $titles = fn (string $query) => collect($this->get('/catalogue?'.$query)->assertOk()->original->getData()['page']['props']['trainings']['data'])->pluck('title')->all();

    expect($titles(''))->toBe(['Accueil client', 'Bureautique', 'Cuisine'])
        ->and($titles('q=bureau'))->toBe(['Bureautique'])
        ->and($titles('q=b'))->toBe(['Bureautique', 'Cuisine'])
        ->and($titles('das='.$supports->id))->toBe(['Bureautique'])
        ->and($titles('status=active'))->toBe(['Accueil client', 'Bureautique'])
        ->and($titles('status=inactive'))->toBe(['Cuisine'])
        ->and($titles('funding='.$cpf->id))->toBe(['Accueil client', 'Bureautique'])
        ->and($titles('sort=code'))->toBe(['Bureautique', 'Cuisine', 'Accueil client'])
        ->and($titles('sort=price_company&dir=desc'))->toBe(['Accueil client', 'Cuisine', 'Bureautique'])
        ->and($titles('sort=title&dir=desc'))->toBe(['Cuisine', 'Bureautique', 'Accueil client'])
        ->and($titles('sort=password&das[]=x'))->toBe(['Accueil client', 'Bureautique', 'Cuisine']);

    $row = collect($this->get('/catalogue?q=bureau')->original->getData()['page']['props']['trainings']['data'])->sole();
    expect($row)->toMatchArray([
        'code' => 'A', 'das' => 'Métiers supports', 'price_company' => '100,00', 'is_active' => true, 'funding_modes' => ['CPF'],
    ]);
});
