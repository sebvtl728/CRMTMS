<?php

namespace App\Modules\Finance\Invoices;

use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › Factures (spec 0.9 § 4.5): the list of the invoices and credit
 * notes of the dossiers in the scope of finance.invoices.view, the page of
 * one (404 out of the scope of its dossier, a dossier in the trash
 * included), drafts made by hand from the dossier, edited and deleted
 * (finance.invoices.manage and its scope: 403).
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceDrafts $drafts,
        private readonly InvoiceRows $rows,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = [
            'status' => in_array($s = $request->query('status'), InvoiceStatus::values(InvoiceStatus::cases()), true) ? $s : null,
            'kind' => in_array($k = $request->query('kind'), [Invoice::INVOICE, Invoice::CREDIT_NOTE], true) ? $k : null,
            'company' => is_string($c = $request->query('company')) && Str::isUuid($c) ? $c : null,
        ];
        $page = Invoice::query()
            // Dossiers in the trash too: their validated invoices stay findable.
            ->whereIn('dossier_id', Billing::dossiersFor($user, ['with_trashed' => true], Permissions::VIEW))
            ->when($filters['status'], fn ($q, string $s) => $q->where('status', $s))
            ->when($filters['kind'], fn ($q, string $k) => $q->where('kind', $k))
            ->when($filters['company'], fn ($q, string $id) => $q->where('company_id', $id))
            ->orderByRaw('issued_on desc nulls first')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('finance/invoices/index', [
            'invoices' => [...$page->toArray(), 'data' => $this->rows->rows($page->getCollection())],
            'filters' => $filters,
            'options' => [
                'status' => InvoiceStatus::options(),
                'kind' => [['value' => Invoice::INVOICE, 'label' => 'Facture'], ['value' => Invoice::CREDIT_NOTE, 'label' => 'Avoir']],
                'company' => Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            ],
        ]);
    }

    public function show(Request $request, string $invoiceId): Response
    {
        $invoice = $this->find($request, $invoiceId);

        return Inertia::render('finance/invoices/show', [
            'invoice' => $this->rows->page($invoice),
            'options' => [
                'units' => array_map(fn (string $u) => ['value' => $u, 'label' => $u], InvoiceDrafts::UNITS),
                'vat_rates' => array_map(fn (string $r) => ['value' => $r, 'label' => str_replace('.', ',', rtrim(rtrim($r, '0'), '.')).' %'], InvoiceTotals::RATES),
                'vat_exempt' => Company::withTrashed()->find($invoice->company_id)?->vat_regime?->value === 'exempt',
            ],
            'can' => [
                'update' => $invoice->isDraft() && $this->manages($request, $invoice),
            ],
        ]);
    }

    public function store(Request $request, string $dossierId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && Billing::allows($user, Permissions::VIEW, $dossierId), 404);
        abort_unless(Billing::allows($user, Permissions::MANAGE, $dossierId), 403);

        $invoice = $this->drafts->manual($user, $dossierId, (string) $request->input('recipient', ''));

        return redirect('/finance/invoices/'.$invoice->id)->with('status', 'Brouillon prêt.');
    }

    public function update(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);

        $this->drafts->update($invoice, $request->only(['notes', 'lines']));

        return back()->with('status', 'Brouillon enregistré.');
    }

    /** « Recalculer »: the computed lines of a draft from what is due now, free lines kept. */
    public function recompute(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);

        $this->drafts->recompute($invoice);

        return back()->with('status', 'Brouillon recalculé.');
    }

    /**
     * What is left to invoice on a dossier (its « Facturation » block, read
     * apart so the dossier page never waits for the computation): 404 out of
     * the scope of finance.invoices.view.
     */
    public function due(Request $request, string $dossierId, Due $due): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && Billing::allows($user, Permissions::VIEW, $dossierId), 404);
        $result = $due->forDossier($dossierId);

        return response()->json([
            'due' => array_map(fn (array $r) => [
                'recipient' => $r['recipient']['label'], 'kind' => $r['kind'], 'total_cents' => $r['total_cents'], 'period' => $r['period']['label'],
                'draft_url' => $r['draft']['url'] ?? null,
            ], $result['rows']),
            'soon' => $result['soon'][0]['reason'] ?? null,
        ]);
    }

    public function destroy(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);

        $this->drafts->delete($invoice);

        return redirect('/finance/invoices')->with('status', 'Brouillon supprimé.');
    }

    /** The invoice, 404 when unknown or out of the scope of finance.invoices.view on its dossier. */
    private function find(Request $request, string $invoiceId): Invoice
    {
        $user = $request->user();
        $invoice = Str::isUuid($invoiceId) ? Invoice::query()->find($invoiceId) : null;
        abort_unless($user instanceof User && $invoice !== null && Billing::allows($user, Permissions::VIEW, $invoice->dossier_id, withTrashed: true), 404);

        return $invoice;
    }

    private function manages(Request $request, Invoice $invoice): bool
    {
        $user = $request->user();

        return $user instanceof User && Billing::allows($user, Permissions::MANAGE, $invoice->dossier_id);
    }
}
