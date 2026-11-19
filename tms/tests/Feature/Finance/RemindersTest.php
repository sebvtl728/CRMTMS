<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Finance\Invoices\CreditNotes;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Invoices\SendInvoice;
use App\Modules\Finance\Reminders\ReminderMail;
use App\Modules\Finance\Reminders\Reminders;
use App\Modules\Finance\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/** Travels to a day of Paris (10:00). */
function remindOn(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 10:00', 'Europe/Paris'));
}

/** Marks the last reminder of the invoice as gone (as SendInvoice would). */
function remindDelivered(Invoice $invoice, string $status = 'sent'): void
{
    InvoiceSending::query()->where('invoice_id', $invoice->id)->where('purpose', 'reminder')->where('status', 'pending')
        ->update(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null]);
}

it('reminds nothing before the first level, then level 1 seven days after the due date', function () {
    [$invoice] = payInvoice();
    $reminders = app(Reminders::class);

    remindOn('2026-12-23');
    expect($reminders->nextLevel($invoice))->toBeNull()
        ->and($reminders->due(null))->toBe([]);

    remindOn('2026-12-24');
    $due = $reminders->due(null);
    expect($due)->toHaveCount(1)
        ->and($due[0]['invoice']->id)->toBe($invoice->id)
        ->and($due[0]['level'])->toBe(1)
        ->and($due[0]['days_late'])->toBe(7)
        ->and($due[0]['remaining_cents'])->toBe(17143)
        ->and($due[0]['email'])->toBe('compta@hotel-du-parc.test');
});

it('goes to level 2 only once level 1 has left and thirty days have passed', function () {
    Queue::fake();
    [$invoice] = payInvoice();
    $reminders = app(Reminders::class);

    remindOn('2026-12-24');
    $sending = $reminders->send(null, $invoice);
    expect($sending->purpose)->toBe('reminder')->and($sending->reminder_level)->toBe(1)->and($sending->sent_by)->toBeNull();
    Queue::assertPushed(SendInvoice::class, fn (SendInvoice $job) => $job->sendingId === $sending->id);

    // Still pending: nothing more to send.
    expect($reminders->due(null))->toBe([]);
    remindDelivered($invoice);

    remindOn('2027-01-15');
    expect($reminders->nextLevel($invoice))->toBeNull();
    remindOn('2027-01-16');
    expect($reminders->nextLevel($invoice))->toBe(2);
});

it('offers the same level again when the reminder did not leave', function () {
    Queue::fake();
    [$invoice] = payInvoice();
    remindOn('2026-12-24');
    app(Reminders::class)->send(null, $invoice);
    remindDelivered($invoice, 'failed');

    expect(app(Reminders::class)->nextLevel($invoice))->toBe(1);
});

it('never reminds a paid, paused or fully credited invoice', function () {
    Queue::fake();
    [$invoice, , $user] = payInvoice();
    $reminders = app(Reminders::class);
    remindOn('2026-12-24');

    $reminders->pause($user, $invoice, 'Litige sur les heures');
    expect($reminders->due(null))->toBe([]);
    expect(fn () => $reminders->send($user, $invoice))->toThrow(UserFacingHttpException::class, Reminders::NOT_DUE);
    $reminders->resume($user, $invoice);
    expect($reminders->due(null))->toHaveCount(1);

    payRecord($user, $invoice, 17143, ['received_on' => '2026-12-24']);
    expect($reminders->due(null))->toBe([])
        ->and($reminders->nextLevel($invoice))->toBeNull();
});

it('never reminds an invoice cancelled by a credit note', function () {
    [$invoice, , $user] = payInvoice();
    $credit = app(CreditNotes::class)->create($user, $invoice, null);
    app(InvoiceValidator::class)->validate($credit->refresh(), $user);

    remindOn('2026-12-24');
    expect(app(Reminders::class)->due(null))->toBe([]);
});

it('leaves the penalties out of the reminder of a particulier', function () {
    [$invoice] = payInvoice();
    remindOn('2027-01-16');
    $professional = ReminderMail::defaults($invoice, 2);
    expect($professional['body'])->toContain('40 €')->and($professional['body'])->toContain('171,43');

    $particulier = new Invoice;
    $particulier->setRawAttributes($invoice->getAttributes());
    $particulier->recipient_type = 'person';
    expect(ReminderMail::defaults($particulier, 2)['body'])->not->toContain('40 €');
});

it('validates the reminder levels of the organisme', function () {
    $error = function (array $days): ?string {
        try {
            FinanceSettings::saveReminders($days, false);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    };

    expect($error([]))->toBe('Indiquez de 1 à 3 niveaux de relance.')
        ->and($error([7, 30, 60, 90]))->toBe('Indiquez de 1 à 3 niveaux de relance.')
        ->and($error([30, 7]))->toBe('Chaque niveau vient après le précédent (délais croissants).')
        ->and($error([0]))->toBe('Chaque délai est un nombre de jours de 1 à 365.')
        ->and($error([10, 40]))->toBeNull()
        ->and(FinanceSettings::reminderDays())->toBe([10, 40]);

    FinanceSettings::saveReminders([5], true);
    expect(FinanceSettings::autoReminders())->toBeTrue();
});

it('spaces the levels of a backlog and never sends two the same day', function () {
    Queue::fake();
    [$invoice] = payInvoice();
    $reminders = app(Reminders::class);

    // 70 days late: level 1 first, whatever the delay.
    remindOn('2027-02-25');
    expect($reminders->nextLevel($invoice))->toBe(1);
    $reminders->send(null, $invoice);
    remindDelivered($invoice);
    expect($reminders->nextLevel($invoice))->toBeNull();

    // Level 2 only 23 days (30 − 7) after level 1.
    remindOn('2027-03-19');
    expect($reminders->nextLevel($invoice))->toBeNull();
    remindOn('2027-03-20');
    expect($reminders->nextLevel($invoice))->toBe(2);
});

it('refuses a level other than the one shown', function () {
    Queue::fake();
    [$invoice] = payInvoice();
    remindOn('2026-12-24');

    expect(fn () => app(Reminders::class)->send(null, $invoice, null, 2))->toThrow(UserFacingHttpException::class, Reminders::NOT_DUE);
});

it('never sends again a failed reminder of an invoice paid meanwhile', function () {
    Queue::fake();
    [$invoice, , $user] = payInvoice();
    remindOn('2026-12-24');
    app(Reminders::class)->send($user, $invoice);
    remindDelivered($invoice, 'failed');
    Invoice::query()->whereKey($invoice->id)->toBase()->update(['email_status' => 'failed']);
    payRecord($user, $invoice, 17143, ['received_on' => '2026-12-24']);

    expect(fn () => app(App\Modules\Finance\Invoices\InvoiceSender::class)->resend($user, $invoice->refresh()))
        ->toThrow(UserFacingHttpException::class, Reminders::NOT_DUE);
});

it('does not send a reminder paid between its queueing and its sending', function () {
    Queue::fake();
    Illuminate\Support\Facades\Mail::fake();
    [$invoice, , $user] = payInvoice();
    remindOn('2026-12-24');
    $sending = app(Reminders::class)->send($user, $invoice);
    payRecord($user, $invoice, 17143, ['received_on' => '2026-12-24']);

    app()->call([new SendInvoice($sending->id), 'handle']);

    expect($sending->refresh()->status)->toBe('failed')
        ->and($sending->error_code)->toBe('not_due')
        ->and($sending->error)->toBe(SendInvoice::NOT_DUE);
    Illuminate\Support\Facades\Mail::assertNothingSent();
});
