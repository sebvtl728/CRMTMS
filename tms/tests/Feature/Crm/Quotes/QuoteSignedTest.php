<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteSignedCopy;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
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

function signedPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function signedPng(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function signedUpload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/**
 * A quote sent through the real endpoint, awaiting the customer's answer.
 */
function signedSentQuote(): Quote
{
    test()->actingAs(commercial());
    $quote = sendableQuote('Étude');
    test()->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    return $quote->refresh();
}

it('accepts a quote with its signed copy, journaled without the file name, and serves it', function () {
    $quote = signedSentQuote();

    $this->post("/crm/quotes/{$quote->id}/accept", ['accepted_on' => '2026-11-14', 'signed' => signedUpload('Devis signé Durand.pdf', signedPdf())])
        ->assertSessionHasNoErrors();

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->signed_path)->toBe('tenants/'.tenant()->id."/quotes/{$quote->id}/signe.pdf")
        ->and($quote->signed_name)->toBe('Devis signé Durand.pdf')
        ->and($quote->signed_at?->toIso8601String())->toBe(now()->toIso8601String());
    Storage::disk(TenantStorage::DISK)->assertExists($quote->signed_path);

    $journal = AuditLog::query()->where('auditable_id', $quote->id)->get()
        ->map(fn ($l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode(' ');
    expect($journal)->not->toContain('Durand.pdf');
    $log = AuditLog::query()->where('auditable_id', $quote->id)->where('event', 'quote.signed_added')->sole();
    expect($log->new_values)->toEqual(['type' => 'pdf', 'size' => strlen(signedPdf()), 'sha256' => hash('sha256', signedPdf())]);
    expect(Timeline::for($quote->organisation)->where('type', 'quote')->pluck('title')->all())->toContain('Devis signé ajouté (CF-2026-0001)');

    $response = $this->get("/crm/quotes/{$quote->id}/signe")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->streamedContent())->toBe(signedPdf());

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('quote.signed.name', 'Devis signé Durand.pdf')
        ->where('can.attachSigned', true));
});

it('still accepts a quote without a file, and refuses a renamed file or an svg', function () {
    $quote = signedSentQuote();
    $url = "/crm/quotes/{$quote->id}/accept";

    $this->post($url, ['signed' => signedUpload('signe.pdf', '<?php echo 1;')])
        ->assertSessionHasErrors(['signed' => 'Le devis signé doit être un PDF, une image JPEG ou PNG.']);
    $this->post($url, ['signed' => signedUpload('signe.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
        ->assertSessionHasErrors(['signed' => 'Le devis signé doit être un PDF, une image JPEG ou PNG.']);
    $this->post($url, ['signed' => UploadedFile::fake()->create('gros.pdf', 10241, 'application/pdf')])
        ->assertSessionHasErrors(['signed' => 'Le devis signé ne doit pas dépasser 10 Mo.']);
    expect($quote->refresh()->status)->not->toBe(QuoteStatus::Accepted);

    $this->post($url, [])->assertSessionHasNoErrors();
    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Accepted)->and($quote->signed_path)->toBeNull();
    $this->get("/crm/quotes/{$quote->id}/signe")->assertNotFound();
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('quote.signed', null)->where('can.attachSigned', true));
});

it('attaches then replaces the signed copy of an accepted quote, deleting the previous file', function () {
    $quote = signedSentQuote();
    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors();
    $url = "/crm/quotes/{$quote->id}/signe";

    $this->post($url, ['signed' => signedUpload('scan.pdf', signedPdf())])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Devis signé enregistré.');
    $first = $quote->refresh()->signed_path;

    $this->post($url, ['signed' => signedUpload('photo.png', signedPng())])->assertSessionHasNoErrors();
    $quote->refresh();
    expect($quote->signed_path)->toEndWith('/signe.png')->and($quote->signed_name)->toBe('photo.png');
    Storage::disk(TenantStorage::DISK)->assertMissing($first);
    Storage::disk(TenantStorage::DISK)->assertExists($quote->signed_path);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->post($url, [])->assertSessionHasErrors(['signed' => 'Choisissez un fichier.']);
});

it('refuses a signed copy on a quote that is not accepted, without the right, or on a trashed fiche', function () {
    $quote = signedSentQuote();
    $url = "/crm/quotes/{$quote->id}/signe";

    $this->post($url, ['signed' => signedUpload('scan.pdf', signedPdf())])->assertStatus(409)
        ->assertSee('Seul un devis accepté peut recevoir son exemplaire signé.');

    $this->post("/crm/quotes/{$quote->id}/accept", [])->assertSessionHasNoErrors();
    $this->actingAs(direction(twoFactor: true))->post($url, ['signed' => signedUpload('scan.pdf', signedPdf())])->assertForbidden();
    $this->actingAs(userWithRole(Role::ResponsableDas))->get($url)->assertNotFound();

    $this->actingAs(commercial());
    $quote->organisation?->delete();
    $this->post($url, ['signed' => signedUpload('scan.pdf', signedPdf())])->assertForbidden();
    expect($quote->refresh()->signed_path)->toBeNull();
});

it('deletes the signed copy when the contact is anonymised, and lists it in her data export', function () {
    $quote = signedSentQuote();
    $this->post("/crm/quotes/{$quote->id}/accept", ['signed' => signedUpload('scan.pdf', signedPdf())])->assertSessionHasNoErrors();
    $quote->refresh();
    $path = $quote->signed_path;
    $contact = $quote->contact;
    assert($contact !== null);

    expect(collect(PersonDataExport::for($contact)['devis'])->pluck('devis_signe')->all())->toBe(['oui']);

    app(Anonymiser::class)->anonymise($contact, 'request');

    $quote->refresh();
    expect($quote->signed_path)->toBeNull()->and($quote->signed_name)->toBeNull()->and($quote->signed_at)->toBeNull();
    Storage::disk(TenantStorage::DISK)->assertMissing($path);
});

it('keeps the previous signed copy intact when saving the replacement fails', function () {
    $quote = signedSentQuote();
    $this->post("/crm/quotes/{$quote->id}/accept", ['signed' => signedUpload('scan.pdf', signedPdf())])->assertSessionHasNoErrors();
    $path = $quote->refresh()->signed_path;

    Quote::saving(function (): void {
        throw new RuntimeException('database down');
    });
    $other = "%PDF-1.7\nautre contenu\n%%EOF\n";
    expect(fn () => app(QuoteSignedCopy::class)->store($quote->refresh(), signedUpload('autre.pdf', $other)))
        ->toThrow(RuntimeException::class);

    $disk = Storage::disk(TenantStorage::DISK);
    expect($disk->get($path))->toBe(signedPdf())
        ->and(collect($disk->files(dirname($path)))->filter(fn (string $f) => str_starts_with(basename($f), '.tmp-'))->all())->toBe([]);
});

it('serves the signed copy in a sandbox', function () {
    $quote = signedSentQuote();
    $this->post("/crm/quotes/{$quote->id}/accept", ['signed' => signedUpload('scan.pdf', signedPdf())])->assertSessionHasNoErrors();

    $csp = (string) $this->get("/crm/quotes/{$quote->id}/signe")->headers->get('Content-Security-Policy');
    expect($csp)->toContain('sandbox')->toContain("default-src 'self'");
});
