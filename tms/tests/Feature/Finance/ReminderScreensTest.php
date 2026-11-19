<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Reminders\ReminderPause;
use App\Modules\Finance\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

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

/** The page a month and a week later, with a fresh session. */
function remindPage(User $user): TestResponse
{
    test()->travelTo(CarbonImmutable::parse('2026-12-24 10:00', 'Europe/Paris'));

    return test()->flushSession()->actingAs($user)->get('/finance/reminders');
}

it('lists the invoices to remind in the scope of who prepares the invoices', function () {
    [$invoice, , $user] = payInvoice();

    remindPage($user)->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/reminders/index')
        ->where('reminders.0.id', $invoice->id)
        ->where('reminders.0.level', 1)
        ->where('reminders.0.days_late', 7)
        ->where('reminders.0.email', 'compta@hotel-du-parc.test'));

    $reader = l2User([App\Modules\Sessions\Permissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);
    $this->flushSession()->actingAs($reader)->get('/finance/reminders')->assertForbidden();
});

it('sends the reminders chosen, skipping an invoice paid meanwhile', function () {
    [$invoice, , $user] = payInvoice();
    $second = paySecondInvoice($invoice, $user, 5000);
    remindPage($user);
    payRecord($user, $second, 5000);

    $this->post('/finance/reminders/send', ['reminders' => [['id' => $invoice->id, 'level' => 1], ['id' => $second->id, 'level' => 1]]])
        ->assertRedirect()->assertSessionHas('status', '1 relance envoyée, 1 ignorée (déjà soldée, suspendue, déjà relancée ou sans adresse).');
    expect(InvoiceSending::query()->where('purpose', 'reminder')->pluck('invoice_id')->all())->toBe([$invoice->id]);
});

it('reminds one invoice with the message typed, never a paused one', function () {
    [$invoice, , $user] = payInvoice();
    remindPage($user);

    $this->post('/finance/invoices/'.$invoice->id.'/reminders/pause', ['reason' => 'Litige'])->assertRedirect()->assertSessionHas('status', 'Relances suspendues.');
    expect(ReminderPause::query()->count())->toBe(1);
    $this->post('/finance/invoices/'.$invoice->id.'/remind', ['to' => 'a@b.test', 'subject' => 'Relance', 'body' => 'Bonjour'])->assertStatus(409);

    $this->delete('/finance/invoices/'.$invoice->id.'/reminders/pause')->assertRedirect()->assertSessionHas('status', 'Relances reprises.');
    // The level shown is no longer the one due: refused.
    $this->post('/finance/invoices/'.$invoice->id.'/remind', ['to' => 'a@b.test', 'subject' => 'Relance', 'body' => 'Bonjour', 'level' => 2])->assertStatus(409);
    $this->post('/finance/invoices/'.$invoice->id.'/remind', ['to' => 'a@b.test', 'subject' => 'Relance', 'body' => 'Bonjour', 'level' => 1])
        ->assertRedirect()->assertSessionHas('status', 'Relance de niveau 1 en cours d\'envoi.');
    expect(InvoiceSending::query()->where('purpose', 'reminder')->sole()->email)->toBe('a@b.test')
        ->and(AuditLog::query()->where('event', 'invoice.reminder_queued')->count())->toBe(1);
});

it('shows the reminders of an invoice on its page', function () {
    [$invoice, , $user] = payInvoice();
    remindPage($user);

    $this->get('/finance/invoices/'.$invoice->id)->assertInertia(fn (Assert $p) => $p
        ->where('reminders.next_level', 1)
        ->where('reminders.paused', null)
        ->where('reminders.defaults.to', 'compta@hotel-du-parc.test')
        ->has('reminders.history', 0)
        ->where('can.remind', true));
});

it('saves the reminder settings of the organisme', function () {
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->put('/finance/settings/reminders', ['days' => ['10', '40'], 'auto' => true])
        ->assertRedirect('/finance/settings')->assertSessionHas('status', 'Réglages des relances enregistrés.');
    expect(FinanceSettings::reminderDays())->toBe([10, 40])->and(FinanceSettings::autoReminders())->toBeTrue();

    $this->actingAs($admin)->get('/finance/settings')->assertInertia(fn (Assert $p) => $p
        ->where('reminders.days', [10, 40])->where('reminders.auto', true));
    $this->actingAs(fiManager())->put('/finance/settings/reminders', ['days' => [5], 'auto' => false])->assertForbidden();
});
