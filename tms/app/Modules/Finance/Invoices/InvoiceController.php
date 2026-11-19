<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Exports\CsvExporter;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Finance\Billing\Particulier;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Payments\PaymentAllocation;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Reminders\ReminderController;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance › Factures (spec 0.9 § 4.5): the list of the invoices and credit
 * notes of the dossiers in the scope of finance.invoices.view, the page of
 * one (404 out of the scope of its dossier, a dossier in the trash
 * included), drafts made by hand from the dossier, edited and deleted
 * (finance.invoices.manage and its scope: 403), validated
 * (finance.invoices.validate), credit notes made from a validated invoice,
 * and the PDF.
 */
class InvoiceController extends Controller
{
    public const ARCHIVE_LOST = 'Le PDF archivé de cette pièce est introuvable : contactez l\'administrateur.';

    public function __construct(
        private readonly InvoiceDrafts $drafts,
        private readonly InvoiceRows $rows,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters($request);
        $page = $this->listQuery($user, $filters)->paginate(25)->withQueryString();

        return Inertia::render('finance/invoices/index', [
            'invoices' => [...$page->toArray(), 'data' => $this->rows->rows($page->getCollection())],
            'filters' => $filters,
            'options' => [
                'status' => InvoiceStatus::options(),
                'kind' => [['value' => Invoice::INVOICE, 'label' => 'Facture'], ['value' => Invoice::CREDIT_NOTE, 'label' => 'Avoir']],
                'company' => Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
                'payment' => [['value' => 'open', 'label' => 'Non soldées'], ['value' => 'overdue', 'label' => 'En retard']],
            ],
            'can' => [
                'export' => $user->can(Permissions::VIEW),
                'integrity' => $user->can(Permissions::VALIDATE),
            ],
        ]);
    }

    /**
     * CSV export of the list (« ; », French dates and amounts): the filters,
     * scope and sort of the list; audited with the filters and the row count.
     */
    public function export(Request $request, XlsxExporter $counter, CsvExporter $csv, AuditRecorder $audit, InvoiceExport $export): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters($request);
        /** @var Builder<Model> $query */
        $query = $this->listQuery($user, $filters);

        $rows = $counter->count($query);
        $audit->export('finance.invoices', ['filters' => array_filter($filters), 'rows' => $rows]);

        return $csv->export($query, $export, $user, $export->defaults(), $export->fileName().'-'.now()->format('Ymd-Hi').'.csv');
    }

    /**
     * « Vérifier l'intégrité »: the chain of the journal of the organisme,
     * recomputed (finance.invoices.validate); the result is flashed.
     */
    public function integrity(): RedirectResponse
    {
        $result = Integrity::verify();
        if ($result['broken'] !== null) {
            return back()->withErrors(['integrity' => "Intégrité rompue à la pièce {$result['broken']} : contactez l'administrateur."]);
        }

        return back()->with('status', $result['count'] === 0
            ? 'Intégrité vérifiée : aucune pièce validée pour le moment.'
            : 'Intégrité vérifiée : '.$result['count'].' pièce'.($result['count'] > 1 ? 's' : '').', chaîne intacte.');
    }

    /**
     * @return array{status: string|null, kind: string|null, company: string|null, payment: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'payment' => in_array($p = $request->query('payment'), ['open', 'overdue'], true) ? $p : null,
            'status' => in_array($s = $request->query('status'), InvoiceStatus::values(InvoiceStatus::cases()), true) ? $s : null,
            'kind' => in_array($k = $request->query('kind'), [Invoice::INVOICE, Invoice::CREDIT_NOTE], true) ? $k : null,
            'company' => is_string($c = $request->query('company')) && Str::isUuid($c) ? $c : null,
        ];
    }

    /**
     * The invoices of the list: the dossiers of the scope of finance.invoices.view
     * (in the trash too: their validated invoices stay findable), newest first.
     *
     * @param  array{status: string|null, kind: string|null, company: string|null, payment: string|null}  $filters
     * @return Builder<Invoice>
     */
    private function listQuery(User $user, array $filters): Builder
    {
        return Invoice::query()
            ->whereIn('dossier_id', Billing::dossiersFor($user, ['with_trashed' => true], Permissions::VIEW))
            ->when($filters['status'], fn ($q, string $s) => $q->where('status', $s))
            ->when($filters['kind'], fn ($q, string $k) => $q->where('kind', $k))
            ->when($filters['company'], fn ($q, string $id) => $q->where('company_id', $id))
            // « Non soldées » / « En retard »: issued invoices with something left to pay (0.9.1).
            ->when($filters['payment'], fn ($q) => $q->where('kind', Invoice::INVOICE)
                ->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
                ->whereRaw(Balances::remainingSql().' > 0'))
            ->when($filters['payment'] === 'overdue', fn ($q) => $q->where('due_on', '<', Balances::today()))
            ->orderByRaw('issued_on desc nulls first')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function show(Request $request, string $invoiceId): Response
    {
        $invoice = $this->find($request, $invoiceId);
        $canSend = ! $invoice->isDraft() && $this->manages($request, $invoice);

        return Inertia::render('finance/invoices/show', [
            'invoice' => $this->rows->page($invoice),
            'options' => [
                'units' => array_map(fn (string $u) => ['value' => $u, 'label' => $u], InvoiceDrafts::UNITS),
                'vat_rates' => array_map(fn (string $r) => ['value' => $r, 'label' => str_replace('.', ',', rtrim(rtrim($r, '0'), '.')).' %'], InvoiceTotals::RATES),
                'vat_exempt' => InvoiceTotals::exempt($invoice, Company::withTrashed()->findOrFail($invoice->company_id)),
            ],
            'can' => [
                'update' => $invoice->isDraft() && $this->manages($request, $invoice),
                'validate' => $invoice->isDraft() && $this->validates($request, $invoice),
                'credit_note' => ! $invoice->isDraft() && $invoice->kind === Invoice::INVOICE && $this->manages($request, $invoice),
                'send' => $canSend,
                'remind' => $canSend && $invoice->kind === Invoice::INVOICE,
                'record_payment' => $invoice->kind === Invoice::INVOICE && ! $invoice->isDraft() && $request->user()?->can(Permissions::PAYMENTS) === true,
            ],
            'balance' => $this->balance($invoice),
            'reminders' => ReminderController::forPage($invoice, $canSend),
            'warnings' => $this->warnings($invoice),
            'payments' => $this->payments($invoice),
            'email' => $invoice->isDraft() ? null : [
                'status' => $invoice->email_status,
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'defaults' => $canSend ? [...InvoiceMail::defaults($invoice), 'delivers_nowhere' => TenantMailer::deliversNowhere($invoice->company)] : null,
                'sendings' => $this->sendings($invoice),
            ],
        ]);
    }

    /**
     * What a draft should be checked for before its validation (0.9.1): a
     * particulier whose contract signature is unknown (withdrawal delay).
     *
     * @return list<string>
     */
    private function warnings(Invoice $invoice): array
    {
        if (! $invoice->isDraft() || $invoice->kind !== Invoice::INVOICE || $invoice->funding_id !== null) {
            return [];
        }
        $dossier = Billing::dossier($invoice->dossier_id);

        return $dossier !== null && Particulier::is($dossier) && ($dossier['convention_signed_on'] ?? null) === null ? [Particulier::NO_SIGNATURE] : [];
    }

    /**
     * What is paid and left on an issued invoice; null for a draft or a credit note.
     *
     * @return array<string, mixed>|null
     */
    private function balance(Invoice $invoice): ?array
    {
        $b = Balances::one($invoice);

        return $b === null ? null : [
            'ttc_cents' => $b['ttc_cents'],
            'credited_cents' => $b['credited_cents'],
            'paid_cents' => $b['paid_cents'],
            'remaining_cents' => $b['remaining_cents'],
            'status' => $b['status']->value,
            'status_label' => $b['status']->label(),
            'overdue' => $b['overdue'],
            'overpaid' => $b['overpaid'],
        ];
    }

    /**
     * The payments settling an invoice, oldest first (never their notes).
     *
     * @return list<array<string, mixed>>
     */
    private function payments(Invoice $invoice): array
    {
        $allocations = PaymentAllocation::query()->where('invoice_id', $invoice->id)->get();
        $payments = Payment::query()->whereKey($allocations->pluck('payment_id')->all())->get()->keyBy('id');
        $methods = ReferenceItem::withTrashed()->whereKey($payments->pluck('method_id')->unique()->values()->all())->pluck('label', 'id');

        return $allocations->map(function (PaymentAllocation $a) use ($payments, $methods): array {
            $p = $payments[$a->payment_id];

            return [
                'id' => $p->id,
                'received_on' => $p->received_on->toDateString(),
                'method' => $methods[$p->method_id] ?? '—',
                'reference' => $p->reference,
                'amount_cents' => $a->amount_cents,
                'payment_cents' => $p->amount_cents,
                'url' => '/finance/payments/'.$p->id.'/edit',
            ];
        })->sortBy('received_on')->values()->all();
    }

    /**
     * The e-mailings of an invoice, newest first (the last twenty).
     *
     * @return list<array<string, mixed>>
     */
    private function sendings(Invoice $invoice): array
    {
        $sendings = InvoiceSending::query()->where('invoice_id', $invoice->id)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
        $names = User::query()->whereKey($sendings->pluck('sent_by')->filter()->unique()->all())->pluck('name', 'id');
        $labels = ['pending' => 'En cours', 'sent' => 'Envoyé', 'failed' => 'Non parti'];

        return $sendings->map(fn (InvoiceSending $s) => [
            'id' => $s->id,
            'email' => $s->email,
            'status' => $s->status,
            'status_label' => $labels[$s->status] ?? $s->status,
            'error' => $s->error,
            'at' => ($s->sent_at ?? $s->created_at)?->toIso8601String(),
            'by' => $s->sent_by === null ? null : ($names[$s->sent_by] ?? null),
        ])->values()->all();
    }

    /**
     * « Envoyer »: the archived PDF by e-mail to the address typed (prefilled
     * from the snapshot), queued after commit (finance.invoices.manage and its
     * scope on the dossier: 403; 409 on a draft or while a sending is under way).
     */
    public function send(InvoiceMessageRequest $request, string $invoiceId, InvoiceSender $sender): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);
        /** @var User $user */
        $user = $request->user();

        $sender->send($user, $invoice, $request->message());

        return back()->with('status', $invoice->title().' : envoi en cours.');
    }

    /** « Renvoyer »: the last message again, after the e-mail did not leave. */
    public function resend(Request $request, string $invoiceId, InvoiceSender $sender): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);
        /** @var User $user */
        $user = $request->user();

        $sender->resend($user, $invoice);

        return back()->with('status', "Nouvel essai d'envoi de ".$invoice->title().' en cours.');
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

    /**
     * « Valider »: definitive number, frozen content, archived PDF
     * (finance.invoices.validate and its scope on the dossier: 403).
     */
    public function validateDraft(Request $request, string $invoiceId, InvoiceValidator $validator): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->validates($request, $invoice), 403);
        /** @var User $user */
        $user = $request->user();

        $validated = $validator->validate($invoice, $user);

        return back()->with('status', $validated->kindLabel().' validé'.($validated->kind === Invoice::INVOICE ? 'e' : '').' : '.$validated->number.'.');
    }

    /**
     * « Créer un avoir » from a validated invoice: all its lines, or those
     * chosen (lines[] = ids).
     */
    public function creditNote(Request $request, string $invoiceId, CreditNotes $creditNotes): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        abort_unless($this->manages($request, $invoice), 403);
        /** @var User $user */
        $user = $request->user();
        $lines = $request->input('lines');
        $ids = is_array($lines) ? array_values(array_filter($lines, 'is_string')) : null;

        $credit = $creditNotes->create($user, $invoice, $ids);

        return redirect('/finance/invoices/'.$credit->id)->with('status', 'Brouillon d\'avoir prêt.');
    }

    /**
     * The PDF: a validated piece always from its archive (410 when it is
     * missing: never rendered again), a draft previewed with a watermark.
     */
    public function pdf(Request $request, string $invoiceId, InvoicePdf $pdf): StreamedResponse
    {
        $invoice = $this->find($request, $invoiceId);
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.InvoicePdf::fileName($invoice).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (! $invoice->isDraft()) {
            $archived = $pdf->archived($invoice);
            if ($archived === null) {
                Log::warning('Archive PDF de la facture introuvable', ['invoice_id' => $invoice->id]);

                throw new UserFacingHttpException(410, self::ARCHIVE_LOST);
            }
            $disk = Storage::disk(TenantStorage::DISK);

            return response()->stream(function () use ($disk, $archived): void {
                $stream = $disk->readStream($archived);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, [...$headers, 'Content-Length' => (string) $disk->size($archived)]);
        }

        $bytes = $pdf->render($invoice);

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [...$headers, 'Content-Length' => (string) strlen($bytes)]);
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

    private function validates(Request $request, Invoice $invoice): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->can(Permissions::VALIDATE)
            && Billing::allows($user, Permissions::VALIDATE, $invoice->dossier_id, withTrashed: true);
    }
}
