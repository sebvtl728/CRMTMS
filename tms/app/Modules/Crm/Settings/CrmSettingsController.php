<?php

namespace App\Modules\Crm\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Administration › CRM: follow-up delays and dashboard settings (settings.manage). */
class CrmSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('admin/crm-settings', [
            'settings' => self::current(),
            'stages' => ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->orderBy('position')
                ->get(['id', 'label', 'meta'])->filter(fn (ReferenceItem $s) => ($s->meta['outcome'] ?? null) === null)
                ->map(fn (ReferenceItem $s) => ['value' => $s->id, 'label' => $s->label])->values()->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reactivation_months' => ['present', 'array', 'max:6'],
            'reactivation_months.*' => ['integer', 'between:1,'.CrmFollowUpSettings::MAX_REACTIVATION],
            'lead_stage_id' => ['required', 'uuid', Rule::exists('reference_items', 'id')->where('list', ReferenceList::PipelineStage->value)->whereNull('deleted_at')],
            'retention_months' => ['required', 'integer', 'between:1,'.CrmFollowUpSettings::MAX_RETENTION],
        ], [
            'reactivation_months.*.integer' => 'Un délai de réactivation est un nombre de mois de 1 à 36.',
            'reactivation_months.*.between' => 'Un délai de réactivation est un nombre de mois de 1 à 36.',
            'reactivation_months.max' => 'Six délais de réactivation au plus.',
            'lead_stage_id.required' => 'Choisissez une étape du pipeline.',
            'lead_stage_id.uuid' => 'Choisissez une étape du pipeline.',
            'lead_stage_id.exists' => 'Choisissez une étape du pipeline.',
            'retention_months.required' => 'La fenêtre de rétention est un nombre de mois de 1 à 120.',
            'retention_months.integer' => 'La fenêtre de rétention est un nombre de mois de 1 à 120.',
            'retention_months.between' => 'La fenêtre de rétention est un nombre de mois de 1 à 120.',
        ]);
        $new = [
            'reactivation_months' => CrmFollowUpSettings::normaliseMonths($data['reactivation_months']),
            'lead_stage_id' => $data['lead_stage_id'],
            'retention_months' => (int) $data['retention_months'],
        ];

        DB::transaction(function () use ($new): void {
            // Row lock: two administrators saving at once do not overwrite each other's settings.
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($this->tenants->id());
            $old = self::current($tenant);
            if ($old === $new) {
                return;
            }
            $settings = $tenant->settings ?? [];
            $settings['crm'] = [...($settings['crm'] ?? []), ...$new];
            $tenant->forceFill(['settings' => $settings])->save();
            $this->audit->record('crm.settings.updated', $tenant, $old, $new);
        });

        return to_route('admin.crm-settings.edit')->with('status', 'Réglages du CRM enregistrés.');
    }

    /** @return array{reactivation_months: list<int>, lead_stage_id: ?string, retention_months: int} */
    private static function current(?Tenant $tenant = null): array
    {
        return [
            'reactivation_months' => CrmFollowUpSettings::reactivationMonths($tenant),
            'lead_stage_id' => CrmFollowUpSettings::leadStageId($tenant),
            'retention_months' => CrmFollowUpSettings::retentionMonths($tenant),
        ];
    }
}
