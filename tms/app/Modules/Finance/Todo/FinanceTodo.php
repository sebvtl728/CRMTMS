<?php

namespace App\Modules\Finance\Todo;

use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Due;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Reminders\Reminders;

/** What Finance declares to « À traiter » (2026-11-22): the same sources as « À facturer » and « À relancer ». */
final class FinanceTodo
{
    public static function register(): void
    {
        TodoSources::register('finance.to_invoice', 'Finance', 'Montants à facturer', Permissions::MANAGE,
            fn (User $u): int => count(app(Due::class)->forUser($u)['rows']),
            fn (User $u): string => '/finance/to-invoice');

        TodoSources::register('finance.reminders', 'Finance', 'Factures échues à relancer', Permissions::MANAGE,
            fn (User $u): int => count(app(Reminders::class)->due($u)),
            fn (User $u): string => '/finance/reminders');
    }
}
