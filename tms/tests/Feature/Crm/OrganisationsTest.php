<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Rules\Siret;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/** A 14-digit SIRET passing the Luhn check, derived from a 13-digit seed. */
function validSiret(int $seed = 7328293200007): string
{
    $base = str_pad((string) $seed, 13, '0', STR_PAD_LEFT);
    for ($check = 0; $check <= 9; $check++) {
        if (Siret::isValid($base.$check)) {
            return $base.$check;
        }
    }
    throw new RuntimeException('unreachable');
}

function ref(ReferenceList $list, string $label): ReferenceItem
{
    return ReferenceItem::query()->where('list', $list)->where('label', $label)->firstOrFail();
}

/** @param array<string, mixed> $attributes */
function org(string $name, array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => $name, ...$attributes]);
}

it('creates an entreprise with only a name', function () {
    $user = commercial();

    $this->actingAs($user)->post('/crm/organisations', ['name' => 'Hôtel du Lac'])->assertRedirect();

    $org = Organisation::query()->sole();
    expect($org->name)->toBe('Hôtel du Lac')
        ->and($org->status->value)->toBe('prospect')
        ->and($org->country)->toBe('FR')
        ->and($org->siret)->toBeNull()
        ->and($org->created_by)->toBe($user->id);
});

it('creates an entreprise with references and DAS', function () {
    $das = ref(ReferenceList::Das, 'Hôtellerie-restauration');

    $this->actingAs(commercial())->post('/crm/organisations', [
        'name' => 'Brasserie Lyon',
        'siret' => validSiret(),
        'naf_code' => '5610a',
        'sector_id' => ref(ReferenceList::Sector, 'Commerce')->id,
        'origin_id' => ref(ReferenceList::ContactOrigin, 'Salon')->id,
        'status' => 'client',
        'website' => 'www.brasserie.fr',
        'das_ids' => [$das->id],
    ])->assertRedirect();

    $org = Organisation::query()->with('das')->sole();
    expect($org->naf_code)->toBe('5610A')
        ->and($org->status->value)->toBe('client')
        ->and($org->website)->toBe('https://www.brasserie.fr')
        ->and($org->das->pluck('id')->all())->toBe([$das->id])
        ->and(DB::table('organisation_das')->where('organisation_id', $org->id)->value('tenant_id'))->toBe(tenant()->id);
});

it('rejects an invalid siret', function () {
    $this->actingAs(commercial())->postJson('/crm/organisations', ['name' => 'X', 'siret' => '12345678901234'])
        ->assertStatus(422)->assertJsonValidationErrors('siret');

    expect(Organisation::count())->toBe(0);
});

it('rejects a duplicate siret and points to the existing entreprise', function () {
    $existing = org('Première', ['siret' => validSiret()]);

    $response = $this->actingAs(commercial())->postJson('/crm/organisations', ['name' => 'Seconde', 'siret' => validSiret()])
        ->assertStatus(422)->assertJsonValidationErrors('siret');

    expect($response->json('errors.siret.0'))->toBe('Une entreprise avec ce SIRET existe déjà.')
        ->and($response->json('errors.siret_existing_id.0'))->toBe($existing->id);
});

it('accepts the same siret in another organisme and after a soft delete', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => org('Chez eux', ['siret' => validSiret()]));
    inTenant();

    $this->actingAs(commercial())->postJson('/crm/organisations', ['name' => 'Chez nous', 'siret' => validSiret()])->assertRedirect();

    $first = Organisation::query()->sole();
    $first->delete();

    $this->postJson('/crm/organisations', ['name' => 'Retour', 'siret' => validSiret()])->assertRedirect();
    expect(Organisation::count())->toBe(1);
});

it('lets the database refuse a duplicate siret even without the validation', function () {
    org('A', ['siret' => validSiret()]);

    expect(fn () => DB::transaction(fn () => org('B', ['siret' => validSiret()])))->toThrow(UniqueConstraintViolationException::class);
});

it('updates an entreprise and keeps its own siret valid', function () {
    $org = org('Ancien nom', ['siret' => validSiret()]);

    $this->actingAs(commercial())->put("/crm/organisations/{$org->id}", ['name' => 'Nouveau nom', 'siret' => validSiret(), 'city' => 'Lyon'])
        ->assertRedirect(route('crm.organisations.show', $org));

    expect($org->refresh()->name)->toBe('Nouveau nom')->and($org->city)->toBe('Lyon');
});

it('audits creation and DAS changes', function () {
    $das = ref(ReferenceList::Das, 'Métiers supports');
    $this->actingAs(commercial())->post('/crm/organisations', ['name' => 'Audit SA', 'das_ids' => [$das->id]]);
    $org = Organisation::query()->sole();

    expect(AuditLog::query()->where('event', 'created')->where('auditable_id', $org->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'crm.organisation_das_changed')->where('auditable_id', $org->id)->exists())->toBeTrue();
});

it('filters, searches and sorts the list', function () {
    $das = ref(ReferenceList::Das, 'Hôtellerie-restauration');
    $hotel = org('Hôtel Bellevue', ['city' => 'Lyon', 'status' => 'client', 'sector_id' => ref(ReferenceList::Sector, 'Commerce')->id, 'origin_id' => ref(ReferenceList::ContactOrigin, 'Salon')->id, 'last_contact_at' => now()->subDay()]);
    $hotel->das()->sync([$das->id => ['tenant_id' => tenant()->id]]);
    org('Boulangerie Dupont', ['city' => 'Paris', 'last_contact_at' => now()->subDays(10)]);
    org('Zèbre SARL', ['city' => 'Annecy']);
    $me = commercial();
    org('Chez moi', ['responsible_user_id' => $me->id]);

    $names = fn (string $query) => collect($this->actingAs($me)->get('/crm/organisations'.$query)->original->getData()['page']['props']['organisations']['data'])->pluck('name')->all();

    expect($names('?q=hotel'))->toBe(['Hôtel Bellevue'])
        ->and($names('?q=HÔTEL'))->toBe(['Hôtel Bellevue'])
        ->and($names('?q=annecy'))->toBe(['Zèbre SARL'])
        ->and($names('?status=client'))->toBe(['Hôtel Bellevue'])
        ->and($names('?das='.$das->id))->toBe(['Hôtel Bellevue'])
        ->and($names('?sector='.$hotel->sector_id))->toBe(['Hôtel Bellevue'])
        ->and($names('?origin='.$hotel->origin_id))->toBe(['Hôtel Bellevue'])
        ->and($names('?responsible='.$me->id))->toBe(['Chez moi'])
        ->and($names('?mine=1'))->toBe(['Chez moi'])
        ->and($names('?sort=name&dir=asc'))->toBe(['Boulangerie Dupont', 'Chez moi', 'Hôtel Bellevue', 'Zèbre SARL'])
        ->and($names('?sort=city&dir=asc'))->toBe(['Zèbre SARL', 'Hôtel Bellevue', 'Boulangerie Dupont', 'Chez moi'])
        ->and($names('?sort=last_contact_at&dir=desc'))->toBe(['Hôtel Bellevue', 'Boulangerie Dupont', 'Chez moi', 'Zèbre SARL'])
        ->and($names('?sort=nimporte'))->toHaveCount(4);

});

it('paginates by 25', function () {
    foreach (range(1, 30) as $i) {
        org(sprintf('Entreprise %02d', $i));
    }

    $this->actingAs(commercial())->get('/crm/organisations?sort=name&dir=asc')->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/index')
        ->has('organisations.data', 25)
        ->where('organisations.total', 30));
});

it('finds hotel for Hôtel through the search vector', function () {
    org('Hôtel de la Gare');

    expect(Organisation::query()->whereRaw("search_vector like '%hotel%'")->count())->toBe(1);
});

it('renders the fiche with its labels', function () {
    $org = org('Fiche SA', ['sector_id' => ref(ReferenceList::Sector, 'Commerce')->id]);

    $this->actingAs(commercial())->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/show')
        ->where('organisation.name', 'Fiche SA')
        ->where('organisation.sector.label', 'Commerce')
        ->where('can.update', true));
});

it('renders the create and edit forms with options', function () {
    $org = org('Form SA');

    $this->actingAs(commercial())->get('/crm/organisations/create')->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/form')->where('organisation', null)->has('options.sector', 7)->has('options.das', 4));
    $this->get("/crm/organisations/{$org->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('crm/organisations/form')->where('organisation.id', $org->id));
});

it('limits a Responsable DAS to the entreprises of his DAS', function () {
    $mine = ref(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = ref(ReferenceList::Das, 'Métiers supports');
    $visible = org('Visible SA');
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = org('Cachée SA');
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    org('Sans DAS SA');

    $user = userWithRole(Role::ResponsableDas);
    DB::table('user_das')->insert(['tenant_id' => tenant()->id, 'user_id' => $user->id, 'das_id' => $mine->id]);

    $names = fn (string $query) => collect($this->actingAs($user)->get('/crm/organisations'.$query)->original->getData()['page']['props']['organisations']['data'])->pluck('name')->all();

    expect($names(''))->toBe(['Visible SA'])
        ->and($names('?q=cach'))->toBe([])
        ->and($names('?das='.$other->id))->toBe([]);

    $this->get("/crm/organisations/{$visible->id}")->assertOk();
    $this->get("/crm/organisations/{$hidden->id}")->assertNotFound();
    $this->get('/crm/organisations/'.org('X')->id)->assertNotFound();
});

it('lets a Responsable DAS without followed DAS see nothing', function () {
    org('Quelque chose');

    $this->actingAs(userWithRole(Role::ResponsableDas))->get('/crm/organisations')
        ->assertInertia(fn (Assert $page) => $page->has('organisations.data', 0));
});

it('forbids the assistant to delete but not the commercial', function () {
    $org = org('À supprimer');

    $this->actingAs(assistant())->delete("/crm/organisations/{$org->id}")->assertForbidden();
    expect(Organisation::count())->toBe(1);

    $this->actingAs(commercial())->delete("/crm/organisations/{$org->id}")->assertRedirect(route('crm.organisations.index'));
    expect(Organisation::count())->toBe(0)->and(Organisation::withTrashed()->count())->toBe(1);
});

it('forbids create and update without the permission', function () {
    $org = org('Lecture seule');

    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->post('/crm/organisations', ['name' => 'X'])->assertForbidden();
    $this->put("/crm/organisations/{$org->id}", ['name' => 'Y'])->assertForbidden();
    $this->get("/crm/organisations/{$org->id}")->assertOk();
});

it('redirects guests to the login', function () {
    $this->get('/crm/organisations')->assertRedirect('/login');
});

it('restores a soft-deleted entreprise and lists the trash', function () {
    $org = org('Corbeille', ['siret' => validSiret()]);
    $org->delete();
    $user = commercial();

    $this->actingAs($user)->get('/crm/organisations?trashed=1')->assertInertia(fn (Assert $page) => $page->has('organisations.data', 1));
    $this->get('/crm/organisations')->assertInertia(fn (Assert $page) => $page->has('organisations.data', 0));
    $this->put("/crm/organisations/{$org->id}", ['name' => 'Y'])->assertNotFound();

    $this->post("/crm/organisations/{$org->id}/restore")->assertRedirect(route('crm.organisations.show', $org));
    expect(Organisation::count())->toBe(1);
});

it('refuses to restore onto a siret now used by another entreprise', function () {
    $old = org('Ancienne', ['siret' => validSiret()]);
    $old->delete();
    org('Nouvelle', ['siret' => validSiret()]);

    $this->actingAs(commercial())->post("/crm/organisations/{$old->id}/restore")->assertSessionHasErrors('siret');
    expect($old->refresh()->trashed())->toBeTrue();
});

it('protects reference items used by an entreprise', function () {
    $sector = ref(ReferenceList::Sector, 'Industrie');
    $das = ref(ReferenceList::Das, 'Métiers supports');
    $free = ref(ReferenceList::Sector, 'Services');
    $org = org('Usage SA', ['sector_id' => $sector->id]);
    $org->das()->sync([$das->id => ['tenant_id' => tenant()->id]]);

    expect($sector->isInUse())->toBeTrue()->and($das->isInUse())->toBeTrue()->and($free->isInUse())->toBeFalse();

    $this->actingAs(admin(twoFactor: true))->delete(route('admin.references.destroy', [$sector->list->value, $sector->id]))->assertSessionHasErrors();
    expect(ReferenceItem::query()->whereKey($sector->id)->exists())->toBeTrue();
});

it('stores the DAS followed by a Responsable DAS from the users page', function () {
    $user = userWithRole(Role::ResponsableDas);
    $das = ref(ReferenceList::Das, 'Compétences transversales');
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->put("/admin/users/{$user->id}/das", ['das_ids' => [$das->id]])->assertRedirect();

    expect(DB::table('user_das')->where('user_id', $user->id)->pluck('das_id')->all())->toBe([$das->id])
        ->and(AuditLog::query()->where('event', 'users.das_changed')->where('auditable_id', $user->id)->exists())->toBeTrue();

    $this->put("/admin/users/{$user->id}/das", ['das_ids' => []])->assertRedirect();
    expect(DB::table('user_das')->where('user_id', $user->id)->count())->toBe(0);

    $this->actingAs($admin)->get('/admin/users')->assertInertia(fn (Assert $page) => $page
        ->has('dasOptions', 4)->where('users.0.role', fn ($role) => is_string($role) || $role === null));
});

it('refuses DAS on a user who is not Responsable DAS and needs users.manage', function () {
    $das = ref(ReferenceList::Das, 'Compétences transversales');
    $commercial = commercial();

    $this->actingAs(admin(twoFactor: true))->putJson("/admin/users/{$commercial->id}/das", ['das_ids' => [$das->id]])->assertStatus(422);
    $this->actingAs($commercial)->put("/admin/users/{$commercial->id}/das", ['das_ids' => []])->assertForbidden();
});

it('shares crm.view with the sidebar for a commercial', function () {
    $this->actingAs(commercial())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($p) => collect($p)->contains('crm.view')));
});

it('keeps a deactivated responsible until changed but never assigns a new one', function () {
    $gone = commercial();
    $gone->forceFill(['deactivated_at' => now()])->save();
    $other = commercial();
    $other->forceFill(['deactivated_at' => now()])->save();
    $org = org('Responsable parti', ['responsible_user_id' => $gone->id]);
    $user = commercial();

    $this->actingAs($user)->get("/crm/organisations/{$org->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('options.responsible', fn ($list) => collect($list)->contains(fn ($o) => $o['value'] === $gone->id && str_ends_with($o['label'], '(désactivé)'))));

    $this->put("/crm/organisations/{$org->id}", ['name' => 'Renommée', 'responsible_user_id' => $gone->id])->assertRedirect();
    expect($org->refresh()->name)->toBe('Renommée')->and($org->responsible_user_id)->toBe($gone->id);

    $this->putJson("/crm/organisations/{$org->id}", ['name' => 'Renommée', 'responsible_user_id' => $other->id])->assertJsonValidationErrors('responsible_user_id');
    $this->postJson('/crm/organisations', ['name' => 'Neuve', 'responsible_user_id' => $gone->id])->assertJsonValidationErrors('responsible_user_id');
});

it('shows and keeps an inactive reference value of the fiche', function () {
    $sector = ref(ReferenceList::Sector, 'Industrie');
    $org = org('Secteur éteint', ['sector_id' => $sector->id]);
    $sector->update(['is_active' => false]);

    $this->actingAs(commercial())->get("/crm/organisations/{$org->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('options.sector', fn ($list) => collect($list)->contains(fn ($o) => $o['value'] === $sector->id && $o['label'] === 'Industrie (inactif)')));
    $this->get('/crm/organisations/create')->assertInertia(fn (Assert $page) => $page
        ->where('options.sector', fn ($list) => ! collect($list)->contains(fn ($o) => $o['value'] === $sector->id)));

    $this->put("/crm/organisations/{$org->id}", ['name' => 'Secteur éteint', 'sector_id' => $sector->id, 'city' => 'Nice'])->assertRedirect();
    expect($org->refresh()->sector_id)->toBe($sector->id)->and($org->city)->toBe('Nice');
});

it('answers 404 to a malformed id and ignores array filters', function () {
    org('Filtrable');
    $this->actingAs(commercial());

    $this->get('/crm/organisations/pas-un-uuid')->assertNotFound();
    $this->get('/crm/organisations?q[]=x&status[]=x&das[]=x&sort[]=x&dir[]=x&sector[]=x&responsible[]=x&origin[]=x')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('organisations.data', 1));
});

it('shows a trashed fiche by url only to who may delete', function () {
    $org = Organisation::query()->create(['name' => 'Disparue']);
    $org->delete();
    $person = makePerson('Effacé');
    $person->delete();

    $this->actingAs(assistant());
    $this->get("/crm/organisations/{$org->id}")->assertNotFound();
    $this->get("/crm/people/{$person->id}")->assertNotFound();

    $this->actingAs(commercial());
    $this->get("/crm/organisations/{$org->id}")->assertOk();
    $this->get("/crm/people/{$person->id}")->assertOk();
});
