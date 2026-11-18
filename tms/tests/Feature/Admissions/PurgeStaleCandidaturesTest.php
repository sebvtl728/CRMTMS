<?php

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Candidatures\CandidatureFiles;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2029-03-10 10:00', 'Europe/Paris'));
});

function adLeft(CandidatureStatus $status, string $lastChange, string $name = 'Durand'): Candidature
{
    $c = adCandidature(['person_id' => adPerson($name)->id, 'last_diploma' => 'Brevet', 'adaptation_needs' => 'Fauteuil'], direction());
    if ($status === CandidatureStatus::Rejected) {
        app(CandidatureTransitions::class)->move($c, $status, admin(), crmItem(ReferenceList::CandidatureRefusalReason, 'Autre')->id);
    } elseif ($status !== CandidatureStatus::Identified) {
        app(CandidatureTransitions::class)->move($c, $status, admin());
    }
    Candidature::query()->whereKey($c->id)->update(['updated_at' => CarbonImmutable::parse($lastChange, 'Europe/Paris')]);

    return $c->fresh();
}

it('anonymises the rejected and withdrawn candidatures untouched for two years, nothing else', function () {
    $old = adLeft(CandidatureStatus::Rejected, '2027-03-09 09:00', 'Alpha');
    app(CandidatureFiles::class)->store($old, 'positioning', UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\n%%EOF\n"), admin());
    Candidature::query()->whereKey($old->id)->update(['updated_at' => CarbonImmutable::parse('2027-03-09 09:00', 'Europe/Paris')]);
    $withdrawn = adLeft(CandidatureStatus::Withdrawn, '2027-01-01 09:00', 'Bravo');
    $recent = adLeft(CandidatureStatus::Rejected, '2027-03-11 09:00', 'Charlie');
    $admitted = adLeft(CandidatureStatus::Admitted, '2026-01-01 09:00', 'Delta');
    $open = adLeft(CandidatureStatus::Identified, '2026-01-01 09:00', 'Echo');

    $this->artisan('tms:admissions:purge')->expectsOutput('Candidatures anonymisées : 2.')->assertSuccessful();

    expect(Candidature::query()->find($old->id)->anonymised_at)->not->toBeNull()
        ->and(Candidature::query()->find($old->id)->last_diploma)->toBeNull()
        ->and(Candidature::query()->find($old->id)->number)->toBe($old->number)
        ->and(CandidatureFile::query()->count())->toBe(0)
        ->and(Candidature::query()->find($withdrawn->id)->anonymised_at)->not->toBeNull()
        ->and(Candidature::query()->find($recent->id)->anonymised_at)->toBeNull()
        ->and(Candidature::query()->find($admitted->id)->anonymised_at)->toBeNull()
        ->and(Candidature::query()->find($open->id)->anonymised_at)->toBeNull()
        // The CRM person is not touched.
        ->and(Person::query()->find($old->person_id)->anonymised_at)->toBeNull();

    // Already anonymised: skipped.
    $this->artisan('tms:admissions:purge')->expectsOutput('Candidatures anonymisées : 0.')->assertSuccessful();
});

it('purges every organisme', function () {
    $other = Tenant::factory()->create();
    $there = app(TenantContext::class)->run($other, function () {
        CapFormationReferenceSeeder::run(app(TenantContext::class)->current());

        return adLeft(CandidatureStatus::Withdrawn, '2026-01-01 09:00');
    });
    inTenant();

    $this->artisan('tms:admissions:purge')->assertSuccessful();

    app(TenantContext::class)->run($other, fn () => expect(Candidature::query()->find($there->id)->anonymised_at)->not->toBeNull());
});

it('runs every day at 03:50 Paris time', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn (Illuminate\Console\Scheduling\Event $e) => str_contains((string) $e->command, 'tms:admissions:purge'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('50 3 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});
