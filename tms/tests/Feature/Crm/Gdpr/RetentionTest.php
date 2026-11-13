<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Crm\Gdpr\AnonymiseExpired;
use App\Modules\Crm\Gdpr\GdprPending;
use App\Modules\Crm\Gdpr\GdprPendingMail;
use App\Modules\Crm\Gdpr\RetentionPolicy;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    tenant();
    Mail::fake();
});

/**
 * A person whose last contact (or creation) is $yearsAgo years old.
 *
 * @param  array<string, mixed>  $attributes
 */
function agedPerson(string $name, float $yearsAgo, bool $contact = true, array $attributes = []): Person
{
    $person = makePerson($name, $attributes);
    $at = now()->subDays((int) round($yearsAgo * 365.25));
    DB::table('people')->where('id', $person->id)->update(['created_at' => $at, 'last_contact_at' => $contact ? $at : null]);

    return $person->refresh();
}

function setRetention(int $years): void
{
    $tenant = tenant();
    $settings = $tenant->settings;
    $settings['gdpr'] = ['retention_years' => $years];
    $tenant->forceFill(['settings' => $settings])->save();
}

/** @return list<string> */
function expiredNames(): array
{
    return RetentionPolicy::expiredPeople()->orderBy('last_name')->pluck('last_name')->all();
}

it('reads the retention period of the organisme (3 years by default, 1 to 10)', function () {
    expect(RetentionPolicy::for(tenant()))->toBe(3);
    setRetention(5);
    expect(RetentionPolicy::for(tenant()->refresh()))->toBe(5);
    setRetention(42);
    expect(RetentionPolicy::for(tenant()->refresh()))->toBe(3);
});

it('lists the people whose last contact is older than the retention period', function () {
    agedPerson('Ancien', 4);
    agedPerson('Recent', 2);
    agedPerson('SansContact', 4, contact: false);
    $renewed = agedPerson('Relance', 5);
    DB::table('people')->where('id', $renewed->id)->update(['last_contact_at' => now()->subMonths(3)]);

    expect(expiredNames())->toBe(['Ancien', 'SansContact']);

    setRetention(5);
    expect(expiredNames())->toBe([]);
    setRetention(1);
    expect(expiredNames())->toBe(['Ancien', 'Recent', 'SansContact']);
});

it('never lists a client, an anonymised, a merged or a kept person', function () {
    $client = Organisation::query()->create(['name' => 'Client SA', 'status' => 'client']);
    $prospect = Organisation::query()->create(['name' => 'Prospect SA', 'status' => 'prospect']);

    affiliate(agedPerson('ChezClient', 6), $client);
    affiliate(agedPerson('ChezProspect', 6), $prospect);
    affiliate(agedPerson('AncienChezClient', 6), $client, ['ended_on' => now()->subYears(5)->toDateString()]);
    agedPerson('ParticulierClient', 6, attributes: ['status' => 'client', 'is_individual' => true]);
    $anonymised = agedPerson('Anonymisee', 6);
    DB::table('people')->where('id', $anonymised->id)->update(['anonymised_at' => now()]);
    $merged = agedPerson('Fusionnee', 6);
    DB::table('people')->where('id', $merged->id)->update(['merged_into_id' => agedPerson('Gardee', 1)->id]);
    $kept = agedPerson('Conservee', 6);
    DB::table('people')->where('id', $kept->id)->update(['gdpr_kept_until' => now()->addYear(), 'gdpr_kept_reason' => 'Litige en cours']);
    $trashed = agedPerson('Corbeille', 6);
    $trashed->delete();

    expect(expiredNames())->toBe(['AncienChezClient', 'ChezProspect', 'Corbeille']);
});

it('lists first, then anonymises after 30 days only', function () {
    $person = agedPerson('Ancien', 4, attributes: ['email' => 'ancien@example.test']);

    AnonymiseExpired::dispatch();
    $pending = GdprPending::query()->sole();
    expect($pending->person_id)->toBe($person->id)
        ->and($person->refresh()->anonymised_at)->toBeNull();

    $this->travel(29)->days();
    AnonymiseExpired::dispatch();
    expect($person->refresh()->anonymised_at)->toBeNull()->and(GdprPending::query()->count())->toBe(1);

    $this->travel(1)->days();
    $this->travel(1)->minutes();
    AnonymiseExpired::dispatch();
    $person->refresh();
    expect($person->anonymised_at)->not->toBeNull()
        ->and($person->last_name)->toBe('Anonyme')
        ->and($person->email)->toBeNull()
        ->and(GdprPending::query()->count())->toBe(0);

    $audit = AuditLog::query()->where('event', 'gdpr.anonymised')->sole();
    expect($audit->auditable_id)->toBe($person->id)
        ->and($audit->new_values)->toEqualCanonicalizing(['reason' => 'retention', 'notes' => 0, 'attachments' => 0]);
});

it('is idempotent and mails the gdpr managers the number listed, without personal data', function () {
    $admin = admin(twoFactor: true);
    $direction = direction(twoFactor: true);
    $commercial = commercial();
    agedPerson('Zébulonnière', 4);
    agedPerson('Ancien', 5);

    AnonymiseExpired::dispatch();
    AnonymiseExpired::dispatch();

    expect(GdprPending::query()->count())->toBe(2);
    Mail::assertSent(GdprPendingMail::class, 2);
    Mail::assertSent(GdprPendingMail::class, fn (GdprPendingMail $m) => $m->hasTo($admin->email) && $m->count === 2);
    Mail::assertSent(GdprPendingMail::class, fn (GdprPendingMail $m) => $m->hasTo($direction->email));
    Mail::assertNotSent(GdprPendingMail::class, fn (GdprPendingMail $m) => $m->hasTo($commercial->email));

    $mail = new GdprPendingMail(2, now()->addDays(30));
    $html = $mail->render();
    expect($html)->toContain('2 personnes')->not->toContain('Zébulonnière')->not->toContain('Ancien');
});

it('removes a person from the list after a new contact', function () {
    $person = agedPerson('Ancien', 4);
    AnonymiseExpired::dispatch();
    expect(GdprPending::query()->count())->toBe(1);

    $this->actingAs(commercial())->post("/crm/people/{$person->id}/notes", ['body' => 'Rappel'])->assertSessionHasNoErrors();
    AnonymiseExpired::dispatch();

    expect(GdprPending::query()->count())->toBe(0);
    $this->travel(31)->days();
    AnonymiseExpired::dispatch();
    expect($person->refresh()->anonymised_at)->toBeNull();
});

it('keeps a person on request: removed from the list, audited, re-evaluated after the period', function () {
    $person = agedPerson('Ancien', 4);
    AnonymiseExpired::dispatch();

    $user = direction(twoFactor: true);
    $this->actingAs($user)->post("/crm/gdpr/{$person->id}/keep", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($user)->post("/crm/gdpr/{$person->id}/keep", ['reason' => 'Formation financée en cours'])->assertSessionHasNoErrors();

    expect(GdprPending::query()->count())->toBe(0)
        ->and($person->refresh()->gdpr_kept_until?->toDateString())->toBe(now()->addYears(3)->toDateString());
    $audit = AuditLog::query()->where('event', 'gdpr.kept')->sole();
    expect($audit->new_values['reason'])->toBe('Formation financée en cours');

    $this->travel(40)->days();
    AnonymiseExpired::dispatch();
    expect(GdprPending::query()->count())->toBe(0)->and($person->refresh()->anonymised_at)->toBeNull();

    $this->travel(3)->years();
    AnonymiseExpired::dispatch();
    expect(GdprPending::query()->count())->toBe(1);
});

it('shows the screen « À anonymiser » with the planned date (crm.gdpr only)', function () {
    $person = agedPerson('Ancien', 4);
    AnonymiseExpired::dispatch();

    $this->actingAs(direction(twoFactor: true))->get('/crm/gdpr')->assertOk()->assertInertia(fn ($page) => $page
        ->component('crm/gdpr/index')
        ->where('pending.0.id', $person->id)
        ->where('pending.0.name', 'Ancien')
        ->where('pending.0.planned_at', now()->addDays(30)->toDateString())
        ->where('retention.years', 3));

    $this->actingAs(commercial())->get('/crm/gdpr')->assertForbidden();
    $this->actingAs(commercial())->post("/crm/gdpr/{$person->id}/keep", ['reason' => 'x'])->assertForbidden();
});

it('changes the retention period (admin), audited and bounded', function () {
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->put('/crm/gdpr/retention', ['retention_years' => 11])->assertSessionHasErrors('retention_years');
    $this->actingAs($admin)->put('/crm/gdpr/retention', ['retention_years' => 0])->assertSessionHasErrors('retention_years');
    $this->actingAs($admin)->put('/crm/gdpr/retention', ['retention_years' => 5])->assertSessionHasNoErrors();

    expect(RetentionPolicy::for(tenant()->refresh()))->toBe(5);
    $audit = AuditLog::query()->where('event', 'settings.gdpr_changed')->sole();
    expect($audit->old_values)->toBe(['retention_years' => 3])->and($audit->new_values)->toBe(['retention_years' => 5]);

    // Direction manages the list, not the settings.
    $this->actingAs(direction(twoFactor: true))->put('/crm/gdpr/retention', ['retention_years' => 4])->assertForbidden();
});

it('is scheduled every night at 03:30 for every organisme', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'tms:crm:gdpr-retention'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 3 * * *');

    agedPerson('Ancien', 4);
    Artisan::call('tms:crm:gdpr-retention');
    expect(GdprPending::query()->count())->toBe(1);
});

it('postpones a person while an import is running', function () {
    $person = agedPerson('Ancien', 4);
    AnonymiseExpired::dispatch();
    $this->travel(31)->days();

    $this->actingAs(commercial());
    fakeImportDisk();
    $batch = uploadImport("Nom\nMartin\n", ImportTarget::People);
    $batch->forceFill(['status' => ImportBatch::RUNNING])->save();

    AnonymiseExpired::dispatch();
    expect($person->refresh()->anonymised_at)->toBeNull()->and(GdprPending::query()->count())->toBe(1);

    $batch->forceFill(['status' => ImportBatch::DONE])->save();
    AnonymiseExpired::dispatch();
    expect($person->refresh()->anonymised_at)->not->toBeNull();
});

it('never touches another organisme', function () {
    $other = Tenant::factory()->create();
    inTenant($other);
    $foreign = agedPerson('Etranger', 6);
    inTenant();

    AnonymiseExpired::dispatch();
    expect(GdprPending::query()->count())->toBe(0);

    inTenant($other);
    expect(Person::query()->find($foreign->id)?->anonymised_at)->toBeNull();
});
