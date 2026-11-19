<?php

namespace App\Modules\Finance\Reminders;

use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Finance\Invoices\FinanceMailTemplates;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\Money;
use App\Modules\Finance\Payments\Balances;
use Carbon\CarbonImmutable;

/**
 * The reminder e-mail of a level, from the organisme's template: the
 * recipient's e-mail frozen at validation, the remaining due, the days
 * late; the late payment penalties and the 40 € fixed indemnity only for a
 * professional (an organisation), never for a particulier.
 */
final class ReminderMail
{
    public const PENALTIES = "\n\nConformément à nos conditions de paiement, tout retard de paiement entraîne l'application de pénalités de retard et d'une indemnité forfaitaire de 40 € pour frais de recouvrement (articles L441-10 et D441-5 du Code de commerce).";

    /**
     * @return array{to: string, subject: string, body: string}
     */
    public static function defaults(Invoice $invoice, int $level): array
    {
        $snapshot = $invoice->recipient_snapshot ?? [];
        $balance = Balances::one($invoice);
        $today = CarbonImmutable::parse(Balances::today(), 'Europe/Paris');
        $daysLate = $invoice->due_on === null ? 0 : max(0, (int) CarbonImmutable::parse($invoice->due_on->toDateString(), 'Europe/Paris')->diffInDays($today));
        $text = app(MailTemplates::class)->render(FinanceMailTemplates::reminder(max(1, min(3, $level))), [
            'numero' => (string) $invoice->number,
            'societe' => $invoice->company->name ?? '',
            'destinataire' => (string) ($snapshot['name'] ?? ''),
            'montant_ttc' => Money::format($invoice->total_ttc_cents),
            'reste_du' => Money::format(max(0, $balance['remaining_cents'] ?? $invoice->total_ttc_cents)),
            'echeance' => $invoice->due_on?->format('d/m/Y') ?? '',
            'jours_retard' => (string) $daysLate,
            'niveau' => (string) $level,
            'penalites' => $invoice->recipient_type === 'organisation' ? self::PENALTIES : '',
            'organisme' => app(TenantContext::class)->current()->name ?? '',
        ]);

        return ['to' => (string) ($snapshot['email'] ?? ''), 'subject' => $text['subject'], 'body' => $text['body']];
    }
}
