<?php

namespace App\Modules\Core\Documents;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTML → PDF through the Gotenberg service of the stack (Chromium,
 * POST /forms/chromium/convert/html), A4 with 0.6 in margins.
 *
 * Gotenberg is infrastructure of the stack, not an external connector: its
 * address comes from the configuration (documents.gotenberg_url, an internal
 * compose service that is never published) and is not typed by a user, so it
 * does not go through Connectors\Core\HostPolicy (which refuses internal
 * hosts by design).
 *
 * The HTML must be self-contained (inline styles, images as data URIs):
 * documents forbid any fetch (Content-Security-Policy), and the service runs
 * without JavaScript nor outbound access (compose flags).
 *
 * A failure raises PdfRenderingFailed (French message); the log keeps the
 * status or the error class only, never the document.
 */
class PdfRenderer
{
    private const PAPER = [
        'paperWidth' => '8.27',
        'paperHeight' => '11.7',
        'marginTop' => '0.6',
        'marginBottom' => '0.6',
        'marginLeft' => '0.6',
        'marginRight' => '0.6',
        'printBackground' => 'true',
        'preferCssPageSize' => 'false',
    ];

    /**
     * @param  string|null  $footerHtml  repeated at the bottom of every page (Chromium classes pageNumber / totalPages)
     * @return string the PDF bytes
     *
     * @throws PdfRenderingFailed
     */
    /**
     * The PDFs merged in this order (Gotenberg PDF engines), e.g. a document
     * followed by its signature proof page.
     *
     * @param  list<string>  $pdfs
     *
     * @throws PdfRenderingFailed
     */
    public function merge(array $pdfs): string
    {
        $url = rtrim((string) config('documents.gotenberg_url'), '/').'/forms/pdfengines/merge';
        $request = Http::timeout((int) config('documents.timeout', 30))->connectTimeout(5);
        foreach ($pdfs as $i => $pdf) {
            $request = $request->attach('files', $pdf, sprintf('%02d.pdf', $i + 1), ['Content-Type' => 'application/pdf']);
        }
        try {
            /** @var Response $response */
            $response = $request->post($url);
        } catch (ConnectionException) {
            Log::warning('PDF merge failed: Gotenberg unreachable.');

            throw new PdfRenderingFailed;
        }
        $body = $response->body();
        if (! $response->successful() || ! str_starts_with($body, '%PDF-')) {
            Log::warning('PDF merge failed: Gotenberg answered an error.', ['status' => $response->status()]);

            throw new PdfRenderingFailed;
        }

        return $body;
    }

    public function fromHtml(string $html, ?string $footerHtml = null): string
    {
        $url = rtrim((string) config('documents.gotenberg_url'), '/').'/forms/chromium/convert/html';

        $request = Http::timeout((int) config('documents.timeout', 30))
            ->connectTimeout(5)
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=utf-8']);
        if ($footerHtml !== null) {
            $request = $request->attach('files', $footerHtml, 'footer.html', ['Content-Type' => 'text/html; charset=utf-8']);
        }

        try {
            /** @var Response $response */
            $response = $request->post($url, self::PAPER);
        } catch (ConnectionException $e) {
            Log::warning('PDF rendering failed: Gotenberg unreachable.', ['error' => $e::class]);

            throw new PdfRenderingFailed;
        }

        $body = $response->body();
        if (! $response->successful() || ! str_starts_with($body, '%PDF-')) {
            Log::warning('PDF rendering failed: Gotenberg answered an error.', [
                'status' => $response->status(),
                'trace' => $response->header('Gotenberg-Trace'),
            ]);

            throw new PdfRenderingFailed;
        }

        return $body;
    }
}
