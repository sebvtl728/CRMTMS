<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteMail;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    fakeQuoteGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/**
 * A quote sent through the real endpoint (stage « Proposition » afterwards).
 */
function outcomeQuote(): Quote
{
    test()->actingAs(commercial());
    $quote = sendableQuote('Étude');
    test()->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    return $quote->refresh();
}

it('reminds a sent quote with the same pdf, as a contact', function () {
    $quote = outcomeQuote();
    $archived = Storage::disk(TenantStorage::DISK)->get($quote->pdf_path);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:00', 'Europe/Paris'));
    $this->flushSession(); // days later: a new session

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.remind', true)->where('can.send', false)
        ->where('remind.subject', 'Relance : devis CF-2026-0001 — CAP FORMATION'));

    $this->post("/crm/quotes/{$quote->id}/remind", quoteMessage(['subject' => 'Relance devis']))
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Relance du devis CF-2026-0001 envoyée.');

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Reminded)
        ->and($quote->reminded_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and(Storage::disk(TenantStorage::DISK)->get($quote->pdf_path))->toBe($archived);

    $mails = Mail::sent(QuoteMail::class);
    expect($mails)->toHaveCount(2);
    $mails->last()->assertHasSubject('Relance devis');
    $mails->last()->assertHasAttachedData($archived, 'CF-2026-0001.pdf', ['mime' => 'application/pdf']);

    expect(CarbonImmutable::parse(DB::table('organisations')->where('id', $quote->organisation_id)->value('last_contact_at'))->equalTo($quote->reminded_at))->toBeTrue();

    // Reminded again: allowed. A draft or an accepted quote: no.
    $this->post("/crm/quotes/{$quote->id}/remind", quoteMessage())->assertSessionHasNoErrors();
    $draft = makeQuote($quote->opportunity);
    $this->post("/crm/quotes/{$draft->id}/remind", quoteMessage())->assertStatus(409)->assertSee('Seul un devis envoyé ou relancé se relance.');
});

it('accepts a quote and proposes to win the opportunity with its amount', function () {
    $quote = outcomeQuote();
    $opportunity = $quote->opportunity;
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Europe/Paris'));
    $this->flushSession();

    $this->post("/crm/quotes/{$quote->id}/accept", ['accepted_on' => '2026-11-19'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('proposeWon', ['amount_ht' => '1000.00', 'amount_display' => '1 000,00 €', 'won_on' => '2026-11-19']);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_on->toDateString())->toBe('2026-11-19');

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('pipeline.opportunity_id', $opportunity->id)
        ->where('pipeline.won_stage_id', pipelineStage('Gagné')->id)
        ->where('pipeline.lost_stage_id', pipelineStage('Perdu')->id)
        ->has('pipeline.loss_reasons'));

    // The window calls the stage endpoint, with the quote to take the amount from.
    $this->post("/crm/opportunities/{$opportunity->id}/stage", [
        'stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-19', 'quote_id' => $quote->id,
    ])->assertSessionHasNoErrors();

    $opportunity->refresh();
    expect($opportunity->stage_id)->toBe(pipelineStage('Gagné')->id)
        ->and($opportunity->won_on->toDateString())->toBe('2026-11-19')
        ->and($opportunity->amount)->toBe('1000.00');

    expect(Timeline::for($quote->organisation)->where('type', 'quote')->pluck('title')->first())->toBe('Devis CF-2026-0001 accepté');
});

it('wins without touching the amount when the box is unticked', function () {
    $quote = outcomeQuote();
    $opportunity = $quote->opportunity;
    $opportunity->forceFill(['amount' => '750.00'])->save();
    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors()
        ->assertSessionHas('proposeWon', fn (array $p) => $p['won_on'] === '2026-11-14');
    expect($quote->refresh()->accepted_on->toDateString())->toBe('2026-11-14');

    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-14'])->assertSessionHasNoErrors();
    expect($opportunity->refresh()->amount)->toBe('750.00');
});

it('takes the amount only from an accepted quote of the same opportunity, when winning', function () {
    $quote = outcomeQuote();
    $opportunity = $quote->opportunity;
    $other = makeOpportunity($quote->organisation);

    // Not accepted yet.
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Gagné')->id, 'quote_id' => $quote->id])
        ->assertStatus(422)->assertJsonValidationErrors(['quote_id' => 'Ce devis n\'est pas un devis accepté de cette opportunité.']);
    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors();
    // Another opportunity, or not a win.
    $this->postJson("/crm/opportunities/{$other->id}/stage", ['stage_id' => pipelineStage('Gagné')->id, 'quote_id' => $quote->id])->assertStatus(422);
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Négociation')->id, 'quote_id' => $quote->id])
        ->assertStatus(422)->assertJsonValidationErrors(['quote_id' => 'Le montant d\'un devis ne s\'applique qu\'en passant l\'opportunité en gagnée.']);

    expect($opportunity->refresh()->stage_id)->toBe(pipelineStage('Proposition')->id)
        ->and($other->refresh()->stage_id)->toBe(pipelineStage('Nouveau')->id);
});

it('refuses an acceptance in the future, before the quote date, or not after sending', function () {
    $quote = outcomeQuote();

    $this->postJson("/crm/quotes/{$quote->id}/accept", ['accepted_on' => '2026-11-15'])
        ->assertStatus(422)->assertJsonValidationErrors(['accepted_on' => 'La date d\'acceptation ne peut pas être dans le futur.']);
    $this->postJson("/crm/quotes/{$quote->id}/accept", ['accepted_on' => '2026-11-13'])
        ->assertStatus(422)->assertJsonValidationErrors(['accepted_on' => 'La date d\'acceptation ne peut pas précéder la date du devis.']);
    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);

    $draft = makeQuote($quote->opportunity);
    $this->post("/crm/quotes/{$draft->id}/accept", [])->assertStatus(409)->assertSee('Seul un devis envoyé ou relancé peut être accepté ou refusé.');

    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors();
    $this->post("/crm/quotes/{$quote->id}/refuse", [])->assertStatus(409);
    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertStatus(409);
});

it('refuses a quote and proposes to lose the opportunity with a reason', function () {
    $quote = outcomeQuote();
    $opportunity = $quote->opportunity;

    $this->post("/crm/quotes/{$quote->id}/refuse", ['refusal_reason' => 'Trop cher'])
        ->assertSessionHasNoErrors()->assertSessionHas('proposeLost', true);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Refused)
        ->and($quote->refused_on->toDateString())->toBe('2026-11-14')
        ->and($quote->refusal_reason)->toBe('Trop cher');

    // Lost: the reason stays required by the stage endpoint.
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Perdu')->id])->assertStatus(422)->assertJsonValidationErrors('loss_reason_id');
    $this->post("/crm/opportunities/{$opportunity->id}/stage", [
        'stage_id' => pipelineStage('Perdu')->id, 'loss_reason_id' => crmItem(ReferenceList::LossReason, 'Prix')->id,
    ])->assertSessionHasNoErrors();
    expect($opportunity->refresh()->lost_on->toDateString())->toBe('2026-11-14');
});

it('does not propose to win an opportunity already closed', function () {
    $quote = outcomeQuote();
    $quote->opportunity->forceFill(['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-14'])->save();

    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors()->assertSessionMissing('proposeWon');
});

it('a new version of a sent quote replaces it; a draft copy leaves the draft alone', function () {
    $quote = outcomeQuote();

    $this->post("/crm/quotes/{$quote->id}/duplicate")->assertRedirect();

    $copy = Quote::query()->where('status', 'draft')->sole();
    $quote->refresh();
    expect($copy->number)->toBe('CF-2026-0002')
        ->and($quote->status)->toBe(QuoteStatus::Refused)
        ->and($quote->refusal_reason)->toBe('Remplacé par CF-2026-0002')
        ->and($quote->replaced_by_id)->toBe($copy->id)
        ->and($quote->refused_on->toDateString())->toBe('2026-11-14');

    $this->post("/crm/quotes/{$copy->id}/duplicate")->assertRedirect();
    expect($copy->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and($copy->replaced_by_id)->toBeNull()
        ->and(Quote::query()->where('status', 'draft')->count())->toBe(2);

    // An accepted quote stays accepted.
    $accepted = outcomeQuote();
    $this->post("/crm/quotes/{$accepted->id}/accept", [])->assertSessionHasNoErrors();
    $this->post("/crm/quotes/{$accepted->id}/duplicate")->assertRedirect();
    expect($accepted->refresh()->status)->toBe(QuoteStatus::Accepted)->and($accepted->replaced_by_id)->toBeNull();
});
