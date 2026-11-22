<?php

namespace App\Modules\Qualite\Registers;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Qualite\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qualité › Réclamations and Qualité › Amélioration continue: read with
 * qualite.view, written with qualite.manage (routes).
 */
class RegisterController extends Controller
{
    public function __construct(private readonly Registers $registers) {}

    public function complaints(Request $request): Response
    {
        $labels = self::labels(ReferenceList::ComplaintChannel);

        return Inertia::render('qualite/registers/complaints', [
            'complaints' => Complaint::query()->orderByDesc('received_on')->orderByDesc('created_at')->get()->map(fn (Complaint $c) => [
                'id' => $c->id,
                'received_on' => $c->received_on->toDateString(),
                'channel_id' => $c->channel_id,
                'channel' => $labels[$c->channel_id] ?? null,
                'complainant' => $c->complainant,
                'subject' => $c->subject,
                'description' => $c->description,
                'status' => $c->status,
                'status_label' => Complaint::STATUSES[$c->status],
                'response' => $c->response,
                'responded_on' => $c->responded_on?->toDateString(),
            ])->all(),
            'options' => ['channels' => self::options(ReferenceList::ComplaintChannel), 'statuses' => self::statuses(Complaint::STATUSES)],
            'can' => ['manage' => self::user($request)->can(Permissions::MANAGE)],
        ]);
    }

    public function storeComplaint(Request $request): RedirectResponse
    {
        $this->registers->saveComplaint(null, $request->all(), self::user($request));

        return back()->with('status', 'Réclamation enregistrée.');
    }

    public function updateComplaint(Request $request, Complaint $complaint): RedirectResponse
    {
        $this->registers->saveComplaint($complaint, $request->all(), self::user($request));

        return back()->with('status', 'Réclamation enregistrée.');
    }

    public function actions(Request $request): Response
    {
        $labels = self::labels(ReferenceList::ImprovementSource);
        $users = User::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('qualite/registers/actions', [
            'actions' => ImprovementAction::query()->with('complaint')->orderByRaw("case status when 'done' then 2 when 'abandoned' then 3 else 1 end")
                ->orderBy('due_on')->orderByDesc('created_at')->get()->map(fn (ImprovementAction $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'description' => $a->description,
                    'source_id' => $a->source_id,
                    'source' => $labels[$a->source_id] ?? null,
                    'complaint_id' => $a->complaint_id,
                    'complaint' => $a->complaint === null ? null : ['id' => $a->complaint->id, 'subject' => $a->complaint->subject],
                    'responsible_user_id' => $a->responsible_user_id,
                    'responsible' => $users->firstWhere('id', $a->responsible_user_id)?->name,
                    'due_on' => $a->due_on?->toDateString(),
                    'status' => $a->status,
                    'status_label' => ImprovementAction::STATUSES[$a->status],
                    'done_on' => $a->done_on?->toDateString(),
                    'result' => $a->result,
                ])->all(),
            'options' => [
                'sources' => self::options(ReferenceList::ImprovementSource),
                'statuses' => self::statuses(ImprovementAction::STATUSES),
                'complaints' => Complaint::query()->orderByDesc('received_on')->get(['id', 'subject', 'received_on'])
                    ->map(fn (Complaint $c) => ['value' => $c->id, 'label' => $c->received_on->format('d/m/Y').' - '.$c->subject])->all(),
                'users' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
            'can' => ['manage' => self::user($request)->can(Permissions::MANAGE)],
        ]);
    }

    public function storeAction(Request $request): RedirectResponse
    {
        $this->registers->saveAction(null, $request->all(), self::user($request));

        return back()->with('status', 'Action enregistrée.');
    }

    public function updateAction(Request $request, ImprovementAction $action): RedirectResponse
    {
        $this->registers->saveAction($action, $request->all(), self::user($request));

        return back()->with('status', 'Action enregistrée.');
    }

    /** @return list<array{value: string, label: string}> */
    private static function options(ReferenceList $list): array
    {
        return ReferenceItem::options($list)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values()->all();
    }

    /** @return array<string, string> id => label, inactive and deleted ones included (stored values keep their name). */
    private static function labels(ReferenceList $list): array
    {
        return ReferenceItem::withTrashed()->where('list', $list)->pluck('label', 'id')->all();
    }

    /**
     * @param  array<string, string>  $statuses
     * @return list<array{value: string, label: string}>
     */
    private static function statuses(array $statuses): array
    {
        return array_map(fn (string $v, string $l) => ['value' => $v, 'label' => $l], array_keys($statuses), $statuses);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
