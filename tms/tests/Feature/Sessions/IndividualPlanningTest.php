<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

// ---------------------------------------------------------------- exemptions

it('leaves a trainee exempted from a séance out of its expected trainees', function () {
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-02');
    $second = plSeance($module, '2026-09-03');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $first->id]);

    expect(Attendances::expected($first)->pluck('id')->all())->toBe([$marc->id])
        ->and(Attendances::expected($second)->pluck('id')->sort()->values()->all())->toBe(collect([$lea->id, $marc->id])->sort()->values()->all());
});

it('leaves a trainee exempted from a module out of every séance of it, later ones included', function () {
    $session = plSession();
    $module = plModule($session);
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_MODULE, 'session_module_id' => $module->id]);
    $later = plSeance($module, '2026-09-10');

    expect(Attendances::expected($later)->pluck('id')->all())->toBe([$marc->id]);
});

it('refuses the attendance of an exempted trainee', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seance->id]);

    try {
        app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['enrolment_id'][0])->toBe('Ce stagiaire est dispensé de cette séance.');
    }
});

it('isolates the adjustments per tenant', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seance->id]);

    inTenant(Tenant::factory()->create());

    expect(EnrolmentAdjustment::query()->count())->toBe(0);
});
