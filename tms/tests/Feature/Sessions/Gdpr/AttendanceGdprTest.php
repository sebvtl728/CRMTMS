<?php

use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\AttendanceScans;
use App\Modules\Sessions\Attendance\AttendanceSheetScan;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Planning\Seance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__).'/Support/lot2a.php';
require_once dirname(__DIR__).'/Support/planning.php';
require_once dirname(__DIR__).'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    $this->actingAs(atManager());
});

function gdprScan(string $sessionId, string $date, string $seanceId): AttendanceSheetScan
{
    return app(AttendanceScans::class)->store(
        Seance::query()->findOrFail($seanceId), false,
        UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n"), null,
    );
}

it('clears the reasons but keeps the attendance and the hours of an anonymised trainee, and the scans of a collective session', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    app(Attendances::class)->record($seance, $lea->id, 'absent', null, 'Rendez-vous médical');
    app(Attendances::class)->record($seance, $marc->id, 'absent', null, 'Voiture en panne');
    $interval = atIntervalModule($session);
    app(MonthlyHours::class)->record($lea->id, $interval, '2026-09', 300, null);
    $scan = gdprScan($session->id, '2026-09-10', $seance->id);
    AttendanceSheetScan::query()->whereKey($scan->id)->update(['original_name' => 'Durand-Lea.pdf']);

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');

    $attendance = Attendance::query()->where('enrolment_id', $lea->id)->firstOrFail();
    expect($attendance->reason)->toBeNull()->and($attendance->status)->toBe('absent')
        ->and(Attendance::query()->where('enrolment_id', $marc->id)->firstOrFail()->reason)->toBe('Voiture en panne')
        ->and(MonthlyHour::query()->where('enrolment_id', $lea->id)->value('minutes'))->toBe(300)
        ->and(AttendanceSheetScan::query()->whereKey($scan->id)->value('original_name'))->toBe('emargement-2026-09-10.pdf')
        ->and(AttendanceSheetScan::query()->whereKey($scan->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($scan->path))->toBeTrue();
});

it('deletes the scans of an individual session (one trainee) when he is anonymised', function () {
    $session = plSession(overrides: ['kind' => 'individual', 'capacity' => null]);
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $scan = gdprScan($session->id, '2026-09-10', $seance->id);
    expect(Storage::disk('local')->exists($scan->path))->toBeTrue();

    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');

    expect(AttendanceSheetScan::query()->count())->toBe(0)->and(Storage::disk('local')->exists($scan->path))->toBeFalse();
});

it('adds the attendance and the monthly hours to the RGPD export of the person', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    app(Attendances::class)->record($seance, $lea->id, 'partial', 90, 'Train en retard');
    $interval = atIntervalModule($session);
    app(MonthlyHours::class)->record($lea->id, $interval, '2026-09', 300, null);

    $export = PersonDataExport::for(Person::query()->findOrFail($lea->person_id));

    expect($export['presences'])->toHaveCount(1)
        ->and($export['presences'][0])->toMatchArray(['session' => $session->number, 'date' => '2026-09-10', 'statut' => 'partiel', 'minutes' => 90, 'motif' => 'Train en retard'])
        ->and($export['heures_mensuelles'])->toHaveCount(1)
        ->and($export['heures_mensuelles'][0])->toMatchArray(['session' => $session->number, 'module' => $interval->title, 'mois' => '2026-09', 'minutes' => 300]);
});
