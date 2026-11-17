<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    Company::factory()->default()->create(['name' => 'CAP FORMATION', 'address_line1' => '12 rue des Écoles']);
});

it('prints the sheet of a séance: session, module, date, slots, trainer, one line per trainee expected that day', function () {
    fakeSheetGotenberg();
    $trainer = plTrainer('Martin');
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-10', '09:00', '17:00', ['trainer_id' => $trainer->id, 'break_minutes' => 60]);
    atEnrol($session, 'Durand');
    atEnrol($session, 'Petit');
    atEnrol($session, 'Tardif', ['entered_on' => '2026-09-14']);          // enters after the séance
    $gone = atEnrol($session, 'Parti');
    app(Enrolments::class)->abandon($gone, CarbonImmutable::parse('2026-09-09'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);

    $response = $this->actingAs(atManager())->get("/seances/{$seance->id}/sheet")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->streamedContent())->toStartWith('%PDF-');

    $html = sheetHtml();
    expect($html)->toContain('CAP FORMATION')->toContain($session->label(l2Training()->title))->toContain($module->title)
        ->toContain('10/09/2026')->toContain('09:00')->toContain('17:00')->toContain('Martin')
        ->toContain('Durand')->toContain('Petit')->not->toContain('Tardif')->not->toContain('Parti')
        ->toContain('Matin')->toContain('Après-midi')->toContain('Formateur');
    expect(substr_count($html, 'class="trainee"'))->toBe(2);
});

it('prints one sheet for every séance of the session that day', function () {
    fakeSheetGotenberg();
    $session = plSession();
    $module = plModule($session);
    $morning = plSeance($module, '2026-09-10', '09:00', '12:00');
    plSeance($module, '2026-09-10', '14:00', '17:00');
    plSeance($module, '2026-09-11', '09:00', '12:00');
    atEnrol($session, 'Durand');

    $this->actingAs(atManager())->get("/seances/{$morning->id}/sheet?day=1")->assertOk();

    $html = sheetHtml();
    expect($html)->toContain('09:00')->toContain('12:00')->toContain('14:00')->toContain('17:00')
        ->and(substr_count($html, 'class="trainee"'))->toBe(1);
});

it('escapes what is typed and refuses a cancelled séance', function () {
    fakeSheetGotenberg();
    $session = plSession(overrides: ['title' => '<script>alert(1)</script>']);
    $seance = plSeance(plModule($session), '2026-09-10');
    atEnrol($session, '<b>Nom</b>');

    $this->actingAs(atManager())->get("/seances/{$seance->id}/sheet")->assertOk();
    expect(sheetHtml())->not->toContain('<script>alert')->not->toContain('<b>Nom</b>')->toContain('&lt;script&gt;');

    app(Seances::class)->cancel($seance, 'Intempéries');
    $this->actingAs(atManager())->get("/seances/{$seance->id}/sheet")->assertStatus(409);
});

it('answers 503 in French when the PDF service is down', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10');

    $this->actingAs(atManager())->getJson("/seances/{$seance->id}/sheet")->assertStatus(503)->assertJsonPath('message', PdfRenderingFailed::MESSAGE);
});

it('prints a sheet within the scope only: the trainer his séances, nobody else\'s (404)', function () {
    fakeSheetGotenberg();
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    assert($mine !== null);
    $other = plTrainer('Autre');
    $session = plSession();
    $module = plModule($session);
    $own = plSeance($module, '2026-09-10', '09:00', '12:00', ['trainer_id' => $mine->id]);
    $foreign = plSeance($module, '2026-09-10', '14:00', '17:00', ['trainer_id' => $other->id]);
    atEnrol($session, 'Durand');

    $this->actingAs($formateur)->get("/seances/{$own->id}/sheet")->assertOk();
    $this->actingAs($formateur)->get("/seances/{$foreign->id}/sheet")->assertNotFound();
    // The day sheet holds only the séances he reaches.
    $this->actingAs($formateur)->get("/seances/{$own->id}/sheet?day=1")->assertOk();
    expect(sheetHtml())->toContain('09:00')->not->toContain('14:00');

    $nobody = userWithPermissions([]);
    $this->actingAs($nobody)->get("/seances/{$own->id}/sheet")->assertNotFound();
});
