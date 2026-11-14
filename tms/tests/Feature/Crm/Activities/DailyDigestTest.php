<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\DailyDigest;
use App\Modules\Crm\Activities\DailyDigestMail;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    Mail::fake();
    test()->travelTo(CarbonImmutable::parse('2026-11-16 07:30:00', 'Europe/Paris'));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function digestAction(User $user, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'subject' => 'Rappeler le directeur',
        'due_at' => CarbonImmutable::parse($due, 'Europe/Paris'),
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $user->id,
        ...$attributes,
    ]);
}

function optIn(User $user): User
{
    $user->forceFill(['daily_digest' => true])->save();

    return $user;
}

it('is scheduled at 07:30 Europe/Paris', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'tms:crm:daily-digest'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 7 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});

it('mails only the users who opted in, with their overdue and today actions', function () {
    $in = optIn(commercial());
    $out = commercial();
    $deactivated = optIn(commercial());
    foreach ([$in, $out, $deactivated] as $user) {
        digestAction($user, '2026-11-13 10:00:00', ['subject' => 'En retard']);
        digestAction($user, '2026-11-16 14:30:00', ['subject' => 'Aujourd’hui']);
        digestAction($user, '2026-11-18 14:30:00', ['subject' => 'Plus tard']);
    }
    $deactivated->forceFill(['deactivated_at' => now()])->save();

    Artisan::call('tms:crm:daily-digest');

    Mail::assertSent(DailyDigestMail::class, 1);
    Mail::assertSent(DailyDigestMail::class, function (DailyDigestMail $mail) use ($in) {
        $html = $mail->render();

        return $mail->hasTo($in->email)
            && str_contains($html, 'En retard') && str_contains($html, 'Aujourd’hui')
            && str_contains($html, 'Hôtel du Lac') && str_contains($html, '14:30')
            && ! str_contains($html, 'Plus tard')
            && str_contains($html, route('crm.todo'));
    });
});

it('puts no report and no place in the mail', function () {
    $user = optIn(commercial());
    digestAction($user, '2026-11-16 14:30:00', ['report' => 'Compte rendu confidentiel', 'location' => 'Salle secrète']);

    DailyDigest::dispatch($user->id);

    Mail::assertSent(DailyDigestMail::class, function (DailyDigestMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'Rappeler le directeur') && ! str_contains($html, 'confidentiel') && ! str_contains($html, 'secrète');
    });
});

it('sends nothing when there is nothing to do', function () {
    $user = optIn(commercial());
    digestAction($user, '2026-11-18 14:30:00');
    digestAction($user, '2026-11-14 14:30:00', ['done_at' => now()->subDays(2)]);

    Artisan::call('tms:crm:daily-digest');

    Mail::assertNothingSent();
});

it('respects the das visibility of the user', function () {
    $user = optIn(\userWithRole(Role::ResponsableDas));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    followDas($user, $mine);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    digestAction($user, '2026-11-16 14:30:00', ['organisation_id' => $hidden->id]);

    Artisan::call('tms:crm:daily-digest');

    Mail::assertNothingSent();
});

it('runs once per organisme with the users of that organisme only', function () {
    $mine = optIn(commercial());
    digestAction($mine, '2026-11-16 14:30:00');

    $second = tenantOnHost('autre.example.test');
    app(TenantContext::class)->run($second, function () {
        CapFormationReferenceSeeder::run(tenant());
        $user = optIn(commercial());
        digestAction($user, '2026-11-16 09:00:00', ['subject' => 'Autre organisme']);
    });
    inTenant();

    Artisan::call('tms:crm:daily-digest');

    Mail::assertSent(DailyDigestMail::class, 2);
    Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $m) => $m->hasTo($mine->email) && ! str_contains($m->render(), 'Autre organisme'));
});

it('lets a user opt in and out from the settings', function () {
    $user = commercial();

    expect($user->refresh()->daily_digest)->toBeFalse();
    $this->actingAs($user)->get('/settings/profile')->assertInertia(fn ($p) => $p->where('daily_digest', false));

    $this->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'daily_digest' => true])->assertSessionHasNoErrors();
    expect($user->refresh()->daily_digest)->toBeTrue();

    // A profile update that does not mention it leaves the choice alone.
    $this->patch('/settings/profile', ['name' => 'Nouveau nom', 'email' => $user->email])->assertSessionHasNoErrors();
    expect($user->refresh()->daily_digest)->toBeTrue();

    $this->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'daily_digest' => false])->assertSessionHasNoErrors();
    expect($user->refresh()->daily_digest)->toBeFalse();
});

it('links to the organisme address, not APP_URL, from the worker', function () {
    config(['tms.mode' => 'multi', 'app.url' => 'http://worker.invalid']);
    TenantDomain::factory()->for(tenant())->create(['host' => 'cap.example.test']);
    $user = optIn(commercial());
    digestAction($user, '2026-11-16 14:30:00');

    Artisan::call('tms:crm:daily-digest');

    Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => str_contains($mail->render(), 'https://cap.example.test/crm/todo')
        && ! str_contains($mail->render(), 'worker.invalid'));
});
