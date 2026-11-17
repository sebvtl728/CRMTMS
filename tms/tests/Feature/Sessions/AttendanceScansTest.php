<?php

use App\Modules\Core\Access\Role;
use App\Modules\Sessions\Attendance\AttendanceSheetScan;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
});

function scanPdf(string $name = 'emargement.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

/** @return array{0: User, 1: Seance, 2: Seance} */
function scanSetup(): array
{
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    assert($mine !== null);
    $other = plTrainer('Autre');
    $module = plModule(plSession());
    $own = plSeance($module, '2026-09-10', '09:00', '12:00', ['trainer_id' => $mine->id]);
    $foreign = plSeance($module, '2026-09-10', '14:00', '17:00', ['trainer_id' => $other->id]);

    return [$formateur, $own, $foreign];
}

it('stores a signed scan (PDF or image) on the private disk and lists it with the séance grid', function () {
    [, $own] = scanSetup();
    $manager = atManager();

    $answer = $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertCreated();
    $scan = AttendanceSheetScan::query()->firstOrFail();
    $answer->assertJsonPath('scans.0.id', $scan->id)->assertJsonPath('scans.0.scope', 'seance');
    expect($scan->seance_id)->toBe($own->id)->and($scan->path)->toStartWith('tenants/'.tenant()->id.'/attendance_sheets/')
        ->and(Storage::disk('local')->exists($scan->path))->toBeTrue();

    $png = UploadedFile::fake()->image('scan.png', 20, 20);
    $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => $png, 'day' => 1], ['Accept' => 'application/json'])->assertCreated();
    expect(AttendanceSheetScan::query()->whereNull('seance_id')->count())->toBe(1);

    $this->actingAs($manager)->getJson("/seances/{$own->id}/attendance")->assertJsonCount(2, 'scans');
});

it('refuses a fake PDF, a script renamed, an empty file and a file above 10 Mo', function () {
    [, $own] = scanSetup();
    $manager = atManager();
    $post = fn (UploadedFile $file) => $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => $file], ['Accept' => 'application/json']);

    $post(UploadedFile::fake()->createWithContent('faux.pdf', '<?php echo 1;'))->assertStatus(422)->assertJsonValidationErrors('file');
    $post(UploadedFile::fake()->createWithContent('shell.php', "%PDF-1.4\n"))->assertStatus(422);
    $post(UploadedFile::fake()->createWithContent('vide.pdf', ''))->assertStatus(422);
    $post(UploadedFile::fake()->createWithContent('gros.pdf', '%PDF-1.4'.str_repeat('x', 11 * 1024 * 1024)))->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'Le scan ne doit pas dépasser 10 Mo.');
    expect(AttendanceSheetScan::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('reads a scan within the scope of its séance only (trainer: his séances)', function () {
    [$formateur, $own, $foreign] = scanSetup();
    $manager = atManager();
    $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($manager)->post("/seances/{$foreign->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertCreated();
    $mine = AttendanceSheetScan::query()->where('seance_id', $own->id)->firstOrFail();
    $theirs = AttendanceSheetScan::query()->where('seance_id', $foreign->id)->firstOrFail();

    $response = $this->actingAs($formateur)->get("/seances/{$own->id}/scans/{$mine->id}")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    $this->actingAs($formateur)->get("/seances/{$foreign->id}/scans/{$theirs->id}")->assertNotFound();
    // A scan of another séance addressed through his own: 404.
    $this->actingAs($formateur)->get("/seances/{$own->id}/scans/{$theirs->id}")->assertNotFound();
    // He may deposit a scan on his séance, never on another.
    $this->actingAs($formateur)->post("/seances/{$own->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($formateur)->post("/seances/{$foreign->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($formateur)->getJson("/seances/{$own->id}/attendance")->assertJsonCount(2, 'scans');
});

it('lets a manager delete a scan, never the trainer', function () {
    [$formateur, $own] = scanSetup();
    $manager = atManager();
    $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertCreated();
    $scan = AttendanceSheetScan::query()->firstOrFail();

    $this->actingAs($formateur)->deleteJson("/seances/{$own->id}/scans/{$scan->id}")->assertForbidden();
    expect(AttendanceSheetScan::query()->count())->toBe(1);

    $this->actingAs($manager)->deleteJson("/seances/{$own->id}/scans/{$scan->id}")->assertOk()->assertJsonCount(0, 'scans');
    expect(AttendanceSheetScan::query()->count())->toBe(0)->and(Storage::disk('local')->exists($scan->path))->toBeFalse();
    // Journaled without the file name.
    expect(DB::table('audit_logs')->where('new_values', 'like', '%emargement.pdf%')->orWhere('old_values', 'like', '%emargement.pdf%')->exists())->toBeFalse();
});

it('stores a neutral file name, refuses a scan on a cancelled séance and deletion in a closed month', function () {
    [, $own] = scanSetup();
    $manager = atManager();
    $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => scanPdf('Durand-Lea.pdf')], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('scans.0.name', 'emargement-2026-09-10.pdf');
    $scan = AttendanceSheetScan::query()->firstOrFail();

    $session = TrainingSession::query()->findOrFail($own->training_session_id);
    app(Closures::class)->close($session, '2026-09');
    $this->actingAs($manager)->deleteJson("/seances/{$own->id}/scans/{$scan->id}")->assertStatus(409);
    expect(AttendanceSheetScan::query()->count())->toBe(1);
    app(Closures::class)->reopen($session, '2026-09', 'Erreur');

    // Cancelled séance: no new scan.
    app(Seances::class)->cancel($own, 'Intempéries');
    $this->actingAs($manager)->post("/seances/{$own->id}/scans", ['file' => scanPdf()], ['Accept' => 'application/json'])->assertStatus(409);
});
