<?php

namespace App\Modules\Finance\Reminders;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceSender;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dunning (spec 0.9.1 § 4.3). An invoice is to remind when it is issued,
 * not settled, past its due date, not paused, and its next level is
 * reached: level n is due n-th delay days after the due date, once level
 * n-1 has LEFT (a failed reminder does not count, the same level comes
 * again; a pending one blocks). Past the last level: overdue, no more
 * reminders. A reminder is a sending of the invoice (InvoiceSending
 * « reminder », its level) through InvoiceSender and SendInvoice.
 *
 * @phpstan-type Due array{invoice: Invoice, level: int, days_late: int, remaining_cents: int, email: ?string, last: ?array{level: int, at: string}}
 */
final class Reminders
{
    public const NOT_DUE = 'Cette facture n\'est pas à relancer.';

    public const NO_EMAIL = 'Le destinataire n\'a pas d\'adresse e-mail.';

    /** At most this many invoices on the page (oldest due first). */
    public const LIMIT = 500;

    public function __construct(private readonly InvoiceSender $sender) {}

    /**
     * The invoices to remind now, in the scope of finance.invoices.manage
     * of $user (null: the whole organisme), oldest due date first.
     *
     * @return list<Due>
     */
    public function due(?User $user): array
    {
        $invoices = $this->candidates()
            ->when($user, fn (Builder $q, User $u) => $q->whereIn('dossier_id', Billing::dossiersFor($u, [], Permissions::MANAGE)))
            ->orderBy('due_on')->orderBy('number')->limit(self::LIMIT)->get();

        return array_values(array_filter($this->assess($invoices)));
    }

    /** The level to send now, null when nothing is due. */
    public function nextLevel(Invoice $invoice): ?int
    {
        $invoice = $this->candidates()->whereKey($invoice->id)->first();

        return $invoice === null ? null : ($this->assess(new Collection([$invoice]))[0]['level'] ?? null);
    }

    /**
     * Sends the reminder due now: the message typed, or the level's template.
     *
     * @param  array{to: string, subject: string, body: string}|null  $message
     *
     * @throws UserFacingHttpException 409 not to remind, no address, a sending under way
     */
    public function send(?User $by, Invoice $invoice, ?array $message = null): InvoiceSending
    {
        $level = $this->nextLevel($invoice) ?? throw new UserFacingHttpException(409, self::NOT_DUE);
        $message ??= ReminderMail::defaults($invoice, $level);
        if (trim($message['to']) === '') {
            throw new UserFacingHttpException(409, self::NO_EMAIL);
        }

        // Read again under the lock of the invoice: paid or paused meanwhile, nothing leaves.
        return $this->sender->queue($by, $invoice, $message, $level, function (Invoice $locked) use ($level): void {
            if ($this->nextLevel($locked) !== $level) {
                throw new UserFacingHttpException(409, self::NOT_DUE);
            }
        });
    }

    /** « Suspendre les relances » (dispute, payment plan): journaled. */
    public function pause(User $by, Invoice $invoice, ?string $reason): void
    {
        DB::transaction(function () use ($by, $invoice, $reason): void {
            Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (ReminderPause::query()->where('invoice_id', $invoice->id)->exists()) {
                return;
            }
            $reason = is_string($reason) ? trim(mb_substr($reason, 0, 1000)) : null;
            ReminderPause::query()->create(['invoice_id' => $invoice->id, 'reason' => $reason === '' ? null : $reason, 'paused_by' => $by->id]);
        });
    }

    /** « Reprendre les relances ». */
    public function resume(User $by, Invoice $invoice): void
    {
        ReminderPause::query()->where('invoice_id', $invoice->id)->get()->each(fn (ReminderPause $p) => $p->delete());
    }

    public static function paused(Invoice $invoice): ?ReminderPause
    {
        return ReminderPause::query()->where('invoice_id', $invoice->id)->first();
    }

    /**
     * Issued invoices, not settled, past due, not paused.
     *
     * @return Builder<Invoice>
     */
    private function candidates(): Builder
    {
        return Invoice::query()
            ->where('kind', Invoice::INVOICE)
            ->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->whereNotNull('due_on')
            ->where('due_on', '<', Balances::today())
            ->whereRaw(Balances::remainingSql().' > 0')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_reminder_pauses as rp')->whereColumn('rp.invoice_id', 'invoices.id'));
    }

    /**
     * The level due of each invoice (null: none), in the order given.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return list<Due|null>
     */
    private function assess(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }
        $ids = $invoices->pluck('id')->all();
        $days = FinanceSettings::reminderDays();
        $today = CarbonImmutable::parse(Balances::today(), 'Europe/Paris');
        $balances = Balances::of($ids);
        $sendings = InvoiceSending::query()->whereIn('invoice_id', $ids)->where('purpose', InvoiceSending::REMINDER)
            ->orderBy('created_at')->get(['invoice_id', 'status', 'reminder_level', 'sent_at', 'created_at'])->groupBy('invoice_id');

        return $invoices->map(function (Invoice $invoice) use ($days, $today, $balances, $sendings): ?array {
            $mine = $sendings[$invoice->id] ?? new Collection;
            if ($mine->contains('status', 'pending') || $invoice->due_on === null) {
                return null;
            }
            $sent = $mine->where('status', 'sent');
            $level = (int) ($sent->max('reminder_level') ?? 0) + 1;
            $daysLate = (int) CarbonImmutable::parse($invoice->due_on->toDateString(), 'Europe/Paris')->diffInDays($today);
            if ($level > count($days) || $daysLate < $days[$level - 1]) {
                return null;
            }
            $last = $sent->sortByDesc('reminder_level')->first();

            return [
                'invoice' => $invoice,
                'level' => $level,
                'days_late' => $daysLate,
                'remaining_cents' => $balances[$invoice->id]['remaining_cents'] ?? 0,
                'email' => ($email = trim((string) ($invoice->recipient_snapshot['email'] ?? ''))) === '' ? null : $email,
                'last' => $last === null ? null : ['level' => (int) $last->reminder_level, 'at' => ($last->sent_at ?? $last->created_at)?->toIso8601String() ?? ''],
            ];
        })->values()->all();
    }
}
