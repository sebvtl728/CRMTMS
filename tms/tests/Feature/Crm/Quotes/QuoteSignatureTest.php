<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Documents\PdfSource;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteSignatures;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\QuoteTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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
    app(Signatures::class)->sign($request->fresh(), $m[1], sigDrawing(), '203.0.113.7', 'Firefox');
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

it('keeps the exact HTML of a sent quote and signs it with the drawing in the « Bon pour accord » box', function () {
    $quote = signatureQuote();
    $source = PdfSource::parse((string) Storage::disk(TenantStorage::DISK)->get(PdfSource::pathFor($quote->pdf_path)));
    expect($source?->hasSlot())->toBeTrue()->and($source->html)->toContain($quote->number, 'Bon pour accord');

    $this->post("/crm/quotes/{$quote->id}/signature", ['email' => 'lea.durand@example.test'])->assertSessionHasNoErrors();
    quoteSign(SignatureRequest::query()->where('subject_type', 'quote')->sole());

    $pages = [];
    Http::assertSent(function ($r) use (&$pages) {
        foreach ($r->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $pages[] = (string) $part['contents'];
            }
        }

        return true;
    });
    expect(collect($pages)->first(fn ($p) => str_contains($p, 'Signé électroniquement le 14/11/2026 par Léa Durand')))
        ->toContain($quote->number, 'Bon pour accord', 'data:image/png;base64,');
});

it('erases the drawn signature and the frozen HTML of an anonymised customer', function () {
    $quote = signatureQuote();
    $this->post("/crm/quotes/{$quote->id}/signature", ['email' => 'lea.durand@example.test'])->assertSessionHasNoErrors();
    $request = SignatureRequest::query()->where('subject_type', 'quote')->sole();
    quoteSign($request);
    $request->refresh();
    $disk = Storage::disk(TenantStorage::DISK);
    expect($disk->exists($request->drawing_path))->toBeTrue()->and($disk->exists(PdfSource::pathFor($quote->pdf_path)))->toBeTrue();

    app(Anonymiser::class)->anonymise(Person::query()->where('last_name', 'Durand')->firstOrFail(), 'request');

    expect($disk->exists($request->drawing_path))->toBeFalse()
        ->and($disk->exists(PdfSource::pathFor($quote->pdf_path)))->toBeFalse()
        ->and($request->fresh()->drawing_path)->toBeNull();
});
