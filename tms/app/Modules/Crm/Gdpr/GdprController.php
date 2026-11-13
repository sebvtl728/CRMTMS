<?php

namespace App\Modules\Crm\Gdpr;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « RGPD » screen (crm.gdpr): people listed « À anonymiser » with the
 * planned date, « Conserver » with a reason, people kept, and the retention
 * period (changed by settings.manage).
 */
class GdprController extends Controller
{
    public const LIMIT = 500;

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        $visible = CrmVisibility::people(Person::withTrashed(), $user)->select('people.id');

        $pending = GdprPending::query()->with('person')->whereIn('person_id', $visible)
            ->orderBy('listed_at')->orderBy('person_id')->limit(self::LIMIT)->get()
            ->filter(fn (GdprPending $p) => $p->person !== null)
            ->map(fn (GdprPending $p) => [
                'id' => $p->person_id,
                'name' => $p->person?->fullName(),
                'listed_at' => $p->listed_at->toDateString(),
                'planned_at' => $p->plannedAt()->toDateString(),
                'last_contact_at' => $p->person?->last_contact_at?->toDateString(),
                'created_at' => $p->person?->created_at?->toDateString(),
                'deleted' => (bool) $p->person?->trashed(),
            ])->values()->all();

        $kept = CrmVisibility::people(Person::withTrashed(), $user)->whereNull('anonymised_at')->where('gdpr_kept_until', '>', now())
            ->orderBy('gdpr_kept_until')->limit(self::LIMIT)->get()
            ->map(fn (Person $p) => [
                'id' => $p->id, 'name' => $p->fullName(), 'until' => $p->gdpr_kept_until?->toDateString(), 'reason' => $p->gdpr_kept_reason,
            ])->all();

        return Inertia::render('crm/gdpr/index', [
            'pending' => $pending,
            'pendingTotal' => GdprPending::query()->whereIn('person_id', $visible)->count(),
            'kept' => $kept,
            'retention' => [
                'years' => RetentionPolicy::for($this->tenants->current()),
                'min' => RetentionPolicy::MIN_YEARS,
                'max' => RetentionPolicy::MAX_YEARS,
                'delay_days' => AnonymiseExpired::DELAY_DAYS,
                'can_update' => $user->can('settings.manage'),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    /** « Conserver »: out of the list for one retention period, with a reason. */
    public function keep(Request $request, Person $person): RedirectResponse
    {
        Gate::authorize('gdpr', $person);
        abort_if($person->anonymised_at !== null || $person->merged_into_id !== null, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => 'Indiquez le motif de conservation.',
            'reason.min' => 'Le motif est trop court.',
            'reason.max' => 'Le motif est trop long (500 caractères au plus).',
        ]);
        $reason = trim($validated['reason']);
        $until = now()->addYears(RetentionPolicy::for($this->tenants->current()));

        DB::transaction(function () use ($person, $reason, $until): void {
            $person->forceFill(['gdpr_kept_until' => $until, 'gdpr_kept_reason' => $reason])->save();
            GdprPending::query()->whereKey($person->id)->delete();
            // The reason is free text: kept on the fiche (scrubbed by the
            // anonymisation), masked in the immutable journal.
            $this->audit->record('gdpr.kept', $person, [], ['until' => $until->toDateString(), 'reason' => Person::AUDIT_PERSONAL_MASK]);
        });

        return back()->with('status', 'Fiche conservée jusqu\'au '.$until->format('d/m/Y').'.');
    }

    public function retention(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'retention_years' => ['required', 'integer', 'between:'.RetentionPolicy::MIN_YEARS.','.RetentionPolicy::MAX_YEARS],
        ], [
            'retention_years.required' => 'La durée de conservation est obligatoire.',
            'retention_years.integer' => 'La durée de conservation doit être un nombre entier d\'années.',
            'retention_years.between' => 'La durée de conservation doit être comprise entre :min et :max ans.',
        ]);
        $new = (int) $validated['retention_years'];

        DB::transaction(function () use ($new): void {
            $tenant = $this->tenants->current();
            $old = RetentionPolicy::for($tenant);
            if ($old === $new) {
                return;
            }

            $settings = $tenant->settings;
            $settings['gdpr'] = ['retention_years' => $new];
            $tenant->forceFill(['settings' => $settings])->save();

            $this->audit->record('settings.gdpr_changed', $tenant, ['retention_years' => $old], ['retention_years' => $new]);
        });

        return back()->with('status', 'Durée de conservation enregistrée.');
    }
}
