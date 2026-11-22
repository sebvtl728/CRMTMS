<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\SignatureReminders;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/../../Documents/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    tenant();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'Europe/Paris'));
    $this->path = TenantStorage::path('documents', '2026/original.pdf');
    Storage::disk(TenantStorage::DISK)->put($this->path, "%PDF-1.7 original\n");
});

/** A pending request created now: [request, token]. */
function srRequest(object $test, string $subject = '01a1f000-0000-7000-8000-000000000001', string $email = 'claire.martin@hotel-parc.test'): array
{
    ['request' => $request, 'token' => $token] = app(Signatures::class)->request('document', $subject, $test->path, hash('sha256', "%PDF-1.7 original\n"),
        'Claire Martin', $email, null, 'Convention de formation DOC-2026-00001', admin());

    return [$request, $token];
}

function srRun(): int
{
    return app(SignatureReminders::class)->run(CarbonImmutable::now('Europe/Paris'));
}

/** The link token of the last e-mail sent. */
function srToken(): string
{
    preg_match('#/signature/([A-Za-z0-9]{40})#', Mail::sent(SignatureMail::class)->last()->text, $m);

    return $m[1];
}

it('sends a new link after three days and kills the old one', function () {
    [$request, $old] = srRequest($this);
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Europe/Paris'));
    expect(srRun())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));
    expect(srRun())->toBe(1);
    $new = srToken();
    expect($new)->not->toBe($old)
        ->and(Signatures::find($old))->toBeNull()
        ->and(Signatures::find($new)?->id)->toBe($request->id)
        ->and(Mail::sent(SignatureMail::class)->last()->text)->toContain('remplace le précédent')
        ->and($request->fresh()->reminders_sent)->toBe(1)
        ->and($request->fresh()->expires_at->eq($request->expires_at))->toBeTrue();
});

it('stops after two reminders and never twice the same day', function () {
    [$request] = srRequest($this);
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));
    srRun();
    expect(srRun())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-12-07 10:00', 'Europe/Paris'));
    expect(srRun())->toBe(1);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00', 'Europe/Paris'));
    expect(srRun())->toBe(0)->and($request->fresh()->reminders_sent)->toBe(2);
});

it('leaves a signer who asked for a code in the last 24 hours', function () {
    [$request, $token] = srRequest($this);
    $this->travelTo(CarbonImmutable::parse('2026-12-04 09:00', 'Europe/Paris'));
    $request->forceFill(['code_sent_at' => CarbonImmutable::now()])->save();
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));

    expect(srRun())->toBe(0)->and(Signatures::find($token))->not->toBeNull();
});

it('never reminds a refused, cancelled or turned-off request', function () {
    Signatures::registerGuard('document', fn (string $id) => $id === '01a1f000-0000-7000-8000-000000000002' ? 'Plus attendu.' : null);
    srRequest($this, '01a1f000-0000-7000-8000-000000000002');
    [$cancelled] = srRequest($this, '01a1f000-0000-7000-8000-000000000003');
    $cancelled->forceFill(['status' => 'cancelled'])->save();
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));
    expect(srRun())->toBe(0);

    srRequest($this, '01a1f000-0000-7000-8000-000000000004');
    $this->travelTo(CarbonImmutable::parse('2026-12-08 10:00', 'Europe/Paris'));
    AutomationSettings::save(['signature_reminder_days' => 0]);
    expect(srRun())->toBe(0);
});

it('checks again under the lock that the signer did not just ask for a code', function () {
    [$request] = srRequest($this);
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));
    $request->forceFill(['code_sent_at' => CarbonImmutable::now()->subMinute()])->save();

    expect(SignatureReminders::eligible($request->fresh(), CarbonImmutable::now()))->toBeFalse()
        ->and(SignatureReminders::eligible($request->fresh()->forceFill(['code_sent_at' => null]), CarbonImmutable::now()))->toBeTrue();
});

it('keeps the old link working when the reminder e-mail is refused', function () {
    [$request, $old] = srRequest($this);
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00', 'Europe/Paris'));
    Mail::swap(app('mail.manager'));
    docSmtp(fn () => throw new TransportException('550 refused'));

    expect(srRun())->toBe(0)
        ->and(Signatures::find($old)?->id)->toBe($request->id)
        ->and($request->fresh()->reminders_sent)->toBe(0);
});
