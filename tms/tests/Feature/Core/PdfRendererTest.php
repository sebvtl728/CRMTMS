<?php

use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfRenderingFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => config(['documents.gotenberg_url' => 'http://gotenberg:3000']));

/**
 * @return array<string, array{contents: string, filename: string|null}>
 */
function multipartParts(Request $request): array
{
    $parts = [];
    foreach ($request->data() as $part) {
        $parts[$part['name'].($part['name'] === 'files' ? ':'.$part['filename'] : '')] = [
            'contents' => (string) $part['contents'],
            'filename' => $part['filename'] ?? null,
        ];
    }

    return $parts;
}

it('posts the html to gotenberg as an A4 multipart conversion', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $pdf = app(PdfRenderer::class)->fromHtml('<p>Devis</p>', '<span class="pageNumber"></span>');

    expect($pdf)->toBe('%PDF-1.7 fake');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $parts = multipartParts($request);

        return $request->method() === 'POST'
            && $request->url() === 'http://gotenberg:3000/forms/chromium/convert/html'
            && $request->isMultipart()
            && $parts['files:index.html']['contents'] === '<p>Devis</p>'
            && $parts['files:footer.html']['contents'] === '<span class="pageNumber"></span>'
            && $parts['paperWidth']['contents'] === '8.27'
            && $parts['paperHeight']['contents'] === '11.7'
            && $parts['marginTop']['contents'] === '0.6'
            && $parts['marginBottom']['contents'] === '0.6'
            && $parts['marginLeft']['contents'] === '0.6'
            && $parts['marginRight']['contents'] === '0.6'
            && $parts['printBackground']['contents'] === 'true';
    });
});

it('sends no footer file without a footer', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 fake')]);

    app(PdfRenderer::class)->fromHtml('<p>Devis</p>');

    Http::assertSent(fn (Request $request) => ! array_key_exists('files:footer.html', multipartParts($request)));
});

it('turns a gotenberg error into a french failure, logged without the document', function () {
    Log::spy();
    Http::fake(['gotenberg:3000/*' => Http::response('Internal Server Error', 503)]);

    try {
        app(PdfRenderer::class)->fromHtml('<p>Contenu confidentiel du devis</p>');
        $this->fail('PdfRenderingFailed expected');
    } catch (PdfRenderingFailed $e) {
        expect($e->getMessage())->toBe("Le PDF n'a pas pu être généré, réessayez dans un instant.")
            ->and($e->getStatusCode())->toBe(503);
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => ! str_contains($message.json_encode($context), 'confidentiel')
        && ($context['status'] ?? null) === 503);
});

it('fails the same way when gotenberg is unreachable or answers something else than a pdf', function (Closure $fake) {
    Http::fake(['gotenberg:3000/*' => $fake]);

    app(PdfRenderer::class)->fromHtml('<p>Devis</p>');
})->with([
    'unreachable' => fn () => fn () => Http::failedConnection(),
    'not a pdf' => fn () => fn () => Http::response('<html>oops</html>', 200),
])->throws(PdfRenderingFailed::class, "Le PDF n'a pas pu être généré, réessayez dans un instant.");

it('waits 30 seconds at most for gotenberg', function () {
    expect(config('documents.timeout'))->toBe(30)
        ->and(config('documents.gotenberg_url'))->toBeString()->not->toBe('');
});
