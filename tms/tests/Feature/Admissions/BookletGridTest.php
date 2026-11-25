<?php

use App\Modules\Admissions\Booklet\BookletGrid;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Companies\Company;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

function bookletSkill(array $tree, string $title): array
{
    foreach ($tree['types'] as $at) {
        foreach ($at['competences'] as $cp) {
            foreach ($cp['skills'] as $skill) {
                if ($skill['title'] === $title) {
                    return $skill;
                }
            }
        }
    }

    return [];
}

it('marks a skill on the centre side and reads it back in the tree', function () {
    $c = bookletApprentice();
    $key = bookletSkill(app(BookletGrid::class)->tree($c), 'Peser les produits')['key'];

    $this->actingAs(bookletTrainerUser('Cauret'))->put("/admissions/livret/{$c->id}/grille", ['key' => $key, 'level' => 'ECA'])->assertRedirect();

    $skill = bookletSkill(app(BookletGrid::class)->tree($c), 'Peser les produits');
    expect($skill['centre'])->toBe('ECA')->and($skill['company'])->toBeNull();

    $this->actingAs(admin(twoFactor: true))->put("/admissions/livret/{$c->id}/grille", ['key' => $key, 'level' => ''])->assertRedirect();
    expect(bookletSkill(app(BookletGrid::class)->tree($c), 'Peser les produits')['centre'])->toBeNull();
});

it('refuses unknown levels and skills, and other trainers', function () {
    $c = bookletApprentice();
    $key = bookletSkill(app(BookletGrid::class)->tree($c), 'Peser les produits')['key'];
    $this->actingAs(admin(twoFactor: true))->put("/admissions/livret/{$c->id}/grille", ['key' => $key, 'level' => 'B'])->assertSessionHasErrors('level');
    $this->actingAs(admin(twoFactor: true))->put("/admissions/livret/{$c->id}/grille", ['key' => str_repeat('a', 64), 'level' => 'A'])->assertSessionHasErrors('key');
    $this->actingAs(bookletTrainerUser('Autre'))->put("/admissions/livret/{$c->id}/grille", ['key' => $key, 'level' => 'A'])->assertNotFound();
});

it('hides the marks of skills removed from the training and counts them', function () {
    $c = bookletApprentice();
    $grid = app(BookletGrid::class);
    $grid->set($c, bookletSkill($grid->tree($c), 'Peser les produits')['key'], BookletGrid::CENTRE, 'A', admin());
    Training::query()->whereKey($c->training_id)->update(['competences' => Competences::parse("AT Préparer et dresser des entrées\nCP Réaliser la mise en place des entrées\n- Tailler les légumes\n")]);

    $tree = $grid->tree($c);
    expect($tree['orphans'])->toBe(1)
        ->and(bookletSkill($tree, 'Peser les produits'))->toBe([])
        ->and(bookletSkill($tree, 'Tailler les légumes')['centre'])->toBeNull();
});

it('keys a skill whatever its case and spaces', function () {
    expect(BookletGrid::skillKey('AT  un', 'Cp', 'Peser'))->toBe(BookletGrid::skillKey('at un', 'CP ', ' peser'));
});
