<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Sessions\AbandonRule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => inTenant());

it('defaults to the prorata alone', function () {
    expect(AbandonRule::forTenant())->toBe(['mode' => 'prorata', 'dedit_percent' => null]);
});

it('reads the stored rule and falls back to the prorata on an incoherent value', function () {
    tenant()->forceFill(['settings' => ['sessions' => ['abandon_rule' => ['mode' => 'prorata_dedit', 'dedit_percent' => 30]]]])->save();
    expect(AbandonRule::forTenant())->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 30]);

    foreach ([['mode' => 'prorata_dedit', 'dedit_percent' => 0], ['mode' => 'prorata_dedit', 'dedit_percent' => 101], ['mode' => 'prorata_dedit'], ['mode' => 'inconnu'], 'x', null] as $bad) {
        expect(AbandonRule::normalise($bad))->toBe(['mode' => 'prorata', 'dedit_percent' => null]);
    }
});

it('shows the rule to settings managers only', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/abandon-rule')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/abandon-rule')->where('rule.mode', 'prorata')->where('rule.dedit_percent', null));

    $this->actingAs(direction(twoFactor: true))->get('/admin/abandon-rule')->assertForbidden();
    $this->actingAs(commercial())->put('/admin/abandon-rule', ['mode' => 'prorata'])->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/abandon-rule')->assertRedirect('/login');
});

it('saves the dédit rule, keeps the other settings and audits the change', function () {
    tenant()->forceFill(['settings' => ['branding' => ['color' => 'blue']]])->save();

    $this->actingAs(admin(twoFactor: true))->put('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 25])
        ->assertRedirect('/admin/abandon-rule');

    $settings = tenant()->fresh()->settings;
    expect($settings['sessions']['abandon_rule'])->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 25])
        ->and($settings['branding'])->toBe(['color' => 'blue'])
        ->and(AbandonRule::forTenant(tenant()->fresh()))->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 25]);

    $log = AuditLog::query()->where('event', 'settings.abandon_rule_changed')->sole();
    expect($log->old_values)->toBe(['mode' => 'prorata', 'dedit_percent' => null])->and($log->new_values)->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 25]);

    // Back to the prorata: the percentage is dropped.
    $this->put('/admin/abandon-rule', ['mode' => 'prorata', 'dedit_percent' => 40])->assertRedirect();
    expect(AbandonRule::forTenant(tenant()->fresh()))->toBe(['mode' => 'prorata', 'dedit_percent' => null]);

    // Unchanged: nothing more is audited.
    $this->put('/admin/abandon-rule', ['mode' => 'prorata'])->assertRedirect();
    expect(AuditLog::query()->where('event', 'settings.abandon_rule_changed')->count())->toBe(2);
});

it('requires a percentage from 1 to 100 in dédit mode, with French messages', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit'])->assertJsonValidationErrors(['dedit_percent' => 'Le pourcentage de dédit est obligatoire (de 1 à 100).']);
    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 0])->assertJsonValidationErrors(['dedit_percent' => 'Le pourcentage de dédit doit être compris entre 1 et 100.']);
    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 101])->assertJsonValidationErrors('dedit_percent');
    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 12.5])->assertJsonValidationErrors(['dedit_percent' => 'Le pourcentage de dédit doit être un nombre entier.']);
    $this->putJson('/admin/abandon-rule', ['mode' => 'autre'])->assertJsonValidationErrors(['mode' => 'Choisissez « Prorata seulement » ou « Prorata et dédit ».']);
    $this->putJson('/admin/abandon-rule', [])->assertJsonValidationErrors('mode');

    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 1])->assertRedirect();
    $this->putJson('/admin/abandon-rule', ['mode' => 'prorata_dedit', 'dedit_percent' => 100])->assertRedirect();
    expect(AbandonRule::forTenant(tenant()->fresh())['dedit_percent'])->toBe(100);
});
