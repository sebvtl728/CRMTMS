<?php

namespace App\Modules\Crm\Activities;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Support\FormOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Compte rendu de RDV », one form made for the phone: a meeting of the
 * day (or a fiche), result, report, stage of the opportunity, next
 * follow-up; saved in one transaction (QuickReport).
 */
class QuickReportController extends Controller
{
    public function __construct(private readonly QuickReport $report) {}

    public function create(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $meetings = QuickReport::meetingsOfToday($user, CarbonImmutable::now())->map(function (Activity $a) use ($user): array {
            $row = ActivityRows::row($a);
            unset($row['report']);
            $at = CarbonImmutable::instance($a->due_at ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'));

            return [
                ...$row,
                'time' => $at->format('H:i'),
                'opportunities' => QuickReport::opportunitiesFor($user, $a->opportunity_id, $a->organisation_id, $a->person_id),
            ];
        })->values()->all();

        $selected = FilterInput::id($request->query('activity'));

        return Inertia::render('crm/report', [
            'meetings' => $meetings,
            'selected' => in_array($selected, array_column($meetings, 'id'), true) ? $selected : null,
            'fiche' => $this->fiche($request, $user),
            'types' => ReferenceItem::options(ReferenceList::ActionType)
                ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label, 'code' => $i->code])->values()->all(),
            'results' => FormOptions::reference(ReferenceList::ActionResult),
            'stages' => OpportunityStage::options(),
            'loss_reasons' => FormOptions::reference(ReferenceList::LossReason),
            'can_create' => $user->can('create', Activity::class),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(QuickReportRequest $request): RedirectResponse
    {
        $opportunity = $request->opportunity();
        $stage = $request->stage();
        $move = $opportunity !== null && $stage !== null ? [
            'opportunity' => $opportunity,
            'stage' => $stage,
            'loss_reason_id' => $request->validated('loss_reason_id'),
            'loss_comment' => $request->validated('loss_comment'),
        ] : null;

        [, $next] = $this->report->record($request->meeting(), $request->values(), $request->next(), $move);

        return to_route('crm.report')->with('status', $next === null ? 'Compte rendu enregistré.' : 'Compte rendu enregistré, prochaine relance prévue.');
    }

    /**
     * The fiche picked without a planned meeting (« ?organisation= » or
     * « ?person= »), with its opportunities.
     *
     * @return array{kind: string, id: string, name: string, opportunities: list<array{id: string, name: string, stage_id: string}>}|null
     */
    private function fiche(Request $request, User $user): ?array
    {
        $organisation = ActivityFiches::organisation(FilterInput::id($request->query('organisation')), $user);
        if ($organisation !== null) {
            return ['kind' => 'organisation', 'id' => $organisation->id, 'name' => $organisation->name,
                'opportunities' => QuickReport::opportunitiesFor($user, null, $organisation->id, null)];
        }
        $person = ActivityFiches::person(FilterInput::id($request->query('person')), $user);

        return $person === null ? null : ['kind' => 'person', 'id' => $person->id, 'name' => $person->fullName(),
            'opportunities' => QuickReport::opportunitiesFor($user, null, null, $person->id)];
    }
}
