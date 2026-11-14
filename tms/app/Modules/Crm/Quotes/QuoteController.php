<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\FormOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Devis » screens: list, fiche, draft form (created from an opportunity),
 * deletion of a draft, new version, PDF. Sending and outcomes:
 * QuoteTransitionController; only a draft is changed (409 otherwise).
 */
class QuoteController extends Controller
{
    public const DOCUMENT_GONE = 'Document supprimé (données anonymisées).';

    public function __construct(
        private readonly QuoteWriter $writer,
        private readonly QuoteForm $form,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $filters = QuoteListQuery::filters($request->query());

        return Inertia::render('crm/quotes/index', [
            'quotes' => QuoteListQuery::build($filters, $user)->with(QuoteRows::WITH)->paginate(25)->withQueryString()
                ->through(fn (Quote $q) => QuoteRows::row($q)),
            'filters' => $filters,
            'options' => QuoteListQuery::options(),
            'success_rate' => QuoteSuccessRate::banner($user, $request->query('period'), CarbonImmutable::now(), $filters),
            'can' => ['export' => $user->can(Permissions::EXPORT)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(Opportunity $opportunity): Response
    {
        Gate::authorize('create', [Quote::class, $opportunity]);

        return Inertia::render('crm/quotes/form', $this->form->create($opportunity));
    }

    public function store(QuoteRequest $request): RedirectResponse
    {
        $opportunity = $request->opportunity();
        $company = $request->company();
        // Validated: both exist (and the opportunity is visible).
        assert($opportunity !== null && $company !== null);
        Gate::authorize('create', [Quote::class, $opportunity]);

        $quote = $this->writer->create($opportunity, $company, $request->quoteData());

        return to_route('crm.quotes.show', $quote)->with('status', "Devis {$quote->number} créé.");
    }

    public function show(Request $request, Quote $quote): Response
    {
        $user = $this->user($request);
        Gate::authorize('view', $quote);
        $quote->load([...QuoteRows::WITH, 'opportunity.organisation', 'opportunity.person', 'contact:id,first_name,last_name,deleted_at', 'lines.das']);

        $canUpdate = $user->can('update', $quote);
        // The windows read the whole recipient (the lists above load a few columns).
        $mailQuote = Quote::query()->with('company')->findOrFail($quote->id);
        $nowhere = TenantMailer::deliversNowhere($quote->company);
        $replacedBy = $quote->replaced_by_id === null ? null : Quote::query()->find($quote->replaced_by_id, ['id', 'number']);

        $totals = QuoteTotals::compute($quote->lines->map(fn (QuoteLine $l) => new LineInput($l->quantity, $l->unit_price, $l->discount_percent, $l->vat_rate))->all(), $quote->vat_exempt);

        return Inertia::render('crm/quotes/show', [
            'quote' => [
                ...QuoteRows::row($quote),
                'contact' => $quote->contact === null ? null : ['id' => $quote->contact->id, 'name' => $quote->contact->fullName()],
                'notes' => $quote->notes,
                'vat_exempt' => $quote->vat_exempt,
                'vat_notice' => $quote->vat_exempt ? LegalMentions::vatExemptionNotice() : null,
                'sent_at' => $quote->sent_at?->toIso8601String(),
                'reminded_at' => $quote->reminded_at?->toIso8601String(),
                'accepted_on' => $quote->accepted_on?->toDateString(),
                'refused_on' => $quote->refused_on?->toDateString(),
                'refusal_reason' => $quote->refusal_reason,
                'replaced_by' => $replacedBy === null ? null : ['id' => $replacedBy->id, 'number' => $replacedBy->number],
                'email' => $quote->email_status === null ? null : [
                    'status' => $quote->email_status,
                    'error' => $quote->email_error,
                    'sent_at' => $quote->email_sent_at?->toIso8601String(),
                ],
                'created_at' => $quote->created_at?->toIso8601String(),
                'lines' => $quote->lines->map(fn (QuoteLine $l) => [
                    'id' => $l->id,
                    'training_id' => $l->training_id,
                    'das' => $l->das?->label,
                    'label' => $l->label,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit' => $l->unit,
                    'unit_label' => QuoteLine::UNITS[$l->unit] ?? $l->unit,
                    'unit_price' => $l->unit_price,
                    'discount_percent' => $l->discount_percent,
                    'vat_rate' => $l->vat_rate,
                    'total_ht' => $l->total_ht,
                ])->values()->all(),
                // The stored amounts; the breakdown by rate from the same computation.
                'totals' => [
                    'ht' => $quote->total_ht,
                    'vat' => $quote->total_vat,
                    'ttc' => $quote->total_ttc,
                    'vat_by_rate' => array_map(fn (array $r) => [
                        'rate' => $r['rate'],
                        'base' => QuoteTotals::decimal($r['base']),
                        'vat' => QuoteTotals::decimal($r['vat']),
                    ], $totals->vatByRate),
                ],
            ],
            'can' => [
                'update' => $quote->isDraft() && $canUpdate,
                'delete' => $quote->isDraft() && $user->can('delete', $quote),
                'duplicate' => $user->can('duplicate', $quote),
                'send' => $quote->isDraft() && $canUpdate,
                'remind' => $quote->isAwaitingAnswer() && ! in_array($quote->email_status, ['pending', 'failed'], true) && $canUpdate,
                'resend' => $quote->email_status === 'failed' && $canUpdate,
                'answer' => $quote->isAwaitingAnswer() && $canUpdate,
            ],
            // Prefilled windows; a warning when nothing would really leave.
            'send' => $quote->isDraft() ? [...QuoteMail::defaults($mailQuote, QuoteMail::KIND_SEND), 'delivers_nowhere' => $nowhere] : null,
            'remind' => $quote->isAwaitingAnswer() ? [...QuoteMail::defaults($mailQuote, QuoteMail::KIND_REMIND), 'delivers_nowhere' => $nowhere] : null,
            // The outcome windows move the opportunity through the stage endpoint.
            'pipeline' => [
                'opportunity_id' => $quote->opportunity_id,
                'open' => $quote->opportunity !== null && $quote->opportunity->won_on === null && $quote->opportunity->lost_on === null,
                'won_stage_id' => OpportunityStage::closingStage('won')?->id,
                'lost_stage_id' => OpportunityStage::closingStage('lost')?->id,
                'loss_reasons' => FormOptions::reference(ReferenceList::LossReason),
            ],
            'propose' => [
                'won' => $request->session()->get('proposeWon'),
                'lost' => (bool) $request->session()->get('proposeLost', false),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Quote $quote): Response
    {
        Gate::authorize('update', $quote);
        self::draftOnly($quote);
        $opportunity = $quote->opportunity;
        assert($opportunity !== null);

        return Inertia::render('crm/quotes/form', $this->form->edit($quote, $opportunity));
    }

    public function update(QuoteRequest $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        self::draftOnly($quote);

        $this->writer->update($quote, $request->quoteData());

        return to_route('crm.quotes.show', $quote)->with('status', 'Devis enregistré.');
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        Gate::authorize('delete', $quote);
        if (! $quote->isDraft()) {
            throw new UserFacingHttpException(409, 'Seul un brouillon se supprime : un devis envoyé reste dans l\'historique.');
        }

        DB::transaction(fn () => $quote->delete());

        return to_route('crm.opportunities.show', $quote->opportunity_id)->with('status', "Devis {$quote->number} supprimé.");
    }

    /**
     * A new version: a draft copy with a new number; a sent quote is then
     * refused as replaced by it (QuoteTransitions::newVersion).
     */
    public function duplicate(Quote $quote, QuoteTransitions $transitions): RedirectResponse
    {
        Gate::authorize('duplicate', $quote);
        // The source is then refused as replaced: that is a change of it.
        if ($quote->isAwaitingAnswer() || $quote->status === QuoteStatus::Expired) {
            Gate::authorize('update', $quote);
        }
        $opportunity = $quote->opportunity;
        assert($opportunity !== null);

        $copy = $transitions->newVersion($quote, $this->writer, $opportunity);

        return to_route('crm.quotes.show', $copy)->with('status', "Nouvelle version créée : devis {$copy->number}.");
    }

    /**
     * The archived PDF of a sent quote, else (a draft) a preview rendered on
     * the fly with a watermark. Shown in the browser, never cached. A sent
     * quote without its archive (purged by the anonymisation, or lost)
     * answers 410: it is never rendered again with today's data.
     */
    public function pdf(Quote $quote, QuotePdf $pdf): StreamedResponse
    {
        Gate::authorize('view', $quote);

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.QuotePdf::fileName($quote).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $archived = $pdf->archived($quote);
        if ($archived !== null) {
            $disk = Storage::disk(TenantStorage::DISK);

            return response()->stream(function () use ($disk, $archived): void {
                $stream = $disk->readStream($archived);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, [...$headers, 'Content-Length' => (string) $disk->size($archived)]);
        }

        // A sent quote is only ever served from its archive: never rendered
        // again with today's company or fiches. Purged by the anonymisation
        // (pdf_path cleared), or lost (warned: it should be there).
        if (! $quote->isDraft()) {
            if ($quote->pdf_path !== null) {
                Log::warning('Archive PDF du devis introuvable', ['quote_id' => $quote->id]);
            }

            throw new UserFacingHttpException(410, self::DOCUMENT_GONE);
        }

        $bytes = $pdf->render($quote);

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [...$headers, 'Content-Length' => (string) strlen($bytes)]);
    }

    private static function draftOnly(Quote $quote): void
    {
        if (! $quote->isDraft()) {
            throw new UserFacingHttpException(409, 'Un devis envoyé ne se modifie plus.');
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
