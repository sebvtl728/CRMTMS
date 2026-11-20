<?php

namespace App\Modules\Finance\Payouts;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance › À reverser (spec 2026-11-20): what the mandataire owes to the
 * co-contractors of its free invoices, the payouts recorded, the export.
 */
final class PayoutController extends Controller
{
    public function __construct(private readonly CotraitantPayouts $payouts) {}

    public function index(Request $request): Response
    {
        $user = self::user($request);
        $filters = self::filters($request);
        $rows = $this->payouts->owed($user, $filters);
        $all = $filters['cotraitant'] === null && $filters['state'] === 'all' ? $rows : $this->payouts->owed($user, ['state' => 'all']);
        $recorded = CotraitantPayout::query()->whereIn('invoice_id', array_column($rows, 'invoice_id'))
            ->orderByDesc('paid_on')->orderByDesc('created_at')->get()
            ->groupBy(fn (CotraitantPayout $p) => $p->invoice_id.'|'.$p->cotraitant_id);

        return Inertia::render('finance/payouts', [
            'rows' => array_map(fn (array $r) => [...$r, 'payouts' => ($recorded[$r['invoice_id'].'|'.$r['cotraitant_id']] ?? collect())
                ->map(fn (CotraitantPayout $p) => ['id' => $p->id, 'amount_cents' => $p->amount_cents, 'paid_on' => $p->paid_on->toDateString(), 'reference' => $p->reference])
                ->values()->all()], $rows),
            'totals' => ['left_cents' => array_sum(array_map(fn (array $r) => max(0, $r['left_cents']), $rows))],
            'filters' => $filters,
            'cotraitants' => collect($all)->unique('cotraitant_id')->sortBy('cotraitant')
                ->map(fn (array $r) => ['value' => $r['cotraitant_id'], 'label' => $r['cotraitant']])->values()->all(),
            'today' => now('Europe/Paris')->toDateString(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $invoice = (string) $request->input('invoice_id', '');
        $cotraitant = (string) $request->input('cotraitant_id', '');
        abort_unless(Str::isUuid($invoice) && Str::isUuid($cotraitant), 404);
        $this->payouts->record(self::user($request), $invoice, $cotraitant, $request->only(['amount', 'paid_on', 'reference', 'notes']));

        return back()->with('status', 'Reversement enregistré.');
    }

    public function destroy(Request $request, string $payoutId): RedirectResponse
    {
        $payout = Str::isUuid($payoutId) ? CotraitantPayout::query()->find($payoutId) : null;
        abort_if($payout === null, 404);
        $this->payouts->delete(self::user($request), $payout);

        return back()->with('status', 'Reversement supprimé.');
    }

    /** CSV « ; » of the rows shown (export journaled, never a personal note). */
    public function export(Request $request, AuditRecorder $audit): StreamedResponse
    {
        $filters = self::filters($request);
        $rows = $this->payouts->owed(self::user($request), $filters);
        $audit->export('finance.payouts', ['filters' => array_filter($filters), 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            // Every cell neutralised (a fiche named « =… » never runs as a formula), as Core\Exports\CsvExporter.
            $write = fn (array $cells) => fputcsv($out, array_map(fn (string $c) => XlsxExporter::neutralise($c) ?? '', $cells), ';', '"', '', "\r\n");
            $write(['Facture', 'Émise le', 'Client', 'Co-traitant', 'Dû au co-traitant', 'Déjà reversé', 'Reste à reverser', 'État', 'Facture réglée par le client']);
            foreach ($rows as $r) {
                $write([
                    $r['number'], $r['issued_on'] === null ? '' : date('d/m/Y', (int) strtotime($r['issued_on'])), $r['recipient'], $r['cotraitant'],
                    Money::input($r['owed_cents'], true), Money::input($r['paid_out_cents'], true), Money::input($r['left_cents'], true), $r['state_label'], $r['buyer_paid'] ? 'Oui' : 'Non',
                ]);
            }
            fclose($out);
        }, 'reversements-cotraitants-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** @return array{state: string, cotraitant: ?string} */
    private static function filters(Request $request): array
    {
        $cotraitant = $request->query('cotraitant');

        return [
            'state' => $request->query('state') === 'open' ? 'open' : 'all',
            'cotraitant' => is_string($cotraitant) && Str::isUuid($cotraitant) ? $cotraitant : null,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
