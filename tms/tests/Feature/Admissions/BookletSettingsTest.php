<?php

use App\Modules\Admissions\Booklet\BookletSettings;
use App\Modules\Catalogue\TrainingModules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
});

function bookletPdf(string $name = 'reglement.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

it('records the modules a candidate is exempted from, only modules of the training', function () {
    $training = adTraining();
    TrainingModules::sync($training, [
        ['title' => 'Mise en place', 'duration_hours' => '50'],
        ['title' => 'Dressage', 'duration_hours' => '42'],
    ]);
    $c = adCandidature(['training_id' => $training->id]);
    [$first, $second] = $training->modules()->orderBy('position')->pluck('id')->all();

    $this->actingAs(admin(twoFactor: true));
    $this->put("/admissions/candidatures/{$c->id}/dispenses", ['module_ids' => [$second]])->assertRedirect();
    expect($c->fresh()->dispensed_module_ids)->toBe([$second]);

    $this->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.booklet.modules', [
            ['id' => $first, 'title' => 'Mise en place', 'duration_hours' => '50.00', 'dispensed' => false],
            ['id' => $second, 'title' => 'Dressage', 'duration_hours' => '42.00', 'dispensed' => true],
        ]));

    $this->put("/admissions/candidatures/{$c->id}/dispenses", ['module_ids' => [(string) Str::uuid7()]])
        ->assertSessionHasErrors('module_ids');
    $this->actingAs(adUser(['admissions.candidatures.view' => null]))
        ->put("/admissions/candidatures/{$c->id}/dispenses", ['module_ids' => []])->assertForbidden();
});

it('saves the booklet team and the word of the director', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->put('/admin/livret-apprentissage', [
        'team' => [
            ['role' => 'Responsable de formation', 'name' => 'Franck Gardie-Rondeau', 'phone' => '02 28 44 32 14', 'email' => 'franck@example.test'],
            ['role' => 'Référent handicap', 'name' => 'Olivier Blaiteau', 'phone' => '', 'email' => ''],
        ],
        'director_note' => 'Pour l’équipe pédagogique, Fabrice Robert, directeur général.',
    ])->assertRedirect();

    expect(BookletSettings::all())->toMatchArray([
        'team' => [
            ['role' => 'Responsable de formation', 'name' => 'Franck Gardie-Rondeau', 'phone' => '02 28 44 32 14', 'email' => 'franck@example.test'],
            ['role' => 'Référent handicap', 'name' => 'Olivier Blaiteau', 'phone' => null, 'email' => null],
        ],
        'director_note' => 'Pour l’équipe pédagogique, Fabrice Robert, directeur général.',
    ]);

    $this->put('/admin/livret-apprentissage', ['team' => [['role' => '', 'name' => 'X']]])->assertSessionHasErrors('team.0.role');
    $this->actingAs(adUser(['admissions.candidatures.view' => null]))->get('/admin/livret-apprentissage')->assertForbidden();
});

it('keeps the annexes as checked PDFs of the organisme, in order, and deletes them', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/admin/livret-apprentissage/annexes', ['title' => 'Règlement intérieur', 'file' => bookletPdf()])->assertRedirect();
    $this->post('/admin/livret-apprentissage/annexes', ['title' => 'Statut de l’apprenti', 'file' => bookletPdf('statut.pdf')])->assertRedirect();

    $annexes = BookletSettings::all()['annexes'];
    expect(array_column($annexes, 'title'))->toBe(['Règlement intérieur', 'Statut de l’apprenti'])
        ->and(Storage::disk('local')->exists(BookletSettings::annexPath($annexes[0]['id'])))->toBeTrue();

    // A renamed text file is refused.
    $this->post('/admin/livret-apprentissage/annexes', ['title' => 'Faux', 'file' => UploadedFile::fake()->createWithContent('faux.pdf', 'pas un pdf')])
        ->assertSessionHasErrors('file');

    $this->delete("/admin/livret-apprentissage/annexes/{$annexes[0]['id']}")->assertRedirect();
    expect(array_column(BookletSettings::all()['annexes'], 'title'))->toBe(['Statut de l’apprenti'])
        ->and(Storage::disk('local')->exists(BookletSettings::annexPath($annexes[0]['id'])))->toBeFalse();
});
