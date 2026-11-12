<?php

namespace App\Modules\Core\Security;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security settings of the current organisme (permission settings.manage).
 */
class SecuritySettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('admin/security', [
            'settings' => SecuritySettings::for($this->tenants->current()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'force_2fa' => ['required', 'boolean'],
            'idle_timeout_minutes' => ['required', 'integer', 'between:'.SecuritySettings::MIN_IDLE_MINUTES.','.SecuritySettings::MAX_IDLE_MINUTES],
        ], [
            'idle_timeout_minutes.between' => 'La durée d\'inactivité doit être comprise entre :min et :max minutes.',
            'idle_timeout_minutes.integer' => 'La durée d\'inactivité doit être un nombre entier de minutes.',
            'idle_timeout_minutes.required' => 'La durée d\'inactivité est obligatoire.',
        ]);

        $new = ['force_2fa' => (bool) $validated['force_2fa'], 'idle_timeout_minutes' => (int) $validated['idle_timeout_minutes']];

        DB::transaction(function () use ($new): void {
            $tenant = $this->tenants->current();
            $old = SecuritySettings::for($tenant);

            if ($old === $new) {
                return;
            }

            $settings = $tenant->settings;
            $settings['security'] = $new;
            $tenant->forceFill(['settings' => $settings])->save();

            $this->audit->record('settings.security_changed', $tenant, $old, $new);
        });

        return to_route('admin.security.edit')->with('status', 'Paramètres de sécurité enregistrés.');
    }
}
