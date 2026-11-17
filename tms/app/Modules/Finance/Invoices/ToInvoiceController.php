<?php

namespace App\Modules\Finance\Invoices;

use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › À facturer (spec 0.9 § 4.5): what is due per dossier and per
 * recipient in the scope of finance.invoices.view, the dossiers « bientôt
 * à facturer », and « Préparer les brouillons ».
 */
class ToInvoiceController extends Controller
{
    public function __construct(
        private readonly Due $due,
        private readonly InvoiceDrafts $drafts,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = [
            'company' => self::uuid($request->query('company')),
            'das' => self::uuid($request->query('das')),
            'responsible' => self::uuid($request->query('responsible')),
            'month' => is_string($m = $request->query('month')) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : null,
        ];
        $due = $this->due->forUser($user, [
            'company_id' => $filters['company'], 'das_id' => $filters['das'], 'responsible_user_id' => $filters['responsible'], 'month' => $filters['month'],
        ]);

        return Inertia::render('finance/to-invoice', [
            'rows' => $due['rows'],
            'soon' => $due['soon'],
            'filters' => $filters,
            'options' => self::options(),
            'can' => ['manage' => $user->can(Permissions::MANAGE)],
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $keys = array_values(array_filter((array) $request->input('keys', []), 'is_string'));
        $result = $this->drafts->prepare($user, $keys);

        $created = $result['created'];
        $status = match (true) {
            $created === 0 => 'Aucun brouillon préparé.',
            $created === 1 => '1 brouillon préparé.',
            default => "{$created} brouillons préparés.",
        };
        if ($created > 0 && $result['skipped'] > 0) {
            $status .= " {$result['skipped']} ignoré(s) : brouillon déjà en cours ou plus rien à facturer.";
        }

        return back()->with('status', $status);
    }

    /**
     * @return array{company: list<array{value: string, label: string}>, das: list<array{value: string, label: string}>, responsible: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        return [
            'company' => Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'das' => ReferenceItem::query()->where('list', ReferenceList::Das)->orderBy('position')->orderBy('label')->get()
                ->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->is_active ? $i->label : $i->label.' (inactif)'])->values()->all(),
            'responsible' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values()->all(),
        ];
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
