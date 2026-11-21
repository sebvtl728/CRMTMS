<?php

use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => inTenant());

function securityPayload(array $overrides = []): array
{
    return array_merge(['force_2fa' => true, 'idle_timeout_minutes' => 60], $overrides);
}

it('shows the security settings with defaults to settings managers only', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/security')
            ->where('settings.force_2fa', false)
            ->where('settings.idle_timeout_minutes', 120));

    $this->actingAs(direction(twoFactor: true))->get('/admin/security')->assertForbidden();
    $this->actingAs(commercial())->get('/admin/security')->assertForbidden();
});

it('requires authentication for the security settings', function () {
    $this->get('/admin/security')->assertRedirect('/login');
    $this->put('/admin/security', securityPayload())->assertRedirect('/login');
});

it('saves the security settings and audits old and new values', function () {
    $this->actingAs(admin(twoFactor: true))
        ->put('/admin/security', securityPayload())
        ->assertRedirect('/admin/security');

    $settings = tenant()->fresh()->settings;
    expect($settings['security'])->toEqual(['force_2fa' => true, 'idle_timeout_minutes' => 60]);

    $log = AuditLog::query()->where('event', 'settings.security_changed')->sole();
    expect($log->old_values)->toEqual(['force_2fa' => false, 'idle_timeout_minutes' => 120])
        ->and($log->new_values)->toEqual(['force_2fa' => true, 'idle_timeout_minutes' => 60]);
});

it('keeps the other tenant settings and does not audit an unchanged save', function () {
    tenant()->forceFill(['settings' => ['branding' => ['color' => 'blue']]])->save();

    $this->actingAs($admin = admin(twoFactor: true))
        ->put('/admin/security', securityPayload(['force_2fa' => false, 'idle_timeout_minutes' => 120]))
        ->assertRedirect('/admin/security');

    expect(tenant()->fresh()->settings['branding'])->toBe(['color' => 'blue']);
    expect(AuditLog::query()->where('event', 'settings.security_changed')->count())->toBe(0);
});

it('forbids changing the settings without settings.manage', function () {
    $this->actingAs(direction(twoFactor: true))->put('/admin/security', securityPayload())->assertForbidden();

    expect(tenant()->fresh()->settings)->toBe([]);
});

it('rejects idle timeout outside 15..480', function (mixed $value) {
    $this->actingAs(admin(twoFactor: true))
        ->put('/admin/security', securityPayload(['idle_timeout_minutes' => $value]))
        ->assertSessionHasErrors('idle_timeout_minutes');

    expect(Tenant::query()->find(tenant()->id)->settings)->toBe([]);
})->with([5, 14, 481, 10000, 'abc', '']);

it('accepts the idle timeout bounds', function (int $value) {
    $this->actingAs(admin(twoFactor: true))
        ->put('/admin/security', securityPayload(['idle_timeout_minutes' => $value]))
        ->assertSessionHasNoErrors();

    expect(tenant()->fresh()->settings['security']['idle_timeout_minutes'])->toBe($value);
})->with([15, 480]);

it('applies idle timeout from tenant settings', function () {
    tenant()->forceFill(['settings' => ['security' => ['force_2fa' => false, 'idle_timeout_minutes' => 15]]])->save();
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();

    $this->travel(16)->minutes();

    $this->get('/dashboard')
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'Votre session a expiré après une période d\'inactivité. Veuillez vous reconnecter.');
    $this->assertGuest();
});

it('keeps the session alive while the user stays active', function () {
    tenant()->forceFill(['settings' => ['security' => ['force_2fa' => false, 'idle_timeout_minutes' => 15]]])->save();
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();
    $this->travel(10)->minutes();
    $this->get('/dashboard')->assertOk();
    $this->travel(10)->minutes();
    $this->get('/dashboard')->assertOk();
});

it('defaults the idle timeout to 120 minutes', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();
    $this->travel(119)->minutes();
    $this->get('/dashboard')->assertOk();
    $this->travel(121)->minutes();
    $this->get('/dashboard')->assertRedirect('/login');
});

it('does not apply the idle timeout to /health', function () {
    tenant()->forceFill(['settings' => ['security' => ['idle_timeout_minutes' => 15]]])->save();
    $this->actingAs(User::factory()->create());
    $this->get('/dashboard')->assertOk();

    $this->travel(60)->minutes();

    expect($this->get('/health')->status())->not->toBe(302);
});

it('shares an explicit whitelist of user fields and the permission names', function () {
    $user = admin(twoFactor: true);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'role' => 'Administrateur de l\'organisme',
            'two_factor_enabled' => true,
        ])
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === collect(Permissions::all())->sort()->values()->all()));
});

it('shares only the permissions the user holds', function () {
    $this->actingAs(direction(twoFactor: true))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['admissions.candidatures.view', 'admissions.sensitive.view', 'audit.view', 'catalogue.delete', 'catalogue.manage', 'catalogue.view', 'crm.activities.team', 'crm.activities.view', 'crm.dashboards.view', 'crm.export', 'crm.fiches.view', 'crm.gdpr', 'crm.opportunities.view', 'crm.quotes.view', 'crm.revenue.manage', 'crm.revenue.view', 'documents.manage', 'documents.view', 'exports.run', 'finance.invoices.manage', 'finance.invoices.validate', 'finance.invoices.view', 'finance.payments.manage', 'qualite.manage', 'qualite.view', 'questionnaires.manage', 'questionnaires.view', 'sessions.attendance.advance_entry', 'sessions.attendance.close', 'sessions.attendance.manage', 'sessions.dossiers.view', 'sessions.rooms.manage', 'sessions.seances.manage', 'sessions.seances.view', 'sessions.sessions.view', 'sessions.trainers.manage']));
});

it('lets a 300-minute setting outlive the 120-minute session default', function () {
    // The framework session lifetime is an inactivity limit too: its ceiling must not undercut the setting.
    expect(file_get_contents(config_path('session.php')))->toContain("env('SESSION_LIFETIME', 480)")
        ->and(file_get_contents(base_path('.env.example')))->toContain('SESSION_LIFETIME=480');

    tenant()->forceFill(['settings' => ['security' => ['force_2fa' => false, 'idle_timeout_minutes' => 300]]])->save();
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();
    $this->travel(200)->minutes();
    $this->get('/dashboard')->assertOk();
    $this->travel(301)->minutes();
    $this->get('/dashboard')->assertRedirect('/login');
});

it('ignores remember on login and issues no recaller cookie', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery-1']);

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery-1', 'remember' => '1']);

    $response->assertRedirect();
    expect(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('does not authenticate from a forged recaller cookie', function () {
    $user = User::factory()->create();
    $name = Auth::guard('web')->getRecallerName();

    $this->withUnencryptedCookie($name, $user->id.'|forged|'.$user->password)
        ->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('logs out a user restored from a remember cookie', function () {
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => 'legacy-token'])->save();
    $name = Auth::guard('web')->getRecallerName();

    $this->withUnencryptedCookie($name, $user->id.'|legacy-token|'.$user->password)
        ->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('answers an expired Inertia visit with a location redirect and other writes with 303', function () {
    tenant()->forceFill(['settings' => ['security' => ['idle_timeout_minutes' => 15]]])->save();
    $this->actingAs(User::factory()->create());
    $this->get('/dashboard')->assertOk();
    $this->travel(16)->minutes();

    $this->get('/dashboard', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location');

    $this->actingAs(User::factory()->create());
    $this->get('/dashboard');
    $this->travel(16)->minutes();
    $this->put('/admin/security', securityPayload())->assertStatus(303)->assertRedirect('/login');
});
