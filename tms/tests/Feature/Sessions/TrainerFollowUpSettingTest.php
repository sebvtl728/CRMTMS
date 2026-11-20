<?php

use App\Modules\Sessions\Settings\TrainerFollowUp;

beforeEach(fn () => inTenant());

it('defaults the trainer follow-up to 3 months, 0 disables it', function () {
    expect(TrainerFollowUp::months())->toBe(3);

    $this->actingAs(admin(twoFactor: true))->put('/admin/abandon-rule/trainer-follow-up', ['months' => 0])
        ->assertRedirect('/admin/abandon-rule')->assertSessionHasNoErrors();
    expect(TrainerFollowUp::months())->toBe(0);

    $this->put('/admin/abandon-rule/trainer-follow-up', ['months' => 4])->assertSessionHasNoErrors();
    expect(TrainerFollowUp::months())->toBe(4)
        ->and(tenant()->fresh()->settings['sessions']['trainer_follow_up_months'])->toBe(4);

    $this->put('/admin/abandon-rule/trainer-follow-up', ['months' => 37])
        ->assertSessionHasErrors(['months' => 'Le délai du suivi formateur est un nombre de mois de 0 à 36.']);
    $this->actingAs(commercial())->put('/admin/abandon-rule/trainer-follow-up', ['months' => 2])->assertForbidden();
});
