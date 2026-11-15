<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('das visibility on quotes and pdf', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $organisation->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $visibleOpportunity = makeOpportunity($organisation, ['title' => 'Visible', 'das_id' => $mine->id]);
    $hiddenOpportunity = makeOpportunity($organisation, ['title' => 'Cachée', 'das_id' => $other->id]);
    $visible = makeQuote($visibleOpportunity);
    $hidden = makeQuote($hiddenOpportunity);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);

    expect(CrmVisibility::quotes(Quote::query(), $responsable)->pluck('id')->all())->toBe([$visible->id])
        ->and(CrmVisibility::quotes(Quote::query(), commercial())->count())->toBe(2);

    $this->actingAs($responsable);
    $numbers = collect($this->get('/crm/quotes')->assertOk()->original->getData()['page']['props']['quotes']['data'])->pluck('number')->all();
    expect($numbers)->toBe([$visible->number]);
    // Filtering on a DAS he does not follow shows nothing more.
    expect(collect($this->get('/crm/quotes?das='.$other->id)->original->getData()['page']['props']['quotes']['data']))->toBeEmpty();

    $this->get("/crm/quotes/{$visible->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false));
    $this->get("/crm/quotes/{$hidden->id}")->assertNotFound();
    $this->get("/crm/quotes/{$hidden->id}/edit")->assertNotFound();
    $this->post("/crm/quotes/{$hidden->id}/duplicate")->assertNotFound();

    // The tab of the fiche and the timeline follow the same rule.
    $this->get("/crm/organisations/{$organisation->id}")
        ->assertInertia(fn (Assert $page) => $page->has('quotes', 1)->where('quotes.0.number', $visible->number));
    expect(Timeline::for($organisation)->where('type', 'quote')->pluck('meta.quote_id')->all())->toBe([$visible->id]);

    // A Responsable DAS granted crm.quotes.update does not create on an opportunity he does not see.
    $responsable->givePermissionTo('crm.quotes.update');
    $this->get("/crm/opportunities/{$hiddenOpportunity->id}/quotes/create")->assertNotFound();
    $this->postJson('/crm/quotes', quotePayload($hiddenOpportunity, $visible->company))->assertJsonValidationErrors('opportunity_id');
    expect(Quote::query()->count())->toBe(2);

    // The PDF route (Task 6) answers 404 the same way: covered in QuotePdfTest.
});

it('answers 404 for a malformed id, another organisme or a quote of a trashed fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $quote = makeQuote(makeOpportunity($organisation));

    $foreign = app(TenantContext::class)->run(Tenant::factory()->create(), function () {
        CapFormationReferenceSeeder::run(tenant());

        return makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Ailleurs'])), Company::factory()->default()->create());
    });
    inTenant();

    $this->actingAs(assistant());
    $this->get('/crm/quotes/pas-un-uuid')->assertNotFound();
    $this->get("/crm/quotes/{$foreign->id}")->assertNotFound();
    $this->get("/crm/quotes/{$quote->id}")->assertOk();

    $organisation->delete();
    $this->get("/crm/quotes/{$quote->id}")->assertNotFound();
    expect(collect($this->get('/crm/quotes')->original->getData()['page']['props']['quotes']['data']))->toBeEmpty();

    // With crm.quotes.delete the quote stays readable, but nothing is changed on a trashed fiche.
    $this->actingAs(commercial());
    $this->get("/crm/quotes/{$quote->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.delete', false));
    $this->get("/crm/quotes/{$quote->id}/edit")->assertForbidden();
    $this->delete("/crm/quotes/{$quote->id}")->assertForbidden();
    $this->post("/crm/quotes/{$quote->id}/duplicate")->assertForbidden();
});

it('hides the quotes of a trashed opportunity and shows none without a viewer', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);
    $quote = makeQuote($opportunity);

    expect(Timeline::for($organisation)->where('type', 'quote'))->toBeEmpty();

    $this->actingAs(commercial());
    expect(Timeline::for($organisation)->where('type', 'quote'))->toHaveCount(1);

    $opportunity->delete();
    $this->get("/crm/quotes/{$quote->id}")->assertNotFound();
    expect(Timeline::for($organisation)->where('type', 'quote'))->toBeEmpty()
        ->and(collect($this->get('/crm/quotes')->original->getData()['page']['props']['quotes']['data']))->toBeEmpty();
    Auth::logout();
});
