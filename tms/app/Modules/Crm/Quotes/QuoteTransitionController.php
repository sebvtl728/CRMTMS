<?php

namespace App\Modules\Crm\Quotes;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Opportunities\OpportunityStage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sending, reminder, « Renvoyer », acceptance and refusal of a quote
 * (crm.quotes.update on an editable quote; the transition itself is checked by
 * QuoteTransitions, 409 otherwise). An outcome proposes the matching move
 * of the opportunity (proposeWon / proposeLost, flashed): the window then
 * calls the stage endpoint, the user may decline.
 */
class QuoteTransitionController extends Controller
{
    public function __construct(private readonly QuoteTransitions $transitions, private readonly QuoteSignedCopy $copies) {}

    public function send(QuoteMessageRequest $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $this->transitions->send($quote, $request->message(), $request->brochureVersionIds(), $request->signOnline() ? $request->user() : null);

        return to_route('crm.quotes.show', $quote)->with('status', "Devis {$quote->number} envoyé.");
    }

    public function remind(QuoteMessageRequest $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $this->transitions->remind($quote, $request->message());

        return to_route('crm.quotes.show', $quote)->with('status', "Relance du devis {$quote->number} envoyée.");
    }

    public function resend(Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $this->transitions->resend($quote);

        return to_route('crm.quotes.show', $quote)->with('status', "Nouvel essai d'envoi du devis {$quote->number} en cours.");
    }

    public function accept(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $on = $this->date($request, $quote, 'accepted_on', 'd\'acceptation');
        // The signed copy is optional; checked before anything is written.
        $request->validate(QuoteSignedCopy::rules(false), QuoteSignedCopy::messages());
        $signed = $request->file(QuoteSignedCopy::FIELD);
        DB::transaction(function () use ($quote, $on, $signed): void {
            $this->transitions->accept($quote, $on);
            if ($signed instanceof UploadedFile) {
                $this->copies->store($quote, $signed);
            }
        });

        $redirect = to_route('crm.quotes.show', $quote)->with('status', "Devis {$quote->number} accepté.");
        if ($this->opportunityOpen($quote) && OpportunityStage::closingStage('won') !== null) {
            $redirect->with('proposeWon', [
                'amount_ht' => $quote->total_ht,
                'amount_display' => str_replace("\u{00A0}", ' ', QuotePdf::money($quote->total_ht)),
                'won_on' => $on->toDateString(),
            ]);
        }

        return $redirect;
    }

    public function refuse(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('update', $quote);
        $on = $this->date($request, $quote, 'refused_on', 'de refus');
        $reason = $request->validate(['refusal_reason' => ['nullable', 'string', 'max:2000']], ['refusal_reason.max' => 'Le motif est trop long.'])['refusal_reason'] ?? null;
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
        $this->transitions->refuse($quote, $on, $reason);

        $redirect = to_route('crm.quotes.show', $quote)->with('status', "Devis {$quote->number} refusé.");
        if ($this->opportunityOpen($quote) && OpportunityStage::closingStage('lost') !== null) {
            $redirect->with('proposeLost', true);
        }

        return $redirect;
    }

    /**
     * The date of the answer: today (Paris) by default, never in the future
     * nor before the date of the quote.
     */
    private function date(Request $request, Quote $quote, string $field, string $what): CarbonImmutable
    {
        $tz = QuoteNumbering::TIMEZONE;
        $today = CarbonImmutable::now($tz)->toDateString();
        $value = $request->validate([
            $field => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:'.$quote->issued_on->toDateString()],
        ], [
            "{$field}.date_format" => 'La date doit être au format AAAA-MM-JJ.',
            "{$field}.before_or_equal" => "La date {$what} ne peut pas être dans le futur.",
            "{$field}.after_or_equal" => "La date {$what} ne peut pas précéder la date du devis.",
        ])[$field] ?? null;

        return CarbonImmutable::parse(is_string($value) ? $value : $today, $tz);
    }

    private function opportunityOpen(Quote $quote): bool
    {
        $opportunity = $quote->opportunity()->first();

        return $opportunity !== null && $opportunity->won_on === null && $opportunity->lost_on === null;
    }
}
