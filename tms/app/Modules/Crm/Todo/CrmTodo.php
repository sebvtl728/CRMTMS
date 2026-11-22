<?php

namespace App\Modules\Crm\Todo;

use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\QuoteListQuery;
use App\Modules\Crm\Quotes\QuoteStatus;
use Carbon\CarbonImmutable;

/** What the CRM declares to « À traiter » (2026-11-22): the same queries as its lists. */
final class CrmTodo
{
    public const QUOTE_SILENCE_DAYS = 15;

    public static function register(): void
    {
        TodoSources::register('crm.silent_quotes', 'Commercial', 'Devis envoyés sans réponse depuis plus de '.self::QUOTE_SILENCE_DAYS.' jours', Permissions::QUOTES_VIEW,
            fn (User $u): int => QuoteListQuery::build(QuoteListQuery::filters([]), $u)
                ->whereIn('quotes.status', [QuoteStatus::Sent->value, QuoteStatus::Reminded->value])
                ->where('quotes.sent_at', '<=', CarbonImmutable::now()->subDays(self::QUOTE_SILENCE_DAYS))->count(),
            fn (User $u): string => '/crm/quotes?status='.QuoteStatus::Sent->value);

        TodoSources::register('crm.activities', 'Commercial', 'Actions commerciales à faire', Permissions::ACTIVITIES_VIEW,
            fn (User $u): int => TodoQuery::badge($u),
            fn (User $u): string => '/crm/todo');
    }
}
