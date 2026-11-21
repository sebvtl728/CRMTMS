<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignedSheet;
use App\Modules\Sessions\Attendance\Signing\SignedSheetPdf;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Gdpr\PersonSessionsExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    Company::factory()->default()->create(['name' => 'CAP FORMATION']);
    sgNow();
    $this->actingAs(atManager());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function gsSign(string $slotId, string $enrolmentId): AttendanceSignature
{
    return app(AttendanceSigning::class)->sign(SignSlot::query()->findOrFail($slotId), $enrolmentId, sigDrawing(), '10.0.0.1', 'Firefox');
}

it('erases her drawings, address and browser when a trainee is anonymised, the hour kept', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $slotId = sgOpen($session)['slot']->id;
    $hers = gsSign($slotId, $lea->id);
    $his = gsSign($slotId, $paul->id);

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');

    $disk = Storage::disk(TenantStorage::DISK);
    expect($hers->fresh())->drawing_path->toBeNull()->ip->toBeNull()->user_agent->toBeNull()->signed_at->not->toBeNull()
        ->and($disk->exists((string) $hers->drawing_path))->toBeFalse()
        ->and($his->fresh()->drawing_path)->not->toBeNull()
        ->and($disk->exists((string) $his->drawing_path))->toBeTrue();
});

it('deletes the signed sheets of an individual session, keeps those of a collective one', function () {
    fakeSheetGotenberg();
    $solo = plSession(overrides: ['kind' => 'individual', 'capacity' => null]);
    plSeance(plModule($solo), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($solo, 'Durand');
    $group = plSession();
    plSeance(plModule($group), '2026-09-10', '09:00', '12:00');
    atEnrol($group, 'Petit');
    $manager = atManager();
    $soloSheet = app(SignedSheetPdf::class)->generate($solo, CarbonImmutable::parse('2026-09-01'), $manager);
    $groupSheet = app(SignedSheetPdf::class)->generate($group, CarbonImmutable::parse('2026-09-01'), $manager);

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');

    expect(SignedSheet::query()->whereKey($soloSheet->id)->exists())->toBeFalse()
        ->and(Storage::disk(TenantStorage::DISK)->exists($soloSheet->path))->toBeFalse()
        ->and(SignedSheet::query()->whereKey($groupSheet->id)->exists())->toBeTrue();
});

it('exports her online signatures', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    gsSign(sgOpen($session)['slot']->id, $lea->id);

    $export = PersonSessionsExport::for($lea->person_id);

    expect($export['emargements'])->toHaveCount(1)
        ->and($export['emargements'][0])->toMatchArray(['session' => $session->number, 'date' => '2026-09-10', 'demi_journee' => 'Matin', 'source' => 'online'])
        ->and($export['emargements'][0]['signe_le'])->not->toBeNull();
});
