<?php

namespace App\Modules\Crm\Public;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteLine;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\QuoteTotals;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Public read façade of the accepted quotes (lot 2a: « Créer le dossier »).
 * Only an accepted quote the viewer sees through crm.quotes.view, whose
 * opportunity and account are out of the trash.
 */
final class AcceptedQuotes
{
    public function find(string $quoteId, User $viewer): ?AcceptedQuoteData
    {
        if (! Str::isUuid($quoteId)) {
            return null;
        }

        /** @var Quote|null $quote */
        $quote = CrmVisibility::quotes(Quote::query(), $viewer, Permissions::QUOTES_VIEW)
            ->whereKey($quoteId)
            ->where('status', QuoteStatus::Accepted->value)
            ->whereHas('opportunity', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->with(['lines', 'opportunity', 'organisation', 'person'])
            ->first();

        $opportunity = $quote?->opportunity;
        if ($quote === null || $opportunity === null) {
            return null;
        }
        $account = self::account($quote);
        if ($account === null) {
            return null;
        }

        return new AcceptedQuoteData(
            id: $quote->id,
            number: $quote->number,
            company_id: $quote->company_id,
            account: $account,
            contact_person_id: $quote->contact_person_id,
            opportunity_id: $opportunity->id,
            das_id: $opportunity->das_id,
            lines: $quote->lines->map(fn (QuoteLine $line): array => [
                'training_id' => $line->training_id,
                'das_id' => $line->das_id,
                'label' => $line->label,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit,
                'unit_price_cents' => QuoteTotals::cents((string) $line->unit_price),
                'discount_percent' => (string) $line->discount_percent,
                'total_ht_cents' => QuoteTotals::cents((string) $line->total_ht),
            ])->values()->all(),
        );
    }

    /**
     * The entreprise or the particulier of the quote; null when in the trash.
     *
     * @return array{type: 'organisation'|'person', id: string, label: string}|null
     */
    private static function account(Quote $quote): ?array
    {
        if ($quote->organisation_id !== null) {
            $organisation = $quote->organisation;

            return $organisation === null || $organisation->trashed() ? null
                : ['type' => 'organisation', 'id' => $organisation->id, 'label' => $organisation->name];
        }
        $person = $quote->person;

        return $person === null || $person->trashed() ? null
            : ['type' => 'person', 'id' => $person->id, 'label' => $person->fullName()];
    }
}
