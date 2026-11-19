<?php

namespace App\Modules\Finance\Reminders;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceMessageRequest;
use App\Modules\Finance\Invoices\InvoiceRows;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Invoices\Money;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › À relancer (spec 0.9.1 § 4.3), finance.invoices.manage and its
 * scope on the dossiers: the invoices to remind, the reminders sent in a
 * batch, one reminder typed from the invoice page, « Suspendre » and
 * « Reprendre » the reminders of an invoice.
 */
class ReminderController extends Controller
{
    public function __construct(private readonly Reminders $reminders, private readonly InvoiceRows $rows) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $due = $this->reminders->due($user);
        $invoices = new Collection(array_map(fn (array $d) => $d['invoice'], $due));
        $rows = collect($this->rows->rows($invoices))->keyBy('id');

        return Inertia::render('finance/reminders/index', [
            'reminders' => array_map(fn (array $d) => [
                'id' => $d['invoice']->id,
                'title' => $d['invoice']->title(),
                'url' => '/finance/invoices/'.$d['invoice']->id,
                'recipient' => $rows[$d['invoice']->id]['recipient'] ?? '—',
                'dossier' => $rows[$d['invoice']->id]['dossier'] ?? null,
                'due_on' => $d['invoice']->due_on?->toDateString(),
                'days_late' => $d['days_late'],
                'level' => $d['level'],
                'remaining_cents' => $d['remaining_cents'],
                'email' => $d['email'],
                'last' => $d['last'],
            ], $due),
            'status' => $request->session()->get('status'),
        ]);
    }

    /** « Envoyer les relances »: the level due of each invoice chosen, its template. */
    public function send(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ids = array_values(array_unique(array_filter((array) $request->input('ids', []), fn ($id) => is_string($id) && Str::isUuid($id))));
        $ids = array_slice($ids, 0, Reminders::LIMIT);
        $sent = 0;
        $skipped = 0;
        foreach (Invoice::query()->whereKey($ids)->get() as $invoice) {
            if (! Billing::allows($user, Permissions::MANAGE, $invoice->dossier_id)) {
                $skipped++;

                continue;
            }
            try {
                $this->reminders->send($user, $invoice);
                $sent++;
            } catch (UserFacingHttpException) {
                $skipped++;
            }
        }
        $skipped += count($ids) - $sent - $skipped;

        return back()->with('status', $sent.' relance'.($sent > 1 ? 's envoyées' : ' envoyée')
            .($skipped > 0 ? ', '.$skipped.' ignorée'.($skipped > 1 ? 's' : '').' (déjà soldée, suspendue ou sans adresse).' : '.'));
    }

    /** « Relancer » from the invoice page: the message typed. */
    public function remind(InvoiceMessageRequest $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        /** @var User $user */
        $user = $request->user();
        $sending = $this->reminders->send($user, $invoice, $request->message());

        return back()->with('status', 'Relance de niveau '.$sending->reminder_level.' en cours d\'envoi.');
    }

    public function pause(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        /** @var User $user */
        $user = $request->user();
        $reason = $request->input('reason');
        $this->reminders->pause($user, $invoice, is_string($reason) ? $reason : null);

        return back()->with('status', 'Relances suspendues.');
    }

    public function resume(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->find($request, $invoiceId);
        /** @var User $user */
        $user = $request->user();
        $this->reminders->resume($user, $invoice);

        return back()->with('status', 'Relances reprises.');
    }

    /**
     * What the invoice page shows of the reminders of an issued invoice:
     * the level due now, the pause, the history, the message proposed.
     *
     * @return array<string, mixed>|null
     */
    public static function forPage(Invoice $invoice, bool $canRemind): ?array
    {
        if ($invoice->kind !== Invoice::INVOICE || $invoice->isDraft()) {
            return null;
        }
        $reminders = app(Reminders::class);
        $next = $reminders->nextLevel($invoice);
        $pause = Reminders::paused($invoice);
        $labels = ['pending' => 'En cours', 'sent' => 'Envoyée', 'failed' => 'Non partie'];

        return [
            'next_level' => $next,
            'paused' => $pause === null ? null : ['at' => $pause->created_at?->toIso8601String(), 'reason' => $canRemind ? $pause->reason : null],
            'defaults' => $canRemind && $next !== null ? ReminderMail::defaults($invoice, $next) : null,
            'history' => InvoiceSending::query()->where('invoice_id', $invoice->id)->where('purpose', 'reminder')
                ->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (InvoiceSending $s) => [
                    'id' => $s->id,
                    'level' => $s->reminder_level,
                    'status_label' => $labels[$s->status] ?? $s->status,
                    'at' => ($s->sent_at ?? $s->created_at)?->toIso8601String(),
                    'automatic' => $s->sent_by === null,
                ])->values()->all(),
            'remaining' => Money::format(max(0, (int) (Balances::one($invoice)['remaining_cents'] ?? 0))),
        ];
    }

    /** The invoice, 404 out of the scope of finance.invoices.view, 403 without managing it. */
    private function find(Request $request, string $invoiceId): Invoice
    {
        $user = $request->user();
        $invoice = Str::isUuid($invoiceId) ? Invoice::query()->find($invoiceId) : null;
        abort_unless($user instanceof User && $invoice !== null && Billing::allows($user, Permissions::VIEW, $invoice->dossier_id, withTrashed: true), 404);
        abort_unless(Billing::allows($user, Permissions::MANAGE, $invoice->dossier_id), 403);

        return $invoice;
    }
}
