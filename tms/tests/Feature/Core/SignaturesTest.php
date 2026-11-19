<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\Events\SignatureCompleted;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

const SIG_PDF = "%PDF-1.7 original\n";

beforeEach(function () {
    fakeImportDisk();
    tenant();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response("%PDF-1.7 rendu\n", 200, ['Content-Type' => 'application/pdf'])]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
    $this->path = TenantStorage::path('documents', '2026/original.pdf');
    Storage::disk(TenantStorage::DISK)->put($this->path, SIG_PDF);
});

function sigRequest(object $test): array
{
    return app(Signatures::class)->request('document', '01a1f000-0000-7000-8000-000000000001', $test->path, hash('sha256', SIG_PDF),
        'Claire Martin', 'claire.martin@hotel-parc.test', null, 'Convention de formation DOC-2026-00001', admin());
}

/** The code of the last e-mail sent to the signer. */
function sigCode(): string
{
    $mail = Mail::sent(SignatureMail::class)->last();
    preg_match('/\b(\d{6})\b/', $mail->text, $m);

    return $m[1];
}

it('creates a request: only the hash of the link is kept, valid 30 days, the previous one of the subject cancelled', function () {
    ['request' => $first] = sigRequest($this);
    ['request' => $request, 'token' => $token] = sigRequest($this);

    expect(strlen($token))->toBe(40)
        ->and($request->token_hash)->toBe(hash('sha256', $token))
        ->and($request->expires_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-12-19 23:59')
        ->and($first->fresh()->status)->toBe('cancelled')
        ->and(Signatures::find($token)?->id)->toBe($request->id)
        ->and(Signatures::find('x'.substr($token, 1)))->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-12-20 08:00', 'Europe/Paris'));
    expect(Signatures::find($token))->toBeNull();
});

it('sends a 6-digit code at most once a minute, and refuses a wrong, expired or exhausted code', function () {
    ['request' => $request] = sigRequest($this);
    $signatures = app(Signatures::class);

    $signatures->sendCode($request);
    Mail::assertSent(SignatureMail::class, fn ($m) => $m->hasTo('claire.martin@hotel-parc.test'));
    expect(fn () => $signatures->sendCode($request->fresh()))->toThrow(UserFacingHttpException::class, 'Patientez une minute avant de demander un nouveau code.');

    expect(fn () => $signatures->sign($request->fresh(), '000000', '203.0.113.7', 'Firefox'))->toThrow(ValidationException::class, 'Code incorrect.');
    foreach (range(2, 5) as $_) {
        try {
            $signatures->sign($request->fresh(), '000000', '203.0.113.7', 'Firefox');
        } catch (ValidationException) {
        }
    }
    expect(fn () => $signatures->sign($request->fresh(), sigCode(), '203.0.113.7', 'Firefox'))->toThrow(ValidationException::class, 'Trop d\'essais : demandez un nouveau code.');

    $this->travel(2)->minutes();
    $signatures->sendCode($request->fresh());
    $this->travel(11)->minutes();
    expect(fn () => $signatures->sign($request->fresh(), sigCode(), '203.0.113.7', 'Firefox'))->toThrow(ValidationException::class, 'Ce code a expiré : demandez-en un nouveau.');
});

it('signs: the original PDF plus a proof page, an event after commit, a confirmation with the signed PDF', function () {
    Event::fake([SignatureCompleted::class]);
    ['request' => $request] = sigRequest($this);
    $signatures = app(Signatures::class);
    $signatures->sendCode($request);

    $signed = $signatures->sign($request->fresh(), sigCode(), '203.0.113.7', 'Firefox 140');

    expect($signed->status)->toBe('signed')
        ->and($signed->signed_ip)->toBe('203.0.113.7')
        ->and($signed->signed_at)->not->toBeNull()
        ->and(Storage::disk(TenantStorage::DISK)->get($signed->signed_pdf_path))->toBe("%PDF-1.7 rendu\n")
        ->and(Storage::disk(TenantStorage::DISK)->get($this->path))->toBe(SIG_PDF)
        ->and($signed->signed_pdf_sha256)->toBe(hash('sha256', "%PDF-1.7 rendu\n"));
    Event::assertDispatched(SignatureCompleted::class, fn ($e) => $e->subjectType === 'document' && $e->requestId === $signed->id);
    Mail::assertSent(SignatureMail::class, fn ($m) => $m->attachment !== null);

    // The proof page names the signer, the date of Paris, the IP and the fingerprint of the original.
    $proof = '';
    Http::assertSent(function ($r) use (&$proof) {
        foreach ($r->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $proof = (string) $part['contents'];
            }
        }

        return true;
    });
    expect($proof)->toContain('Claire Martin', 'claire.martin@hotel-parc.test', '19/11/2026', '203.0.113.7', hash('sha256', SIG_PDF));
    expect(Signatures::find('anything'))->toBeNull()
        ->and(fn () => $signatures->sign($signed, '123456', '1.1.1.1', 'x'))->toThrow(UserFacingHttpException::class);
});

it('refuses to sign when the original was altered', function () {
    ['request' => $request] = sigRequest($this);
    $signatures = app(Signatures::class);
    $signatures->sendCode($request);
    Storage::disk(TenantStorage::DISK)->put($this->path, '%PDF-1.7 autre');

    expect(fn () => $signatures->sign($request->fresh(), sigCode(), '203.0.113.7', 'Firefox'))
        ->toThrow(fn (UserFacingHttpException $e) => expect($e->getStatusCode())->toBe(410));
    expect(SignatureRequest::query()->sole()->status)->toBe('pending');
});
