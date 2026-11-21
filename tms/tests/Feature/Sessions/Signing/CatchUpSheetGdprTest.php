<?php

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\SignedSheetPdf;
use App\Modules\Sessions\Attendance\Signing\SigningAnonymisation;
use App\Modules\Sessions\Gdpr\PersonSessionsExport;
use App\Modules\Sessions\Public\AttendanceSigning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    sgNow();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('prints the catch-up mention on the signed sheet', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $marc = atEnrol($session, 'Roux');
    $slot = sgOpen($session)['slot'];
    app(AttendanceSigning::class)->validateExternal($slot->id, 'fake', null, 'Marie Martin');
    $manager = atManager();
    $make = fn ($e, array $extra) => AttendanceSignature::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $e->id, 'kind' => AttendanceSignature::KIND_REMOTE,
        'signed_at' => CarbonImmutable::parse('2026-09-11 18:05', 'Europe/Paris'),
    ])->forceFill($extra)->save();
    $make($lea, ['confirmed_at' => CarbonImmutable::parse('2026-09-12 09:00', 'Europe/Paris'), 'confirmed_by' => $manager->id]);
    $make($paul, []);
    $make($marc, ['refused_at' => now(), 'refused_by' => $manager->id, 'refusal_reason' => 'Absent']);

    $html = app(SignedSheetPdf::class)->html($session, CarbonImmutable::parse('2026-09-01', 'Europe/Paris'));

    expect($html)->toContain('Signé en rattrapage le 11/09/2026 à 18:05 (à distance, code e-mail), confirmé par '.$manager->name.' le 12/09/2026')
        ->toContain('Rattrapage à confirmer')
        ->toContain('Rattrapage refusé')
        ->toContain('à distance, lien personnel et code e-mail à usage unique');
});

it('erases catch-up link e-mails on anonymisation and exports the kind', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    $link = CatchUpLink::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'email' => 'lea@example.test',
        'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(7),
    ]);
    AttendanceSignature::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'kind' => AttendanceSignature::KIND_ONSITE, 'signed_at' => now(),
    ])->forceFill(['confirmed_at' => now()])->save();

    $export = PersonSessionsExport::for($lea->person_id);
    expect($export['emargements'][0])->toMatchArray(['type' => 'Rattrapage sur place', 'confirme' => true]);

    SigningAnonymisation::scrub([$lea->id], []);
    expect($link->refresh())->email->toBeNull()->revoked_at->not->toBeNull();
});
