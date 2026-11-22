<?php

use App\Modules\Core\Access\Role;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Attendance\AttendanceProof;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
});

function proofPdf(string $name = 'arret-maladie-lea.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

it('stores the proof of an absence on the private disk and lists it with the grid', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'excused', null, null);
    $manager = atManager();

    $this->actingAs($manager)->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])
        ->assertCreated();

    $proof = AttendanceProof::query()->sole();
    expect($proof->seance_id)->toBe($seance->id)->and($proof->enrolment_id)->toBe($lea->id)
        ->and($proof->path)->toStartWith('tenants/'.tenant()->id.'/attendance_proofs/')
        ->and($proof->original_name)->toBe('justificatif-2026-09-02.pdf')
        ->and(Storage::disk('local')->exists($proof->path))->toBeTrue();

    $this->actingAs($manager)->getJson("/seances/{$seance->id}/attendance")
        ->assertJsonPath('can_manage_proofs', true)
        ->assertJsonPath("proofs.{$lea->id}.0.id", $proof->id);

    $response = $this->actingAs($manager)->get("/seances/{$seance->id}/proofs/{$proof->id}")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('refuses a file that is not a PDF or an image, and an enrolment of another session', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    $other = atEnrol(plSession(), 'Petit');
    $manager = atManager();

    $this->actingAs($manager)->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => UploadedFile::fake()->createWithContent('x.pdf', '<?php')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    $this->actingAs($manager)->post("/seances/{$seance->id}/enrolments/{$other->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])
        ->assertNotFound();
    expect(AttendanceProof::query()->count())->toBe(0);
});

it('keeps the proofs away from a trainer (« Ses séances »): health data', function () {
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00', ['trainer_id' => $mine->id]);
    $lea = atEnrol($session);
    $this->actingAs(atManager())->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])->assertCreated();
    $proof = AttendanceProof::query()->sole();

    $this->actingAs($formateur)->getJson("/seances/{$seance->id}/attendance")->assertJsonPath('can_manage_proofs', false)->assertJsonPath('proofs', []);
    $this->actingAs($formateur)->get("/seances/{$seance->id}/proofs/{$proof->id}")->assertForbidden();
    $this->actingAs($formateur)->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])->assertForbidden();
});

it('deletes a proof, and erases the proofs of an anonymised trainee', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:00');
    $lea = atEnrol($session);
    $manager = atManager();
    $this->actingAs($manager)->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($manager)->post("/seances/{$seance->id}/enrolments/{$lea->id}/proofs", ['file' => proofPdf()], ['Accept' => 'application/json'])->assertCreated();
    $first = AttendanceProof::query()->orderBy('created_at')->firstOrFail();

    $this->actingAs($manager)->deleteJson("/seances/{$seance->id}/proofs/{$first->id}")->assertOk();
    expect(AttendanceProof::query()->count())->toBe(1)->and(Storage::disk('local')->exists($first->path))->toBeFalse();

    $kept = AttendanceProof::query()->sole();
    app(Anonymiser::class)->anonymise(Person::query()->findOrFail($lea->person_id), 'request');
    expect(AttendanceProof::query()->count())->toBe(0)->and(Storage::disk('local')->exists($kept->path))->toBeFalse();
});
