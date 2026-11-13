<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function stageOpportunity(): Opportunity
{
    return makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']), ['amount' => 1000]);
}

it('moving to lost without a reason is refused', function () {
    $opportunity = stageOpportunity();

    $this->actingAs(commercial())->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Perdu')->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['loss_reason_id' => 'Le motif de perte est obligatoire.']);

    // Called directly (kanban, future callers): same rule.
    expect(fn () => app(OpportunityStage::class)->move($opportunity, pipelineStage('Perdu'), null, null, null))
        ->toThrow(ValidationException::class, 'Le motif de perte est obligatoire.');

    $opportunity->refresh();
    expect($opportunity->stage_id)->toBe(pipelineStage('Nouveau')->id)
        ->and($opportunity->lost_on)->toBeNull()
        ->and(StageChange::query()->count())->toBe(1);
});

it('moves to lost with a reason', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 23:30', 'Europe/Paris'));
    $opportunity = stageOpportunity();

    $this->actingAs(commercial())->post("/crm/opportunities/{$opportunity->id}/stage", [
        'stage_id' => pipelineStage('Perdu')->id,
        'loss_reason_id' => crmItem(ReferenceList::LossReason, 'Prix')->id,
        'loss_comment' => 'Trop cher pour eux',
    ])->assertSessionHasNoErrors();

    $opportunity->refresh();
    expect($opportunity->stage_id)->toBe(pipelineStage('Perdu')->id)
        ->and($opportunity->loss_reason_id)->toBe(crmItem(ReferenceList::LossReason, 'Prix')->id)
        ->and($opportunity->loss_comment)->toBe('Trop cher pour eux')
        ->and($opportunity->lost_on?->toDateString())->toBe('2026-11-13')
        ->and($opportunity->probability)->toBe(0)
        ->and($opportunity->won_on)->toBeNull();

    // A reason from another list is refused.
    $other = stageOpportunity();
    $this->postJson("/crm/opportunities/{$other->id}/stage", ['stage_id' => pipelineStage('Perdu')->id, 'loss_reason_id' => crmItem(ReferenceList::Priority, 'Bas')->id])
        ->assertJsonValidationErrors('loss_reason_id');
});

it('moves to won with today as default date and computes the cycle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Europe/Paris'));
    $opportunity = stageOpportunity();
    expect($opportunity->cycleDays())->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-13 23:30', 'Europe/Paris'));
    $this->actingAs(commercial())->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Gagné')->id])
        ->assertSessionHasNoErrors();

    $opportunity->refresh();
    expect($opportunity->won_on?->toDateString())->toBe('2026-11-13')
        ->and($opportunity->probability)->toBe(100)
        ->and($opportunity->cycleDays())->toBe(12);

    // An explicit date (in the past) is kept.
    $other = stageOpportunity();
    $this->post("/crm/opportunities/{$other->id}/stage", ['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2026-11-10'])->assertSessionHasNoErrors();
    expect($other->refresh()->won_on?->toDateString())->toBe('2026-11-10');
    $this->postJson("/crm/opportunities/{$other->id}/stage", ['stage_id' => pipelineStage('Gagné')->id, 'won_on' => '2027-01-01'])
        ->assertJsonValidationErrors('won_on');
});

it('clears the closing fields when leaving won or lost', function () {
    $opportunity = stageOpportunity();
    $stages = app(OpportunityStage::class);

    $stages->move($opportunity, pipelineStage('Gagné'), null, null, null);
    $stages->move($opportunity, pipelineStage('Négociation'), null, null, null);

    $opportunity->refresh();
    expect($opportunity->won_on)->toBeNull()
        ->and($opportunity->probability)->toBe(80);

    $stages->move($opportunity, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Concurrent')->id, 'Parti ailleurs', null);
    $stages->move($opportunity, pipelineStage('Étude'), null, null, null);

    $opportunity->refresh();
    expect($opportunity->lost_on)->toBeNull()
        ->and($opportunity->loss_reason_id)->toBeNull()
        ->and($opportunity->loss_comment)->toBeNull()
        ->and($opportunity->probability)->toBe(40);
});

it('keeps a manual probability at the next move, then follows the stages again', function () {
    $opportunity = stageOpportunity();
    $this->actingAs(commercial())->put("/crm/opportunities/{$opportunity->id}", [
        'title' => $opportunity->title, 'das_id' => $opportunity->das_id, 'probability' => 35,
    ])->assertSessionHasNoErrors();

    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Contacté')->id]);
    expect($opportunity->refresh()->probability)->toBe(35)
        ->and($opportunity->probability_overridden)->toBeFalse();

    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Étude')->id]);
    expect($opportunity->refresh()->probability)->toBe(40);
});

it('writes the complete stage history without touching the last contact', function () {
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $this->actingAs($user);
    $opportunity = makeOpportunity($organisation);

    foreach (['Contacté', 'Proposition', 'Gagné', 'Négociation'] as $label) {
        $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage($label)->id])->assertSessionHasNoErrors();
    }
    // Same stage again: nothing to record.
    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Négociation')->id]);

    $label = fn (?string $id) => $id === null ? null : collect([
        'Nouveau', 'Contacté', 'Proposition', 'Gagné', 'Négociation',
    ])->first(fn (string $l) => pipelineStage($l)->id === $id);

    $history = StageChange::query()->where('opportunity_id', $opportunity->id)->orderBy('changed_at')->orderBy('id')->get();
    expect($history->map(fn (StageChange $c) => [$label($c->from_stage_id), $label($c->to_stage_id)])->all())->toBe([
        [null, 'Nouveau'], ['Nouveau', 'Contacté'], ['Contacté', 'Proposition'], ['Proposition', 'Gagné'], ['Gagné', 'Négociation'],
    ])
        ->and($history->pluck('changed_by')->unique()->all())->toBe([$user->id])
        ->and($organisation->refresh()->last_contact_at)->toBeNull();

    $this->get("/crm/opportunities/{$opportunity->id}")
        ->assertInertia(fn ($page) => $page->has('history', 5)->where('history.0.to', 'Négociation'));
});

it('refuses an inactive or foreign stage', function () {
    $opportunity = stageOpportunity();
    $etude = pipelineStage('Étude');
    $etude->update(['is_active' => false]);

    $this->actingAs(commercial())->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => $etude->id])->assertJsonValidationErrors('stage_id');
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => crmItem(ReferenceList::Priority, 'Bas')->id])->assertJsonValidationErrors('stage_id');
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => 'pas-un-id'])->assertJsonValidationErrors('stage_id');
});

it('lets only crm.update move a stage', function () {
    $opportunity = stageOpportunity();

    // A Responsable DAS sees the opportunity (crm.view) but may not move it.
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));

    $this->actingAs($responsable)->get("/crm/opportunities/{$opportunity->id}")->assertOk();
    $this->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Contacté')->id])->assertForbidden();
});
