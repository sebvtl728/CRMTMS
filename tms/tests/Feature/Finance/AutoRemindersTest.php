<?php

use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

it('sends nothing while automatic reminders are off', function () {
    payInvoice();
    $this->travelTo(CarbonImmutable::parse('2026-12-24 08:30', 'Europe/Paris'));

    $this->artisan('tms:finance:remind')->expectsOutput('Relances envoyées : 0.')->assertSuccessful();
    expect(InvoiceSending::query()->where('purpose', 'reminder')->count())->toBe(0);
});

it('sends the level due once a day, without author', function () {
    [$invoice] = payInvoice();
    FinanceSettings::saveReminders([7, 30, 60], true);
    $this->travelTo(CarbonImmutable::parse('2026-12-24 08:30', 'Europe/Paris'));

    $this->artisan('tms:finance:remind')->expectsOutput('Relances envoyées : 1.')->assertSuccessful();
    $sending = InvoiceSending::query()->where('purpose', 'reminder')->sole();
    expect($sending->invoice_id)->toBe($invoice->id)->and($sending->sent_by)->toBeNull()->and($sending->reminder_level)->toBe(1);

    // Failed meanwhile: the same level is due again, but not twice the same day.
    $sending->forceFill(['status' => 'failed'])->save();
    Invoice::query()->whereKey($invoice->id)->toBase()->update(['email_status' => 'failed']);
    $this->artisan('tms:finance:remind')->expectsOutput('Relances envoyées : 0.')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-12-25 08:30', 'Europe/Paris'));
    $this->artisan('tms:finance:remind')->expectsOutput('Relances envoyées : 1.')->assertSuccessful();
});

it('is scheduled every morning at 8:30, Paris time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, 'tms:finance:remind'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 8 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});
