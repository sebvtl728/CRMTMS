<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignedSheet;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotValidation;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    Company::factory()->default()->create(['name' => 'CAP FORMATION', 'address_line1' => '12 rue des Écoles']);
    sgNow();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A session with a validated morning (Léa signed, Paul not) and an open afternoon. */
function ssSession(array $overrides = []): array
{
    $session = plSession(overrides: $overrides);
    $module = plModule($session);
    plSeance($module, '2026-09-10', '09:00', '12:00');
    plSeance($module, '2026-09-10', '14:00', '17:00');
    $lea = atEnrol($session, 'Durand');
    atEnrol($session, 'Petit');
    $manager = atManager();
    test()->actingAs($manager);
    $morning = sgOpen($session, by: $manager)['slot'];
    app(AttendanceSigning::class)->sign($morning, $lea->id, sigDrawing(), '', '');
    app(SlotValidation::class)->validate($morning->fresh(), sigDrawing(), $manager);
    sgNow('2026-09-10 14:05');
    sgOpen($session, half: SignSlot::AFTERNOON, by: $manager);

    return [$session, $manager];
}

it('renders the month with the drawings, the validation and the unvalidated half-days', function () {
    fakeSheetGotenberg();
    [$session, $manager] = ssSession(['title' => '<script>alert(1)</script>']);

    $this->actingAs($manager)->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertCreated()
        ->assertJsonPath('sheets.0.month', '2026-09');

    $html = sheetHtml();
    expect($html)->toContain('CAP FORMATION')->toContain('Léa Durand')->toContain('Léa Petit')
        ->toContain('data:image/png;base64,')->toContain('Validé par')->toContain('gestionnaire des présences')
        ->toContain('Non validé')->toContain('Mois non clôturé')->toContain('Absent')
        ->toContain('Jeudi 10 septembre 2026')
        ->not->toContain('<script>alert')->toContain('&lt;script&gt;');
});

it('archives each generation with its SHA-256 and serves the archive, 410 when altered', function () {
    fakeSheetGotenberg();
    [$session, $manager] = ssSession();
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertCreated();
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertCreated()
        ->assertJsonCount(2, 'sheets');

    $sheet = SignedSheet::query()->orderBy('created_at')->firstOrFail();
    $disk = Storage::disk(TenantStorage::DISK);
    expect($sheet->pdf_sha256)->toBe(hash('sha256', (string) $disk->get($sheet->path)));
    $response = $this->actingAs($manager)->get("/signed-sheets/{$sheet->id}")->assertOk();
    expect($response->streamedContent())->toStartWith('%PDF-');

    $disk->put($sheet->path, 'altered');
    $this->actingAs($manager)->get("/signed-sheets/{$sheet->id}")->assertStatus(410);
});

it('lets a trainer download the sheet of his session, never generate one', function () {
    fakeSheetGotenberg();
    $formateur = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $manager = atManager();
    $this->actingAs($manager)->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertCreated();
    $sheet = SignedSheet::query()->sole();
    $stranger = userWithRole(Role::Formateur);
    Trainers::forUser($stranger) ?? Trainers::ensureForUser($stranger);

    $this->flushSession();
    $this->actingAs($formateur)->get("/signed-sheets/{$sheet->id}")->assertOk();
    $this->actingAs($formateur)->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertNotFound();
    $this->flushSession();
    $this->actingAs($stranger)->get("/signed-sheets/{$sheet->id}")->assertNotFound();
});

it('answers 503 in French and archives nothing when the PDF service is down', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');

    $this->actingAs(atManager())->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2026-09'])->assertStatus(503);
    expect(SignedSheet::query()->count())->toBe(0);
});

it('refuses a month outside the session', function () {
    $session = plSession();
    $this->actingAs(atManager())->postJson("/sessions/{$session->id}/signed-sheets", ['month' => '2027-01'])->assertStatus(422);
});
