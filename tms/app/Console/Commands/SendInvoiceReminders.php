<?php

namespace App\Console\Commands;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Reminders\Reminders;
use App\Modules\Finance\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every morning at 08:30 Paris time (routes/console.php): in each
 * organisme that turned automatic reminders on, the level due of every
 * invoice to remind leaves with its template, without author (spec 0.9.1
 * § 4.3). Never two reminders of an invoice the same day (a failed one
 * comes again the next morning). One organisme failing does not stop the
 * others.
 */
class SendInvoiceReminders extends Command
{
    protected $signature = 'tms:finance:remind';

    protected $description = 'Envoie les relances dues des factures impayées, pour chaque organisme qui a activé la relance automatique';

    public function handle(): int
    {
        $total = 0;
        $failed = ForEachTenant::run(function () use (&$total): void {
            if (! FinanceSettings::autoReminders()) {
                return;
            }
            $reminders = app(Reminders::class);
            $startOfDay = CarbonImmutable::parse(Balances::today(), 'Europe/Paris')->startOfDay();
            foreach ($reminders->due(null) as $due) {
                $today = InvoiceSending::query()->where('invoice_id', $due['invoice']->id)
                    ->where('purpose', InvoiceSending::REMINDER)->where('created_at', '>=', $startOfDay)->exists();
                if ($today || $due['email'] === null) {
                    continue;
                }
                try {
                    $reminders->send(null, $due['invoice']);
                    $total++;
                } catch (UserFacingHttpException) {
                    // Paid, paused or being sent meanwhile: nothing leaves.
                }
            }
        });

        $this->info("Relances envoyées : {$total}.");

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
