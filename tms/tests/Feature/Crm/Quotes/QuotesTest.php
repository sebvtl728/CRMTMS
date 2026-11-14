<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunityTrainings;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteLine;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function qsTraining(string $title, array $attributes = []): Training
{
    return Training::query()->create([
        'title' => $title,
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        ...$attributes,
    ]);
}

it('prefills a quote from its opportunity and creates it as a numbered draft', function () {
    $this->actingAs(commercial());
    $company = Company::factory()->default()->create(['quote_prefix' => 'CF', 'quote_validity_days' => 45]);
    Company::factory()->create(['name' => 'Autre société']);
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $contact = makePerson('Durand', ['first_name' => 'Léa']);
    affiliate($contact, $organisation);
    $haccp = qsTraining('HACCP', ['code' => 'H1', 'price_company' => '1200.00', 'price_individual' => '900.00']);
    $sansTarif = qsTraining('Sans tarif', ['price_individual' => '100.00']);
    $opportunity = makeOpportunity($organisation, ['title' => 'Plan 2027']);
    OpportunityTrainings::sync($opportunity, [['training_id' => $haccp->id, 'quantity' => '3.00'], ['training_id' => $sansTarif->id, 'quantity' => '1.00']]);

    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/quotes/form')
            ->where('quote.company_id', $company->id)
            ->where('quote.issued_on', '2026-11-14')
            ->where('quote.valid_until', '2026-12-29')
            ->where('quote.lines.0.training_id', $haccp->id)
            ->where('quote.lines.0.label', 'HACCP')
            ->where('quote.lines.0.quantity', '3.00')
            ->where('quote.lines.0.unit', 'stagiaire')
            ->where('quote.lines.0.unit_price', '1200.00')
            ->where('quote.lines.0.vat_rate', '20.00')
            ->where('quote.lines.1.unit_price', null)
            ->where('missing_prices', ['Sans tarif'])
            ->where('opportunity.title', 'Plan 2027')
            ->where('options.contacts', [['value' => $contact->id, 'label' => $contact->fullName()]])
            ->where('options.companies', fn ($companies) => count($companies) === 2 && $companies[0]['value'] === $company->id)
            ->where('options.vat_rates', fn ($rates) => collect($rates)->pluck('value')->all() === ['20.00', '10.00', '5.50', '2.10', '0.00']));

    $this->post('/crm/quotes', quotePayload($opportunity, $company, [
        quoteLine(['training_id' => $haccp->id, 'label' => 'HACCP', 'quantity' => '3', 'unit_price' => '1 200,00']),
        quoteLine(['training_id' => null, 'label' => 'Frais de déplacement', 'unit' => 'forfait', 'unit_price' => '150']),
    ], ['contact_person_id' => $contact->id, 'notes' => 'Session en janvier']))->assertSessionHasNoErrors();

    $quote = Quote::query()->sole();
    expect($quote->number)->toBe('CF-2026-0001')
        ->and($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->organisation_id)->toBe($organisation->id)
        ->and($quote->person_id)->toBeNull()
        ->and($quote->contact_person_id)->toBe($contact->id)
        ->and($quote->vat_exempt)->toBeFalse()
        ->and([$quote->total_ht, $quote->total_vat, $quote->total_ttc])->toBe(['3750.00', '750.00', '4500.00'])
        ->and($quote->lines()->get()->map(fn (QuoteLine $l) => [$l->position, $l->label, $l->das_id, $l->total_ht])->all())->toBe([
            [0, 'HACCP', $haccp->das_id, '3600.00'],
            [1, 'Frais de déplacement', null, '150.00'],
        ]);

    // Timeline of the fiche and of the opportunity.
    expect(Timeline::for($organisation)->where('type', 'quote')->pluck('title')->all())->toBe(['Devis CF-2026-0001 créé'])
        ->and(Timeline::for($opportunity)->where('type', 'quote')->pluck('title')->all())->toBe(['Devis CF-2026-0001 créé']);

    // The tabs of the opportunity and of the entreprise list it.
    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $page) => $page
        ->where('quotes.0.number', 'CF-2026-0001')
        ->where('quotes.0.status.label', 'Brouillon')
        ->where('can.createQuote', true));
    $this->get("/crm/organisations/{$organisation->id}")->assertInertia(fn (Assert $page) => $page->where('quotes.0.number', 'CF-2026-0001'));
    $this->get("/crm/people/{$contact->id}")->assertInertia(fn (Assert $page) => $page->where('quotes.0.number', 'CF-2026-0001'));

    $this->get("/crm/quotes/{$quote->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('crm/quotes/show')
        ->where('quote.number', 'CF-2026-0001')
        ->where('quote.contact.name', $contact->fullName())
        ->where('quote.lines.1.unit_label', 'forfait')
        ->where('can.update', true));
});

it('uses the particulier price and the particulier as recipient', function () {
    $this->actingAs(commercial());
    $company = Company::factory()->default()->create();
    $person = makePerson('Martin', ['is_individual' => true]);
    $haccp = qsTraining('HACCP', ['price_company' => '1200.00', 'price_individual' => '900.00']);
    $opportunity = makeOpportunity($person);
    OpportunityTrainings::sync($opportunity, [['training_id' => $haccp->id, 'quantity' => '1.00']]);

    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")
        ->assertInertia(fn (Assert $page) => $page
            ->where('quote.lines.0.unit_price', '900.00')
            ->where('quote.contact_person_id', $person->id));

    $this->post('/crm/quotes', quotePayload($opportunity, $company, null, ['contact_person_id' => $person->id]))->assertSessionHasNoErrors();
    expect(Quote::query()->sole())->person_id->toBe($person->id)->organisation_id->toBeNull()->contact_person_id->toBe($person->id);
});

it('an exempt company gives 0 % on every line and no rate to choose', function () {
    $this->actingAs(commercial());
    $company = Company::factory()->default()->create(['vat_regime' => VatRegime::Exempt]);
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));

    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")
        ->assertInertia(fn (Assert $page) => $page->where('options.companies.0.vat_exempt', true));

    // A rate sent anyway is ignored.
    $this->post('/crm/quotes', quotePayload($opportunity, $company, [quoteLine(['unit_price' => '1 000,00', 'vat_rate' => '20'])]))->assertSessionHasNoErrors();

    $quote = Quote::query()->sole();
    expect($quote->vat_exempt)->toBeTrue()
        ->and([$quote->total_ht, $quote->total_vat, $quote->total_ttc])->toBe(['1000.00', '0.00', '1000.00'])
        ->and($quote->lines()->value('vat_rate'))->toBe('0.00');

    $this->get("/crm/quotes/{$quote->id}/edit")->assertInertia(fn (Assert $page) => $page->where('vat_exempt', true));
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('quote.vat_exempt', true)
        ->where('quote.vat_notice', 'TVA non applicable, art. 261-4-4° a du CGI'));

    // A draft follows the company: once subject to VAT, the next save applies the rates.
    $company->update(['vat_regime' => VatRegime::Subject]);
    $this->put("/crm/quotes/{$quote->id}/edit", quotePayload($opportunity, $company, [quoteLine(['vat_rate' => '20'])]))->assertSessionHasNoErrors();
    expect($quote->refresh())->vat_exempt->toBeFalse()->total_ttc->toBe('1200.00');
});

it('accepts a French amount such as 12 500,00 and refuses wrong lines in French', function () {
    $this->actingAs(commercial());
    $company = Company::factory()->default()->create();
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));

    $this->post('/crm/quotes', quotePayload($opportunity, $company, [quoteLine(['unit_price' => '12 500,00', 'quantity' => '1'])]))->assertSessionHasNoErrors();
    expect(Quote::query()->sole()->total_ht)->toBe('12500.00');

    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, []))
        ->assertJsonValidationErrors(['lines' => 'Ajoutez au moins une ligne au devis.']);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, array_fill(0, 101, quoteLine())))
        ->assertJsonValidationErrors(['lines' => 'Un devis compte au plus 100 lignes.']);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, [quoteLine([
        'label' => '', 'quantity' => 'trois', 'unit' => 'litre', 'unit_price' => '12,345', 'discount_percent' => '120', 'vat_rate' => '7',
    ])]))->assertJsonValidationErrors([
        'lines.0.label' => 'Indiquez un libellé.',
        'lines.0.quantity' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
        'lines.0.unit' => 'Choisissez une unité de la liste.',
        'lines.0.unit_price' => 'Indiquez un montant, par exemple 12 500,00.',
        'lines.0.discount_percent' => 'La remise doit être comprise entre 0 et 100 %.',
        'lines.0.vat_rate' => 'Choisissez un taux de TVA de la liste.',
    ]);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, [quoteLine(['quantity' => '9999,99', 'unit_price' => '9 999 999 999,99'])]))
        ->assertJsonValidationErrors(['lines' => 'Le total du devis est trop élevé.']);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, null, ['valid_until' => '2026-11-01']))
        ->assertJsonValidationErrors(['valid_until' => 'La date de validité doit suivre la date du devis.']);

    // The recipient: an active interlocuteur of the entreprise only.
    $former = makePerson('Ancien');
    affiliate($former, $opportunity->organisation, ['ended_on' => '2026-01-01']);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, null, ['contact_person_id' => $former->id]))
        ->assertJsonValidationErrors(['contact_person_id' => 'Cette personne n\'est pas un interlocuteur de ce compte.']);

    expect(Quote::query()->count())->toBe(1);
});

it('refuses to change a quote that is no longer a draft', function () {
    $this->actingAs(commercial());
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $quote = makeQuote($opportunity);
    $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();

    $this->get("/crm/quotes/{$quote->id}/edit")->assertStatus(409)->assertSee('Un devis envoyé ne se modifie plus.');
    $this->put("/crm/quotes/{$quote->id}/edit", quotePayload($opportunity, $quote->company))->assertStatus(409);
    $this->delete("/crm/quotes/{$quote->id}")->assertStatus(409);
    expect($quote->refresh()->trashed())->toBeFalse();

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('can.update', false)->where('can.delete', false));
});

it('lets an assistant edit a draft but not delete it', function () {
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $quote = makeQuote($opportunity);

    $this->actingAs(assistant());
    $this->put("/crm/quotes/{$quote->id}/edit", quotePayload($opportunity, $quote->company, [quoteLine(['unit_price' => '2 000'])]))->assertSessionHasNoErrors();
    expect($quote->refresh()->total_ht)->toBe('2000.00');
    $this->delete("/crm/quotes/{$quote->id}")->assertForbidden();
    expect($quote->refresh()->trashed())->toBeFalse();

    $this->actingAs(commercial())->delete("/crm/quotes/{$quote->id}")->assertRedirect(route('crm.opportunities.show', $opportunity));
    expect($quote->refresh()->trashed())->toBeTrue()
        ->and(Timeline::for($opportunity->organisation)->where('type', 'quote')->pluck('title')->all())->toBe([]);
});

it('edits the lines of a draft, keeping its number and company', function () {
    $this->actingAs(commercial());
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $quote = makeQuote($opportunity);
    $other = Company::factory()->create(['quote_prefix' => 'AUT']);

    $this->get("/crm/quotes/{$quote->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/quotes/form')->where('quote.number', $quote->number)->has('quote.lines', 1));

    $this->put("/crm/quotes/{$quote->id}/edit", quotePayload($opportunity, $other, [
        quoteLine(['label' => 'Une', 'unit_price' => '100']),
        quoteLine(['label' => 'Deux', 'unit_price' => '200', 'unit' => 'jour', 'quantity' => '2']),
    ]))->assertSessionHasNoErrors()->assertRedirect(route('crm.quotes.show', $quote));

    $quote->refresh();
    expect($quote->company_id)->not->toBe($other->id)
        ->and($quote->number)->toBe('DEV-2026-0001')
        ->and($quote->lines()->pluck('label')->all())->toBe(['Une', 'Deux'])
        ->and($quote->total_ht)->toBe('500.00')
        ->and(QuoteLine::query()->count())->toBe(2);
});

it('duplicates a quote as a new draft with a new number', function () {
    $this->actingAs(commercial());
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $quote = makeQuote($opportunity);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Europe/Paris'));

    $this->post("/crm/quotes/{$quote->id}/duplicate")->assertRedirect();

    $copy = Quote::query()->whereKeyNot($quote->id)->sole();
    expect($copy->number)->toBe('DEV-2026-0002')
        ->and($copy->status)->toBe(QuoteStatus::Draft)
        ->and($copy->issued_on->toDateString())->toBe('2026-11-20')
        ->and($copy->valid_until->toDateString())->toBe('2026-12-20')
        ->and($copy->total_ht)->toBe($quote->total_ht)
        ->and($copy->lines()->pluck('label')->all())->toBe(['Formation HACCP']);
});

it('lists quotes with filters and sorts', function () {
    $this->actingAs(commercial());
    $hotel = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $support = crmItem(ReferenceList::Das, 'Métiers supports');
    $second = Company::factory()->create(['quote_prefix' => 'SEC']);
    $bistrot = Organisation::query()->create(['name' => 'Le Bistrot']);
    $garage = Organisation::query()->create(['name' => 'Garage Martin']);
    $owner = commercial();
    $a = makeQuote(makeOpportunity($bistrot, ['das_id' => $hotel->id, 'responsible_user_id' => $owner->id]));
    $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'Europe/Paris'));
    $b = makeQuote(makeOpportunity($garage, ['das_id' => $support->id, 'title' => 'Comptabilité']), $second, [[
        'training_id' => null, 'label' => 'Compta', 'description' => null, 'quantity' => '1.00',
        'unit' => 'jour', 'unit_price' => '50.00', 'discount_percent' => '0.00', 'vat_rate' => '20.00',
    ]]);
    $b->forceFill(['status' => QuoteStatus::Sent])->save();

    $numbers = fn (string $query) => collect($this->get('/crm/quotes'.$query)->assertOk()->original->getData()['page']['props']['quotes']['data'])->pluck('number')->all();

    expect($numbers(''))->toBe(['SEC-2026-0001', 'DEV-2026-0001'])
        ->and($numbers('?sort=number&dir=asc'))->toBe(['DEV-2026-0001', 'SEC-2026-0001'])
        ->and($numbers('?sort=total&dir=asc'))->toBe(['SEC-2026-0001', 'DEV-2026-0001'])
        ->and($numbers('?status=sent'))->toBe(['SEC-2026-0001'])
        ->and($numbers('?company='.$second->id))->toBe(['SEC-2026-0001'])
        ->and($numbers('?das='.$hotel->id))->toBe(['DEV-2026-0001'])
        ->and($numbers('?responsible='.$owner->id))->toBe(['DEV-2026-0001'])
        ->and($numbers('?issued_from=2026-12-01'))->toBe(['SEC-2026-0001'])
        ->and($numbers('?issued_to=2026-11-30'))->toBe(['DEV-2026-0001'])
        ->and($numbers('?q=garage'))->toBe(['SEC-2026-0001'])
        ->and($numbers('?q=comptabilite'))->toBe(['SEC-2026-0001'])
        ->and($numbers('?q=DEV-2026'))->toBe(['DEV-2026-0001'])
        ->and($numbers('?status=nimporte&das=pas-un-id'))->toBe(['SEC-2026-0001', 'DEV-2026-0001']);

    $this->get('/crm/quotes')->assertInertia(fn (Assert $page) => $page->component('crm/quotes/index')
        ->where('quotes.data.1.total_ht', '1000.00')
        ->where('quotes.data.1.status.label', 'Brouillon')
        ->where('quotes.data.0.status.label', 'Envoyé')
        ->has('options.status', 6));
    expect($a)->not->toBeNull();
});

it('keeps a training, a DAS and an interlocuteur used by a quote', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $contact = makePerson('Durand');
    affiliate($contact, $organisation);
    $training = qsTraining('HACCP', ['price_company' => '100.00']);
    $opportunity = makeOpportunity($organisation);
    $company = Company::factory()->default()->create();

    $this->post('/crm/quotes', quotePayload($opportunity, $company, [quoteLine(['training_id' => $training->id, 'label' => 'HACCP'])], ['contact_person_id' => $contact->id]))
        ->assertSessionHasNoErrors();
    $quote = Quote::query()->sole();
    $quote->delete();

    // Lines of a trashed quote still use the training and its DAS.
    expect(TrainingUsage::isUsed($training->id))->toBeTrue()
        ->and(ReferenceUsage::isInUse(crmItem(ReferenceList::Das, 'Hôtellerie-restauration')))->toBeTrue()
        ->and(LinkedData::keys())->toContain('crm.quotes')
        ->and(LinkedData::any($contact))->toBeTrue();

    // An inactive training cannot be added to a new quote.
    $training->update(['is_active' => false]);
    $this->postJson('/crm/quotes', quotePayload($opportunity, $company, [quoteLine(['training_id' => $training->id])]))
        ->assertJsonValidationErrors(['lines.0.training_id' => 'Cette formation est désactivée : choisissez-en une autre.']);
});

it('shows the status changes of a quote in the timeline', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);
    $quote = makeQuote($opportunity);

    foreach ([QuoteStatus::Sent, QuoteStatus::Reminded, QuoteStatus::Accepted] as $i => $status) {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Paris')->addDays($i));
        $quote->forceFill(['status' => $status])->save();
    }
    $quote->forceFill(['notes' => 'Autre chose'])->save();

    expect(Timeline::for($organisation)->where('type', 'quote')->pluck('title')->all())->toBe([
        'Devis DEV-2026-0001 accepté',
        'Devis DEV-2026-0001 relancé',
        'Devis DEV-2026-0001 envoyé',
        'Devis DEV-2026-0001 créé',
    ]);
});

it('refuses a quote for an opportunity on a trashed fiche or without a company', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);

    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")->assertStatus(409)
        ->assertSee('Aucune société émettrice');

    $company = Company::factory()->default()->create();
    $organisation->delete();
    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")->assertForbidden();
    $this->post('/crm/quotes', quotePayload($opportunity, $company))->assertForbidden();
    expect(Quote::query()->count())->toBe(0);
});
