<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rows of a quote list (list page, « Devis » tab of an opportunity or a fiche).
 */
final class QuoteRows
{
    /** @var list<string> */
    public const WITH = [
        'company:id,name,quote_prefix,deleted_at',
        'opportunity:id,title,das_id,responsible_user_id,organisation_id,person_id,deleted_at',
        'opportunity.das',
        'opportunity.responsible:id,name',
        'organisation:id,name,deleted_at',
        'person:id,first_name,last_name,deleted_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(Quote $q): array
    {
        return [
            'id' => $q->id,
            'number' => $q->number,
            'status' => $q->status->toOption(),
            'company' => $q->company?->name,
            'opportunity' => ['id' => $q->opportunity_id, 'title' => $q->opportunity?->title],
            'account' => $q->person_id !== null
                ? ['type' => 'person', 'id' => $q->person_id, 'name' => $q->person?->fullName()]
                : ['type' => 'organisation', 'id' => $q->organisation_id, 'name' => $q->organisation?->name],
            'das' => $q->opportunity?->das?->label,
            'responsible' => $q->opportunity?->responsible?->name,
            'issued_on' => $q->issued_on->toDateString(),
            'valid_until' => $q->valid_until->toDateString(),
            'total_ht' => $q->total_ht,
            'total_ttc' => $q->total_ttc,
        ];
    }

    /**
     * Quotes of an entreprise or a person (account or recipient) the user
     * sees, opportunities in the bin left out; the latest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forFiche(Organisation|Person $fiche, User $user): array
    {
        return self::rows(self::visible($user)->where(fn (Builder $q) => $fiche instanceof Organisation
            ? $q->where('quotes.organisation_id', $fiche->getKey())
            : $q->where('quotes.person_id', $fiche->getKey())->orWhere('quotes.contact_person_id', $fiche->getKey())));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forOpportunity(Opportunity $opportunity, User $user): array
    {
        return self::rows(self::visible($user)->where('quotes.opportunity_id', $opportunity->getKey()));
    }

    /**
     * @return Builder<Quote>
     */
    private static function visible(User $user): Builder
    {
        return CrmVisibility::quotes(Quote::query(), $user)
            ->whereIn('quotes.opportunity_id', Opportunity::query()->select('opportunities.id'));
    }

    /**
     * @param  Builder<Quote>  $query
     * @return list<array<string, mixed>>
     */
    private static function rows(Builder $query): array
    {
        return $query->with(self::WITH)
            ->orderByDesc('quotes.issued_on')->orderByDesc('quotes.number')
            ->limit(200)->get()
            ->map(fn (Quote $q) => self::row($q))->values()->all();
    }
}
