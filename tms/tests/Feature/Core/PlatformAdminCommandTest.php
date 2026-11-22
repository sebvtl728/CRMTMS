<?php

use App\Modules\Core\Access\Role;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => tenant());

it('names a platform operator from the server, who then opens Horizon', function () {
    $admin = admin(twoFactor: true);

    $this->artisan('tms:platform-admin', ['email' => $admin->email])->expectsOutputToContain('peut maintenant ouvrir Horizon')->assertSuccessful();
    expect(Gate::forUser($admin->fresh())->allows('viewHorizon'))->toBeTrue();

    $this->artisan('tms:platform-admin', ['email' => $admin->email, '--revoke' => true])->assertSuccessful();
    expect(Gate::forUser($admin->fresh())->allows('viewHorizon'))->toBeFalse();
});

it('refuses an unknown address', function () {
    $this->artisan('tms:platform-admin', ['email' => 'personne@example.test'])->expectsOutputToContain('Aucun utilisateur')->assertFailed();
});

it('refuses an operator without two-factor authentication, and the gate checks it too', function () {
    $user = userWithRole(Role::Commercial);

    $this->artisan('tms:platform-admin', ['email' => $user->email])->expectsOutputToContain('double authentification')->assertFailed();
    $user->forceFill(['is_platform_admin' => true])->save();
    expect(Gate::forUser($user->fresh())->allows('viewHorizon'))->toBeFalse();
});
