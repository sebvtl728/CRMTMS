<?php

namespace App\Modules\Finance\Payments;

use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › Règlements (spec 0.9.1 § 4.1), finance.payments.manage on the
 * whole organisme (route middleware): the list of the payments, the form
 * (new, from an invoice, change), deletion, the search of a payer and the
 * open invoices of a payer.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly Payments $payments, private readonly Fiches $fiches) {}

    public function index(Request $request): Response
    {
        $unallocated = $request->boolean('unallocated');
        $company = is_string($c = $request->query('company')) && Str::isUuid($c) ? $c : null;
        $page = Payment::query()
            ->withSum('allocations as allocated_cents', 'amount_cents')
            ->when($company, fn ($q, string $id) => $q->where('company_id', $id))
            ->when($unallocated, fn ($q) => $q->whereRaw('payments.amount_cents > (select coalesce(sum(pa.amount_cents), 0) from payment_allocations pa where pa.payment_id = payments.id)'))
            ->orderByDesc('received_on')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $payers = $this->payerLabels($page->getCollection());
        $methods = ReferenceItem::withTrashed()->where('list', ReferenceList::PaymentMethod)->pluck('label', 'id');

        return Inertia::render('finance/payments/index', [
            'payments' => [...$page->toArray(), 'data' => $page->getCollection()->map(fn (Payment $p) => [
                'id' => $p->id,
                'received_on' => $p->received_on->toDateString(),
                'payer' => $payers[$p->payer_type.':'.$p->payer_id] ?? '-',
                'method' => $methods[$p->method_id] ?? '-',
                'reference' => $p->reference,
                'amount_cents' => $p->amount_cents,
                'allocated_cents' => (int) $p->getAttribute('allocated_cents'),
                'unallocated_cents' => $p->amount_cents - (int) $p->getAttribute('allocated_cents'),
                'url' => '/finance/payments/'.$p->id.'/edit',
            ])->values()->all()],
            'filters' => ['unallocated' => $unallocated, 'company' => $company],
            'options' => ['company' => $this->companies()],
            'status' => $request->session()->get('status'),
        ]);
    }

    /** The form, empty or prefilled from ?invoice= (payer, company, its remaining due). */
    public function create(Request $request): Response
    {
        $invoice = is_string($id = $request->query('invoice')) && Str::isUuid($id) ? Invoice::query()->find($id) : null;
        $balance = $invoice === null ? null : Balances::one($invoice);
        $remaining = $balance !== null && $balance['remaining_cents'] > 0 ? Money::input($balance['remaining_cents']) : null;
        $payer = $balance === null ? null : $this->payer($invoice->recipient_type, $invoice->recipient_id);

        return $this->form($request, [
            'id' => null,
            'payer' => $payer,
            'company_id' => $balance === null ? (Company::query()->where('is_default', true)->value('id')) : $invoice->company_id,
            'received_on' => Balances::today(),
            'amount' => $remaining ?? '',
            'method_id' => null,
            'reference' => '',
            'notes' => '',
            'allocations' => $remaining === null ? [] : [['invoice_id' => $invoice->id, 'amount' => $remaining]],
            'from_invoice' => $balance === null ? null : $invoice->id,
        ], []);
    }

    public function edit(Request $request, string $paymentId): Response
    {
        $payment = $this->find($paymentId);
        $allocations = $payment->allocations()->get();

        return $this->form($request, [
            'id' => $payment->id,
            'payer' => $this->payer($payment->payer_type, $payment->payer_id),
            'company_id' => $payment->company_id,
            'received_on' => $payment->received_on->toDateString(),
            'amount' => Money::input($payment->amount_cents),
            'method_id' => $payment->method_id,
            'reference' => $payment->reference ?? '',
            'notes' => $payment->notes ?? '',
            'allocations' => $allocations->map(fn (PaymentAllocation $a) => ['invoice_id' => $a->invoice_id, 'amount' => Money::input($a->amount_cents)])->values()->all(),
            'from_invoice' => null,
        ], $allocations->pluck('amount_cents', 'invoice_id')->all());
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->payments->record($user, $this->input($request));
        $from = $request->input('from_invoice');

        return redirect(is_string($from) && Str::isUuid($from) ? '/finance/invoices/'.$from : '/finance/payments')->with('status', 'Règlement enregistré.');
    }

    public function update(Request $request, string $paymentId): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->payments->update($user, $this->find($paymentId), $this->input($request));

        return redirect('/finance/payments')->with('status', 'Règlement modifié.');
    }

    public function destroy(Request $request, string $paymentId): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->payments->delete($user, $this->find($paymentId));

        return redirect('/finance/payments')->with('status', 'Règlement supprimé.');
    }

    /** Organisations and people matching ?q= (two characters at least), the payers to choose from. */
    public function payers(Request $request, Trainees $trainees): JsonResponse
    {
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';
        if (mb_strlen($q) < 2) {
            return response()->json(['payers' => []])->header('Cache-Control', 'no-store, private');
        }
        $organisations = array_map(fn (array $o) => ['type' => 'organisation', 'id' => $o['id'], 'label' => $o['label']], $this->fiches->searchOrganisations($q, 10));
        $people = array_map(fn (array $p) => ['type' => 'person', 'id' => $p['id'], 'label' => trim(($p['first_name'] ?? '').' '.$p['last_name'])], $trainees->search($q, null, 10));

        return response()->json(['payers' => [...$organisations, ...$people]])->header('Cache-Control', 'no-store, private');
    }

    /** The open invoices of a payer (?payer_type, ?payer_id, ?company_id). */
    public function openInvoices(Request $request): JsonResponse
    {
        $type = $request->query('payer_type');
        $id = $request->query('payer_id');
        $company = $request->query('company_id');
        $valid = in_array($type, ['organisation', 'person'], true) && is_string($id) && Str::isUuid($id);

        return response()->json(['invoices' => $valid
            ? $this->payments->openInvoices((string) $type, (string) $id, is_string($company) && Str::isUuid($company) ? $company : null)
            : []])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, int>  $own  this payment's allocations (invoice id => cents): available again in the form
     */
    private function form(Request $request, array $payment, array $own): Response
    {
        $open = $payment['payer'] === null ? [] : $this->payments->openInvoices($payment['payer']['type'], $payment['payer']['id'], $payment['company_id']);
        $listed = array_column($open, 'id');
        $missing = array_values(array_diff(array_keys($own), $listed));
        if ($missing !== []) {
            // An invoice this payment settles in full: listed too, its own allocation available.
            $balances = Balances::of($missing);
            foreach (Invoice::query()->whereKey($missing)->get() as $invoice) {
                $open[] = [
                    'id' => $invoice->id, 'number' => (string) $invoice->number, 'issued_on' => $invoice->issued_on?->toDateString(),
                    'due_on' => $invoice->due_on?->toDateString(), 'ttc_cents' => $invoice->total_ttc_cents,
                    'remaining_cents' => $balances[$invoice->id]['remaining_cents'] ?? 0, 'dossier_number' => '', 'company_id' => $invoice->company_id,
                ];
            }
        }
        $open = array_map(fn (array $i) => [...$i, 'remaining_cents' => $i['remaining_cents'] + (int) ($own[$i['id']] ?? 0)], $open);

        return Inertia::render('finance/payments/form', [
            'payment' => $payment,
            'open_invoices' => $open,
            'options' => [
                'methods' => ReferenceItem::options(ReferenceList::PaymentMethod)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values()->all(),
                'company' => $this->companies(),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->only(['payer_type', 'payer_id', 'company_id', 'received_on', 'amount', 'method_id', 'reference', 'notes', 'allocations']);
    }

    private function find(string $paymentId): Payment
    {
        $payment = Str::isUuid($paymentId) ? Payment::query()->find($paymentId) : null;
        abort_if($payment === null, 404);

        return $payment;
    }

    /**
     * @return array{type: string, id: string, label: string}
     */
    private function payer(string $type, string $id): array
    {
        $labels = $type === 'organisation' ? $this->fiches->organisationLabels([$id]) : $this->fiches->personLabels([$id]);

        return ['type' => $type, 'id' => $id, 'label' => $labels[$id]['label'] ?? '-'];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return array<string, string> « organisation:<id> », « person:<id> »
     */
    private function payerLabels(Collection $payments): array
    {
        $labels = [];
        foreach ($this->fiches->organisationLabels($payments->where('payer_type', 'organisation')->pluck('payer_id')->unique()->values()->all()) as $id => $o) {
            $labels['organisation:'.$id] = $o['label'];
        }
        foreach ($this->fiches->personLabels($payments->where('payer_type', 'person')->pluck('payer_id')->unique()->values()->all()) as $id => $p) {
            $labels['person:'.$id] = $p['label'];
        }

        return $labels;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function companies(): array
    {
        return Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all();
    }
}
