<?php

namespace App\Modules\Sessions\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\AbandonRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration of the abandon rule of the organisme (settings.manage).
 */
class AbandonRuleController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('admin/abandon-rule', [
            'rule' => AbandonRule::forTenant($this->tenants->current()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(AbandonRule::MODES)],
            // Only checked in dédit mode: in prorata mode the percentage is ignored.
            'dedit_percent' => $request->input('mode') === AbandonRule::PRORATA_DEDIT
                ? ['required', 'integer', 'between:'.AbandonRule::MIN_PERCENT.','.AbandonRule::MAX_PERCENT]
                : ['nullable'],
        ], [
            'mode.required' => 'Choisissez la règle applicable en cas d\'abandon.',
            'mode.in' => 'Choisissez « Prorata seulement » ou « Prorata et dédit ».',
            'dedit_percent.required' => 'Le pourcentage de dédit est obligatoire (de 1 à 100).',
            'dedit_percent.integer' => 'Le pourcentage de dédit doit être un nombre entier.',
            'dedit_percent.between' => 'Le pourcentage de dédit doit être compris entre :min et :max.',
        ]);

        $new = $validated['mode'] === AbandonRule::PRORATA_DEDIT
            ? ['mode' => AbandonRule::PRORATA_DEDIT, 'dedit_percent' => (int) $validated['dedit_percent']]
            : ['mode' => AbandonRule::PRORATA, 'dedit_percent' => null];

        DB::transaction(function () use ($new): void {
            // Row lock: two administrators saving at once do not overwrite each other's settings.
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($this->tenants->id());
            $old = AbandonRule::forTenant($tenant);

            if ($old === $new) {
                return;
            }

            $settings = $tenant->settings;
            $settings['sessions']['abandon_rule'] = $new;
            $tenant->forceFill(['settings' => $settings])->save();

            $this->audit->record('settings.abandon_rule_changed', $tenant, $old, $new);
        });

        return to_route('admin.abandon-rule.edit')->with('status', 'Règle d\'abandon enregistrée.');
    }
}
