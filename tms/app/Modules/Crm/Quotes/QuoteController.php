<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Devis » screens: list, fiche, draft form (created from an opportunity),
 * deletion of a draft, new version. Sending and outcomes come later
 * (QuoteTransitions); only a draft is changed (409 otherwise).
 */
class QuoteController extends Controller
{
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

        $totals = QuoteTotals::compute($quote->lines->map(fn (QuoteLine $l) => new LineInput($l->quantity, $l->unit_price, $l->discount_percent, $l->vat_rate))->all(), $quote->vat_exempt);

        return Inertia::render('crm/quotes/show', [
            'quote' => [
                ...QuoteRows::row($quote),
                'contact' => $quote->contact === null ? null : ['id' => $quote->contact->id, 'name' => $quote->contact->fullName()],
                'notes' => $quote->notes,
                'vat_exempt' => $quote->vat_exempt,
                'vat_notice' => $quote->vat_exempt ? LegalMentions::vatExemptionNotice() : null,
                'sent_at' => $quote->sent_at?->toIso8601String(),
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
                'update' => $quote->isDraft() && $user->can('update', $quote),
                'delete' => $quote->isDraft() && $user->can('delete', $quote),
                'duplicate' => $user->can('duplicate', $quote),
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
     * A new version: a draft copy with a new number.
     */
    public function duplicate(Quote $quote): RedirectResponse
    {
        Gate::authorize('duplicate', $quote);
        $opportunity = $quote->opportunity;
        assert($opportunity !== null);

        $copy = $this->writer->duplicate($quote, $opportunity);

        return to_route('crm.quotes.show', $copy)->with('status', "Nouvelle version créée : devis {$copy->number}.");
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
