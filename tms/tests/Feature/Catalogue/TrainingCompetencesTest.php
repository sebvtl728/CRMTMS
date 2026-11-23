<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

const EPR_GRID = <<<'TXT'
AT Préparer et dresser des entrées et des desserts
CP Réaliser la mise en place des entrées et des desserts
- Lire des instructions, des fiches techniques
• Peser les produits

CP Assembler et dresser les entrées et les desserts
- Dresser les entrées
AT Réaliser la plonge
CP Assurer la conduite du poste laverie vaisselle
TXT;

it('parses a pasted grid into activity types, competences and skills, and writes it back', function () {
    $grid = Competences::parse(EPR_GRID);

    expect($grid)->toBe([
        ['title' => 'Préparer et dresser des entrées et des desserts', 'competences' => [
            ['title' => 'Réaliser la mise en place des entrées et des desserts', 'skills' => ['Lire des instructions, des fiches techniques', 'Peser les produits']],
            ['title' => 'Assembler et dresser les entrées et les desserts', 'skills' => ['Dresser les entrées']],
        ]],
        ['title' => 'Réaliser la plonge', 'competences' => [
            ['title' => 'Assurer la conduite du poste laverie vaisselle', 'skills' => []],
        ]],
    ])->and(Competences::parse(Competences::toText($grid)))->toBe($grid)
        ->and(Competences::count($grid))->toBe(3)
        ->and(Competences::parse("  \n "))->toBeNull();
});

it('refuses a competence before any activity type, a skill before any competence, or a line without prefix', function (string $text, string $message) {
    expect(fn () => Competences::parse($text))->toThrow(ValidationException::class, $message);
})->with([
    'competence first' => ["CP Réaliser\n", 'Ligne 1 : une compétence (CP) doit suivre une activité type (AT).'],
    'skill first' => ["AT Préparer\n- Peser\n", 'Ligne 2 : un savoir-faire (-) doit suivre une compétence (CP).'],
    'no prefix' => ["AT Préparer\nPeser les produits\n", 'Ligne 2 : commencez la ligne par AT, CP ou un tiret.'],
]);

it('stores the grid from the training form, shows it back as text and through the public service', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', [
        'title' => 'Employé polyvalent en restauration',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'competences_text' => EPR_GRID,
    ])->assertRedirect('/catalogue');

    $training = Training::query()->sole();
    expect(app(Trainings::class)->competences($training->id))->toBe(Competences::parse(EPR_GRID));

    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.competences_text', Competences::toText(Competences::parse(EPR_GRID))));
    $this->get("/catalogue/{$training->id}")->assertInertia(fn (Assert $p) => $p->where('training.competences_count', 3));

    // The journal says the grid changed, without its (long) content.
    expect(json_encode(AuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('Peser les produits')
        ->and(json_encode(AuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE))->toContain('competences');

    $this->put("/catalogue/{$training->id}/edit", ['title' => 'Employé polyvalent en restauration', 'das_id' => $training->das_id, 'competences_text' => "CP Sans activité\n"])
        ->assertSessionHasErrors(['competences_text' => 'Ligne 1 : une compétence (CP) doit suivre une activité type (AT).']);
    expect(app(Trainings::class)->competences($training->id))->toBe(Competences::parse(EPR_GRID));
});
