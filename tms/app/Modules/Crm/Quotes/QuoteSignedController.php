<?php

namespace App\Modules\Crm\Quotes;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Joindre le devis signé » / « Remplacer » on an accepted quote
 * (crm.update on an editable quote: not trashed, nor its opportunity or
 * fiche), and the download of the copy (crm.view, never cached).
 */
class QuoteSignedController extends Controller
{
    public function __construct(private readonly QuoteSignedCopy $copies) {}

    public function store(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $request->validate(QuoteSignedCopy::rules(true), QuoteSignedCopy::messages());
        $file = $request->file(QuoteSignedCopy::FIELD);
        assert($file instanceof UploadedFile);
        $this->copies->store($quote, $file);

        return to_route('crm.quotes.show', $quote)->with('status', 'Devis signé enregistré.');
    }

    public function show(Quote $quote): StreamedResponse
    {
        Gate::authorize('view', $quote);
        $copy = QuoteSignedCopy::file($quote);
        abort_if($copy === null, 404);

        $disk = Storage::disk(TenantStorage::DISK);

        return response()->stream(function () use ($disk, $copy): void {
            $stream = $disk->readStream($copy['path']);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $copy['mime'],
            'Content-Length' => (string) $disk->size($copy['path']),
            // Named after the quote, not after the uploaded file (personal).
            'Content-Disposition' => 'inline; filename="'.$quote->number.'-signe.'.$copy['extension'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
