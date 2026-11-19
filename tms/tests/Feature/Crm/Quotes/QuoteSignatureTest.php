<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteSignatures;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\QuoteTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    fakeQuoteGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/** A quote sent through the real endpoint, awaiting the customer's answer. */
function signatureQuote(): Quote
{
    test()->actingAs(commercial());
    $quote = sendableQuote('Étude');
    test()->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    return $quote->refresh();
}

function quoteSign(SignatureRequest $request): void
{
    app(Signatures::class)->sendCode($request);
    preg_match('/\b(\d{6})\b/', Mail::sent(SignatureMail::class)->last()->text, $m);
    app(Signatures::class)->sign($request->fresh(), $m[1], '203.0.113.7', 'Firefox');
}

it('sends a « Bon pour accord » link; once signed the quote is accepted with its signed copy, no dossier created', function () {
    $quote = signatureQuote();

    $this->post("/crm/quotes/{$quote->id}/signature", ['email' => 'lea.durand@example.test'])->assertSessionHasNoErrors()->assertSessionHas('status');
    $request = SignatureRequest::query()->where('subject_type', 'quote')->sole();
    expect($request->signer_name)->toBe('Léa Durand')->and($request->title)->toBe('Devis '.$quote->number);

    quoteSign($request);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_on->toDateString())->toBe('2026-11-14')
        ->and($quote->signed_path)->not->toBeNull()
        ->and($quote->signed_name)->toBe($quote->number.'-signe.pdf');
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $p) => $p
        ->where('quote.signature.status', 'signed')->where('can.sign', false));
});

it('invalidates the link of a quote refused, replaced or expired meanwhile', function () {
    $quote = signatureQuote();
    app(QuoteSignatures::class)->request(commercial(), $quote, 'lea.durand@example.test');
    $request = SignatureRequest::query()->sole();
    preg_match('#/signature/([A-Za-z0-9]{40})#', Mail::sent(SignatureMail::class)->first()->text, $m);
    expect(Signatures::find($m[1]))->not->toBeNull();

    app(QuoteTransitions::class)->refuse($quote->fresh(), CarbonImmutable::parse('2026-11-14'), 'Trop cher');

    expect(Signatures::find($m[1]))->toBeNull()
        ->and(fn () => quoteSign($request))->toThrow(UserFacingHttpException::class);
});

it('offers the link only on a quote awaiting an answer, to who may update it', function () {
    $quote = signatureQuote();
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $p) => $p->where('can.sign', true)->where('quote.signature', null));

    $draft = sendableQuote('Étude');
    $this->post("/crm/quotes/{$draft->id}/signature", ['email' => 'lea.durand@example.test'])->assertStatus(409);
});
