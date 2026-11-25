<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Automation\RunAutomationsNow;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => tenant());

it('lets an administrator read and save the automation settings', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/automation')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('admin/automation')->where('settings.convocation_days', 7)->where('settings.day_before', true));

    $this->put('/admin/automation', ['convocation_days' => 5, 'day_before' => false, 'signature_reminder_days' => 4, 'signature_reminder_max' => 3])
        ->assertRedirect('/admin/automation')->assertSessionHas('status', 'Réglages des automatismes enregistrés.');
    expect(AutomationSettings::all())->toBe(['convocation_days' => 5, 'day_before' => false, 'signature_reminder_days' => 4, 'signature_reminder_max' => 3, 'certificates' => false, 'convention_on_quote' => false, 'booklet' => false]);

    $this->put('/admin/automation', ['convocation_days' => 40, 'day_before' => true, 'signature_reminder_days' => 3, 'signature_reminder_max' => 2])
        ->assertSessionHasErrors(['convocation_days' => '30 jours au plus.']);
});

it('refuses the page to a user without settings.manage', function () {
    $this->actingAs(userWithRole(Role::Commercial))->get('/admin/automation')->assertForbidden();
    $this->post('/admin/automation/run')->assertForbidden();
});

it('queues a run now', function () {
    Queue::fake();

    $this->actingAs(admin(twoFactor: true))->post('/admin/automation/run')->assertRedirect('/admin/automation')
        ->assertSessionHas('status', 'Les automatismes sont lancés : les e-mails partent dans quelques instants.');
    Queue::assertPushed(RunAutomationsNow::class);
});
