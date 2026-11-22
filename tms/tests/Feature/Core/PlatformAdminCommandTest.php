<?php

use Illuminate\Support\Facades\Gate;

beforeEach(fn () => tenant());

it('names a platform operator from the server, who then opens Horizon', function () {
    $admin = admin();

    $this->artisan('tms:platform-admin', ['email' => $admin->email])->expectsOutputToContain('peut maintenant ouvrir Horizon')->assertSuccessful();
    expect(Gate::forUser($admin->fresh())->allows('viewHorizon'))->toBeTrue();

    $this->artisan('tms:platform-admin', ['email' => $admin->email, '--revoke' => true])->assertSuccessful();
    expect(Gate::forUser($admin->fresh())->allows('viewHorizon'))->toBeFalse();
});

it('refuses an unknown address', function () {
    $this->artisan('tms:platform-admin', ['email' => 'personne@example.test'])->expectsOutputToContain('Aucun utilisateur')->assertFailed();
});
