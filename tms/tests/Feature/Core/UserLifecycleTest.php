<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\InvitationMail;
use App\Modules\Core\Users\User;
use App\Modules\Core\Users\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => '', 'last_activity' => time(),
    ]);
}

beforeEach(function () {
    config(['session.driver' => 'database']);
    $this->admin = admin(twoFactor: true);
});

it('admin deactivates a user and their sessions end', function () {
    $user = commercial();
    fakeSession($user, 'sess-1');
    fakeSession($user, 'sess-2');

    $this->actingAs($this->admin)->postJson("/admin/users/{$user->id}/deactivate")->assertRedirect();

    expect($user->fresh()->deactivated_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'users.deactivated')->where('auditable_id', $user->id)->exists())->toBeTrue();
});

it('reactivates a user without restoring sessions', function () {
    $user = commercial();
    $user->deactivate();

    $this->actingAs($this->admin)->postJson("/admin/users/{$user->id}/reactivate")->assertRedirect();

    expect($user->fresh()->deactivated_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'users.reactivated')->where('auditable_id', $user->id)->exists())->toBeTrue();
});

it('cannot deactivate or demote the last active admin', function () {
    $message = 'Il doit rester au moins un administrateur actif.';
    // A non-admin holding users.manage acts on the only admin.
    $actor = direction(twoFactor: true);
    $actor->givePermissionTo('users.manage');

    $this->actingAs($actor)->postJson("/admin/users/{$this->admin->id}/deactivate")
        ->assertStatus(422)->assertJsonValidationErrors(['user' => $message]);
    $this->actingAs($actor)->patchJson("/admin/users/{$this->admin->id}/role", ['role' => 'direction'])
        ->assertStatus(422)->assertJsonValidationErrors(['user' => $message]);

    expect($this->admin->fresh()->isActive())->toBeTrue()->and($this->admin->fresh()->role())->toBe(Role::AdminOrganisme);

    // With a second active admin both operations pass.
    $second = admin();
    $this->actingAs($actor)->patchJson("/admin/users/{$second->id}/role", ['role' => 'direction'])->assertRedirect();
});

it('an admin cannot demote themselves when they are the last one', function () {
    $this->actingAs($this->admin)->patchJson("/admin/users/{$this->admin->id}/role", ['role' => 'direction'])
        ->assertStatus(422)->assertJsonValidationErrors(['user' => 'Il doit rester au moins un administrateur actif.']);
});

it('cannot deactivate oneself', function () {
    admin(); // a second admin exists: only the self rule applies
    $this->actingAs($this->admin)->postJson("/admin/users/{$this->admin->id}/deactivate")
        ->assertStatus(422)->assertJsonValidationErrors(['user' => 'Vous ne pouvez pas désactiver votre propre compte.']);
    expect($this->admin->fresh()->isActive())->toBeTrue();
});

it('changes a user role and records old and new', function () {
    $user = commercial();
    fakeSession($user, 'sess-x');

    $this->actingAs($this->admin)->patchJson("/admin/users/{$user->id}/role", ['role' => 'direction'])->assertRedirect();

    $log = AuditLog::query()->where('event', 'access.role_changed')->where('auditable_id', $user->id)->orderByDesc('id')->firstOrFail();
    expect($user->fresh()->role())->toBe(Role::Direction)
        ->and($log->old_values)->toMatchArray(['role' => 'commercial'])
        ->and($log->new_values)->toMatchArray(['role' => 'direction'])
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps the acting admin session when they change their own role', function () {
    $second = admin();
    $current = str_repeat('k', 40);
    fakeSession($this->admin, $current);
    fakeSession($this->admin, 'drop-me');

    // Called directly: the test client cannot carry a real session cookie.
    $store = app('session.store');
    $store->setId($current);
    $request = Request::create('/admin/users/'.$this->admin->id.'/role', 'PATCH', ['role' => 'direction']);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $this->admin);
    app(UserController::class)->updateRole($request, $this->admin);

    expect($this->admin->fresh()->role())->toBe(Role::Direction)
        ->and(DB::table('sessions')->where('id', 'drop-me')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue()
        ->and($second->fresh()->role())->toBe(Role::AdminOrganisme);
});

it('rejects an unknown role', function () {
    $user = commercial();
    $this->actingAs($this->admin)->patchJson("/admin/users/{$user->id}/role", ['role' => 'dieu'])->assertStatus(422)->assertJsonValidationErrors('role');
});

it('resends an invitation with a new token and expires the old one', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau', Role::Commercial, $this->admin);
    $old = $invitation->plainToken;
    $invitation->forceFill(['expires_at' => now()->addHour()])->save();

    $this->actingAs($this->admin)->postJson("/admin/invitations/{$invitation->id}/resend")->assertRedirect();

    $fresh = Invitation::query()->pending()->where('email', 'nouveau@cap.test')->sole();
    expect(Invitation::findPendingByToken($old))->toBeNull()
        ->and($fresh->expires_at->isAfter(now()->addHours(23)))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'users.invitation_resent')->exists())->toBeTrue();
    Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('nouveau@cap.test'));
    $this->get("/activation/{$old}")->assertNotFound();
});

it('revokes a pending invitation', function () {
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau', Role::Commercial, $this->admin);
    $token = $invitation->plainToken;

    $this->actingAs($this->admin)->deleteJson("/admin/invitations/{$invitation->id}")->assertRedirect();

    $this->get("/activation/{$token}")->assertNotFound();
    expect(Invitation::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'users.invitation_revoked')->exists())->toBeTrue();
});

it('assistant cannot manage users', function () {
    $user = commercial();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau', Role::Commercial);
    $this->actingAs(assistant(twoFactor: true));

    $this->patchJson("/admin/users/{$user->id}/role", ['role' => 'direction'])->assertForbidden();
    $this->postJson("/admin/users/{$user->id}/deactivate")->assertForbidden();
    $this->postJson("/admin/users/{$user->id}/reactivate")->assertForbidden();
    $this->postJson("/admin/invitations/{$invitation->id}/resend")->assertForbidden();
    $this->deleteJson("/admin/invitations/{$invitation->id}")->assertForbidden();
});

it('lists deactivated users as such', function () {
    $user = commercial();
    $user->deactivate();
    $this->actingAs($this->admin)->get('/admin/users')->assertInertia(fn ($page) => $page
        ->where('users', fn ($users) => collect($users)->firstWhere('id', $user->id)['active'] === false));
});

it('never lets another organisme be managed', function () {
    $other = tenantOnHost('autre.test');
    $foreign = userIn($other, ['email' => 'x@autre.test']);
    $this->actingAs($this->admin)->postJson("/admin/users/{$foreign->id}/deactivate")->assertNotFound();
});
