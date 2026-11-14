<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteFreezer;
use App\Modules\Crm\Quotes\QuotePdf;
use App\Modules\Crm\Quotes\QuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

const PDF_NBSP = "\u{00A0}";

/**
 * Gotenberg answers a recognisable PDF per call; the HTML it received is kept.
 */
function fakeGotenberg(): void
{
    $calls = 0;
    Http::fake(['gotenberg:3000/*' => function () use (&$calls) {
        $calls++;

        return Http::response("%PDF-1.7 rendu {$calls}", 200, ['Content-Type' => 'application/pdf']);
    }]);
}

function sentHtml(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}

/**
 * @param  array<string, mixed>  $companyAttributes
 */
function pdfQuote(array $companyAttributes = [], ?array $lines = null): Quote
{
    $company = Company::factory()->default()->create([
        'name' => 'CAP FORMATION',
        'legal_form' => 'scop',
        'scop_underlying_form' => 'sarl',
        'capital_variable' => true,
        'siret' => '73282932000074',
        'rcs_city' => 'Lyon',
        'address_line1' => '12 rue des Écoles',
        'address_postal_code' => '69001',
        'address_city' => 'Lyon',
        'quote_prefix' => 'CF',
        'quote_terms' => 'Paiement à 30 jours.',
        'quote_footer' => 'Merci de votre confiance.',
        ...$companyAttributes,
    ]);
    $organisation = Organisation::query()->create([
        'name' => 'Le Bistrot', 'address_line1' => '3 place du Marché', 'postal_code' => '74000', 'city' => 'Annecy',
    ]);
    $contact = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.durand@example.test']);
    affiliate($contact, $organisation);
    $opportunity = makeOpportunity($organisation, ['title' => 'Plan de formation 2027']);

    $quote = makeQuote($opportunity, $company, $lines ?? [
        ['training_id' => null, 'label' => 'HACCP', 'description' => 'Deux jours', 'quantity' => '3.00', 'unit' => 'stagiaire', 'unit_price' => '1200.00', 'discount_percent' => '0.00', 'vat_rate' => '20.00'],
        ['training_id' => null, 'label' => 'Livret', 'description' => null, 'quantity' => '1.00', 'unit' => 'forfait', 'unit_price' => '10000.00', 'discount_percent' => '0.00', 'vat_rate' => '5.50'],
    ]);
    $quote->forceFill(['contact_person_id' => $contact->id])->save();

    return $quote->refresh();
}

it('renders the quote with its company, recipient, lines and french amounts', function () {
    $quote = pdfQuote();
    $html = app(QuotePdf::class)->html($quote);

    expect($html)
        ->toContain('CF-2026-0001')
        ->toContain('14/11/2026')
        ->toContain('14/12/2026')
        ->toContain('Plan de formation 2027')
        ->toContain('Le Bistrot')
        ->toContain('Léa Durand')
        ->toContain('3 place du Marché')
        ->toContain('74000 Annecy')
        ->toContain('12 rue des Écoles')
        ->toContain(e(LegalMentions::for($quote->company)))
        ->toContain('HACCP')
        ->toContain('Deux jours')
        // 3 × 1 200,00 + 10 000,00 = 13 600,00 HT; VAT 720,00 + 550,00.
        ->toContain('1'.PDF_NBSP.'200,00'.PDF_NBSP.'€')
        ->toContain('13'.PDF_NBSP.'600,00'.PDF_NBSP.'€')
        ->toContain('720,00'.PDF_NBSP.'€')
        ->toContain('550,00'.PDF_NBSP.'€')
        ->toContain('1'.PDF_NBSP.'270,00'.PDF_NBSP.'€')
        ->toContain('14'.PDF_NBSP.'870,00'.PDF_NBSP.'€')
        ->toContain('20'.PDF_NBSP.'%')
        ->toContain('5,5'.PDF_NBSP.'%')
        ->toContain('Paiement à 30 jours.')
        ->toContain('Merci de votre confiance.')
        ->toContain('BROUILLON')
        ->not->toContain(LegalMentions::vatExemptionNotice());

    // No external resource: everything is inline, and the page forbids any fetch.
    expect($html)->toContain("default-src 'none'")
        ->not->toMatch('/(src|href)\s*=\s*["\']?(https?:)?\/\//i')
        ->not->toMatch('/url\(\s*["\']?(https?:)?\/\//i')
        ->not->toContain('@import');

    expect(app(QuotePdf::class)->footerHtml($quote))
        ->toContain('class="pageNumber"')
        ->toContain('class="totalPages"')
        ->toContain('CF-2026-0001');
});

it('prints the exemption notice and a single 0 % rate for an exempt company', function () {
    $quote = pdfQuote(['vat_regime' => VatRegime::Exempt]);
    expect($quote->vat_exempt)->toBeTrue();

    $html = app(QuotePdf::class)->html($quote);

    expect($html)->toContain(LegalMentions::vatExemptionNotice())
        ->toContain('13'.PDF_NBSP.'600,00'.PDF_NBSP.'€')
        ->not->toContain('20'.PDF_NBSP.'%');
});

it('embeds the logo as a data uri', function () {
    $quote = pdfQuote();
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    app(CompanyBranding::class)->storeLogo($quote->company, UploadedFile::fake()->createWithContent('logo.png', $bytes));

    expect(app(QuotePdf::class)->html($quote->refresh()))->toContain('src="data:image/png;base64,'.base64_encode($bytes).'"');
});

it('escapes every typed text', function () {
    $quote = pdfQuote(['quote_terms' => '<img src=x onerror=alert(1)>'], [
        ['training_id' => null, 'label' => '<script>alert("ligne")</script>', 'description' => '<b>gras</b>', 'quantity' => '1.00', 'unit' => 'forfait', 'unit_price' => '10.00', 'discount_percent' => '0.00', 'vat_rate' => '20.00'],
    ]);
    $quote->forceFill(['notes' => '<iframe src="https://evil.test"></iframe>'])->save();
    $quote->opportunity->forceFill(['title' => '<style>body{}</style>'])->save();

    $html = app(QuotePdf::class)->html($quote->refresh());

    expect($html)->not->toContain('<script>alert')
        ->toContain('&lt;script&gt;alert(&quot;ligne&quot;)&lt;/script&gt;')
        ->not->toContain('<b>gras</b>')
        ->not->toContain('<img src=x')
        ->not->toContain('<iframe')
        ->not->toContain('<style>body{}');
});

it('previews a draft on the fly with a watermark', function () {
    fakeGotenberg();
    $quote = pdfQuote();
    $this->actingAs(commercial());

    $response = $this->get("/crm/quotes/{$quote->id}/pdf")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="CF-2026-0001.pdf"');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toBe('%PDF-1.7 rendu 1')
        ->and(sentHtml())->toContain('BROUILLON')
        ->and($quote->refresh()->pdf_path)->toBeNull();
});

it('freezes a quote: recipient copied, pdf archived under the tenant', function () {
    fakeGotenberg();
    $quote = pdfQuote();

    DB::transaction(fn () => app(QuoteFreezer::class)->freeze($quote));
    $quote->refresh();

    expect($quote->pdf_path)->toBe(TenantStorage::path('quotes', "{$quote->id}/CF-2026-0001.pdf"))
        ->and(Storage::disk(TenantStorage::DISK)->get($quote->pdf_path))->toBe('%PDF-1.7 rendu 1')
        ->and($quote->recipient_snapshot)->toEqual([
            'name' => 'Le Bistrot',
            'attention' => 'Léa Durand',
            'address_line1' => '3 place du Marché',
            'address_line2' => null,
            'postal_code' => '74000',
            'city' => 'Annecy',
            'email' => 'lea.durand@example.test',
        ])
        // The archived document of a quote being sent has no watermark.
        ->and(sentHtml())->not->toContain('BROUILLON');
});

it('sent quote is frozen against company changes', function () {
    fakeGotenberg();
    $quote = pdfQuote();
    DB::transaction(fn () => app(QuoteFreezer::class)->freeze($quote));
    // Test only: Task 7's send sets the status in the same transaction.
    $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();
    $archived = Storage::disk(TenantStorage::DISK)->get((string) $quote->refresh()->pdf_path);

    // The company becomes exempt and changes its branding; the client moves.
    $quote->company->update(['vat_regime' => VatRegime::Exempt, 'name' => 'Nouveau nom', 'quote_footer' => 'Autre pied']);
    $quote->organisation->update(['name' => 'Bistrot renommé', 'city' => 'Lyon']);

    $this->actingAs(commercial());
    $response = $this->get("/crm/quotes/{$quote->id}/pdf")->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="CF-2026-0001.pdf"');

    expect($response->streamedContent())->toBe($archived)
        ->and($archived)->toBe('%PDF-1.7 rendu 1');
    Http::assertSentCount(1); // the archive is served, nothing is rendered again

    $quote->refresh();
    expect($quote->vat_exempt)->toBeFalse()
        ->and([$quote->total_vat, $quote->total_ttc])->toBe(['1270.00', '14870.00'])
        ->and($quote->recipient_snapshot['name'])->toBe('Le Bistrot');

    // Its html still uses the frozen recipient and VAT.
    $html = app(QuotePdf::class)->html($quote);
    expect($html)->toContain('Le Bistrot')->not->toContain('Bistrot renommé')
        ->not->toContain(LegalMentions::vatExemptionNotice())
        ->not->toContain('BROUILLON');
});

it('never renders again a sent quote whose archive is lost: 410 and a warning', function () {
    fakeGotenberg();
    $quote = pdfQuote();
    DB::transaction(fn () => app(QuoteFreezer::class)->freeze($quote));
    $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()])->save();
    $quote->company->update(['name' => 'Nouveau nom']);
    Storage::disk(TenantStorage::DISK)->delete((string) $quote->refresh()->pdf_path);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = [$e->level, $e->message, $e->context];
    });

    $this->actingAs(commercial());
    $this->get("/crm/quotes/{$quote->id}/pdf")->assertStatus(410)
        ->assertSee('Le PDF archivé est introuvable : contactez l&#039;administrateur.', false)
        ->assertDontSee('données anonymisées')
        ->assertDontSee('Nouveau nom');
    Http::assertSentCount(1); // the freeze only

    expect($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('warning')
        ->and($logged[0][1])->toBe('Archive PDF du devis introuvable')
        ->and(array_keys($logged[0][2]))->toEqualCanonicalizing(['tenant_id', 'quote_id']);

    // A draft is still previewed on the fly.
    $draft = makeQuote($quote->opportunity, $quote->company);
    $this->get("/crm/quotes/{$draft->id}/pdf")->assertOk();
    Http::assertSentCount(2);
});

it('answers 404 for a quote outside the das of a responsable, or of another organisme', function () {
    fakeGotenberg();
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $visible = makeQuote(makeOpportunity($organisation, ['das_id' => $mine->id]));
    $hidden = makeQuote(makeOpportunity($organisation, ['das_id' => $other->id]));
    $foreign = app(TenantContext::class)->run(Tenant::factory()->create(), function () {
        CapFormationReferenceSeeder::run(tenant());

        return makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Ailleurs'])), Company::factory()->default()->create());
    });
    inTenant();

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable);

    $this->get("/crm/quotes/{$visible->id}/pdf")->assertOk();
    $this->get("/crm/quotes/{$hidden->id}/pdf")->assertNotFound();
    $this->get("/crm/quotes/{$foreign->id}/pdf")->assertNotFound();
    Http::assertSentCount(1);
});

it('shows a french error when gotenberg fails', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);
    $quote = pdfQuote();
    $this->actingAs(commercial());

    $this->get("/crm/quotes/{$quote->id}/pdf")->assertStatus(503)
        ->assertSee("Le PDF n'a pas pu être généré, réessayez dans un instant.");
});

it('limits the pdf rendering per user', function () {
    fakeGotenberg();
    $quote = pdfQuote();
    $this->actingAs(commercial());

    foreach (range(1, 30) as $i) {
        $this->get("/crm/quotes/{$quote->id}/pdf")->assertOk();
    }
    $this->get("/crm/quotes/{$quote->id}/pdf")->assertStatus(429);
});

it('renders a real pdf with gotenberg', function () {
    // The real service (compose « gotenberg », CI service): no fake here.
    Http::swap(new Factory);
    config(['documents.gotenberg_url' => getenv('GOTENBERG_URL') ?: 'http://gotenberg:3000']);
    $quote = pdfQuote();

    $draft = app(QuotePdf::class)->render($quote);
    expect(substr($draft, 0, 5))->toBe('%PDF-');

    DB::transaction(fn () => app(QuoteFreezer::class)->freeze($quote));
    $archived = Storage::disk(TenantStorage::DISK)->get((string) $quote->refresh()->pdf_path);
    expect(substr((string) $archived, 0, 5))->toBe('%PDF-');
})->group('slow');
