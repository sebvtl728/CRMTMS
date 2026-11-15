<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => inTenant());

it('redirects the home page to the login for guests and to the dashboard when signed in', function () {
    $this->get('/')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/dashboard');
});

it('offers no self-service account deletion', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/profile', ['password' => 'password']);

    expect($response->getStatusCode())->toBeIn([404, 405]);
    expect($user->fresh())->not->toBeNull();
});

it('shares no starter-kit quote with the pages', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->missing('quote'));
});

it('translates the password reset link message', function () {
    expect(__('A reset link will be sent if the account exists.'))
        ->not->toBe('A reset link will be sent if the account exists.');
});

it('shows an expired activation link in French', function () {
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau', RoleModel::forSystem(Role::Commercial));
    $this->travel(25)->hours();
    config(['app.debug' => false]);

    $this->get("/activation/{$invitation->plainToken}")->assertStatus(410)
        ->assertSee('Ce lien d\'activation a expiré')
        ->assertDontSee('Disparu');
});

it('renders the error pages in French', function (int $status, string $title) {
    config(['app.debug' => false]);
    Route::get("/_test-error/{$status}", fn () => abort($status));

    $this->get("/_test-error/{$status}")->assertStatus($status)
        ->assertSee($title, false)
        ->assertSee('lang="fr"', false);
})->with([
    [403, 'Accès refusé'],
    [404, 'Page introuvable'],
    [410, 'Lien expiré'],
    [419, 'Session expirée'],
    [429, 'Trop de tentatives'],
    [500, 'Erreur du serveur'],
    [503, 'Service indisponible'],
]);

it('never shows framework or server details on an error page', function () {
    config(['app.debug' => false]);
    Route::get('/_test-error/boom', fn () => throw new RuntimeException('secret-internal-detail'));

    $this->get('/_test-error/boom')->assertStatus(500)->assertDontSee('secret-internal-detail');
    $this->get('/route-qui-n-existe-pas')->assertNotFound()->assertDontSee('could not be found');
});
