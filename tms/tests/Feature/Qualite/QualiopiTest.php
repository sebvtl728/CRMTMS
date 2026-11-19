<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Qualite\Qualiopi\QualiopiReferential;
use App\Modules\Qualite\Qualiopi\QualityIndicator;
use Inertia\Testing\AssertableInertia as Assert;

it('holds the 2026 referential: 7 criteria, 33 indicators, the CFA ones and the changes', function () {
    $all = QualiopiReferential::indicators();

    expect(array_keys($all))->toBe(range(1, 33))
        ->and(count(QualiopiReferential::CRITERIA))->toBe(7)
        ->and(array_keys(array_filter($all, fn ($i) => $i['scope'] === 'cfa')))->toBe([14, 15, 20, 29, 33])
        ->and(array_keys(array_filter($all, fn ($i) => $i['change'] === 'new')))->toBe([33])
        ->and(array_keys(array_filter($all, fn ($i) => $i['change'] !== null)))->toBe([1, 2, 3, 7, 9, 12, 14, 15, 19, 20, 27, 30, 31, 32, 33])
        ->and($all[12]['text'])->toContain('violences sexistes et sexuelles')
        ->and(array_values(array_unique(array_column($all, 'criterion'))))->toBe([1, 2, 3, 4, 5, 6, 7]);
});

it('shows the 33 indicators by criterion to who may see the quality file, never to the others', function () {
    $this->actingAs(commercial())->get('/qualite/qualiopi')->assertForbidden();

    $this->actingAs(admin(twoFactor: true))->get('/qualite/qualiopi')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('qualite/qualiopi/index')
        ->has('criteria', 7)
        ->has('criteria.0.indicators', 3)
        ->where('criteria.0.indicators.0.number', 1)
        ->where('criteria.0.indicators.0.status', 'todo')
        ->where('criteria.0.indicators.0.applicable', true)
        ->where('progress', ['ready' => 0, 'applicable' => 33])
        ->where('verify_notice', QualiopiReferential::VERIFY_NOTICE)
        ->where('can.manage', true));
});

it('records the status, the responsible and the review date of an indicator, journaled', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin);

    $this->put('/qualite/qualiopi/8', ['status' => 'ready', 'responsible_user_id' => $admin->id, 'review_on' => '2027-03-01', 'notes' => 'Test en ligne par DAS.'])
        ->assertRedirect()->assertSessionHas('status', 'Indicateur 8 enregistré.');

    $state = QualityIndicator::query()->sole();
    expect([$state->number, $state->status, $state->review_on->toDateString(), $state->responsible_user_id])->toBe([8, 'ready', '2027-03-01', $admin->id])
        ->and(AuditLog::query()->where('auditable_type', 'quality_indicator')->count())->toBeGreaterThan(0);
    $this->get('/qualite/qualiopi')->assertInertia(fn (Assert $p) => $p->where('progress', ['ready' => 1, 'applicable' => 33]));
});

it('declares an indicator not applicable only with its justification', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->put('/qualite/qualiopi/33', ['applicable' => false])
        ->assertSessionHasErrors(['not_applicable_reason' => 'Justifiez pourquoi l’indicateur ne s’applique pas.']);
    $this->put('/qualite/qualiopi/33', ['applicable' => false, 'not_applicable_reason' => 'CAP FORMATION n’est pas un CFA.'])->assertSessionHasNoErrors();

    $this->get('/qualite/qualiopi')->assertInertia(fn (Assert $p) => $p->where('progress', ['ready' => 0, 'applicable' => 32]));
    $this->put('/qualite/qualiopi/34', ['status' => 'ready'])->assertNotFound();
    $this->put('/qualite/qualiopi/8', ['status' => 'parfait'])->assertSessionHasErrors('status');
});

it('lets a reader see the file but not change it', function () {
    $reader = userWithPermissions(['qualite.view']);
    $this->actingAs($reader)->get('/qualite/qualiopi')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', false));
    $this->actingAs($reader)->put('/qualite/qualiopi/8', ['status' => 'ready'])->assertForbidden();
});
