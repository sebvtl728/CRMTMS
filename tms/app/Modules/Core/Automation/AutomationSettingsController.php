<?php

namespace App\Modules\Core\Automation;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Administration › Automatismes (settings.manage): delays of the automatic sends, and « Exécuter maintenant ». */
class AutomationSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('admin/automation', [
            'settings' => AutomationSettings::all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $values = $request->only(array_keys(AutomationSettings::DEFAULTS));
        DB::transaction(function () use ($values): void {
            // Row lock: two administrators saving at once do not overwrite each other's settings.
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($this->tenants->id());
            $old = AutomationSettings::all();
            AutomationSettings::save($values);
            $new = AutomationSettings::all();
            if ($old !== $new) {
                $this->audit->record('automation.settings.updated', $tenant, $old, $new);
            }
        });

        return to_route('admin.automation.edit')->with('status', 'Réglages des automatismes enregistrés.');
    }

    public function run(): RedirectResponse
    {
        RunAutomationsNow::dispatch();

        return to_route('admin.automation.edit')->with('status', 'Les automatismes sont lancés : les e-mails partent dans quelques instants.');
    }
}
