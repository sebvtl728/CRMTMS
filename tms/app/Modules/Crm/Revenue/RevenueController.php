<?php

namespace App\Modules\Crm\Revenue;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Amount;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\QuoteTotals;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Chiffre d'affaires » per DAS: the table of a period, the detail of a DAS
 * line (JSON, for the side panel) and the targets of the period. The scope of
 * crm.revenue.view is applied by DasRevenue and RevenueSources; « Ses fiches »
 * sees neither targets nor rates (organisme targets would mislead).
 */
class RevenueController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $period = $this->period($request);
        $own = RevenueSources::scope($user) === AccessScope::Own;

        $result = DasRevenue::for($user, $period);
        if ($own) {
            $result['rows'] = array_map(fn (array $row) => [...$row, 'target_cents' => null, 'rate' => null], $result['rows']);
            $result['totals']['target_cents'] = null;
        }

        return Inertia::render('crm/revenue/index', [
            'period' => self::periodProps($period),
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'own' => $own,
            'can' => ['manage' => ! $own && $user->can(Permissions::REVENUE_MANAGE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Quotes and opportunities of one DAS line; 404 for a DAS the user does not follow.
     */
    public function sources(Request $request, string $das): JsonResponse
    {
        $user = $this->user($request);
        $period = $this->period($request);

        abort_unless(Str::isUuid($das) && ReferenceItem::query()->where('list', ReferenceList::Das)->whereKey($das)->exists(), 404);
        abort_if(Scopes::of($user, Permissions::REVENUE_VIEW) === AccessScope::Das && ! in_array($das, CrmVisibility::followedDas($user), true), 404);

        $sources = array_map(fn (array $row) => [...$row, 'url' => $row['kind'] === 'quote' ? route('crm.quotes.show', $row['id']) : route('crm.opportunities.show', $row['id'])],
            RevenueSources::for($user, $period, $das));

        return response()->json(['period' => self::periodProps($period), 'sources' => $sources]);
    }

    /**
     * One DAS target of a period, amount typed the French way; empty or 0 clears it.
     */
    public function setTarget(Request $request, RevenueTargets $targets): RedirectResponse
    {
        $data = $request->validate([
            'das_id' => ['required', 'uuid'],
            'period' => ['required', 'string'],
            'key' => ['required', 'string'],
            'amount' => ['nullable', 'string', 'max:30'],
        ], ['das_id.*' => 'Ce DAS n\'existe pas.']);

        $period = Period::of($data['period'], $data['key']);

        $amount = Amount::parse($data['amount'] ?? null);
        if ($amount !== null && preg_match('/^\d+(\.\d{1,2})?$/', (string) $amount) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant positif avec deux décimales au plus (par exemple 10 000,50).']);
        }

        $targets->set($data['das_id'], $period, $amount === null ? null : QuoteTotals::cents((string) $amount));

        return back()->with('status', 'Objectif enregistré.');
    }

    /**
     * The period asked (type and key, the current Paris month by default).
     */
    private function period(Request $request): Period
    {
        $type = $request->query('period', 'month');
        $key = $request->query('key');
        $type = is_string($type) ? $type : '';

        try {
            return is_string($key) && $key !== '' ? Period::of($type, $key) : Period::current($type, CarbonImmutable::now());
        } catch (ValidationException $e) {
            throw new UserFacingHttpException(422, 'La période demandée est invalide.', $e);
        }
    }

    /**
     * @return array{type: string, key: string, label: string, previous_key: string, next_key: string}
     */
    private static function periodProps(Period $period): array
    {
        return [
            'type' => $period->type,
            'key' => $period->key(),
            'label' => $period->label(),
            'previous_key' => $period->previous()->key(),
            'next_key' => $period->next()->key(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
