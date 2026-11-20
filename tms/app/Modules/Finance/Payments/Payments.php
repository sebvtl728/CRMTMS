<?php

namespace App\Modules\Finance\Payments;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\Money;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records, changes and deletes payments and their allocations (spec 0.9.1
 * § 4.1), each in one transaction. Locks: the payment row (change,
 * deletion), then the invoices allocated, ordered by id; their remaining
 * due is read again under these locks (two payments racing for the same
 * invoice: the second is refused). An allocation settles an issued invoice
 * (never a credit note nor a draft) of the payer and the company of the
 * payment, at most its remaining due; the allocations of a payment never
 * exceed it.
 */
final class Payments
{
    public const NOT_PAYABLE = 'Seule une facture validée peut recevoir un règlement.';

    public const OTHER_PAYER = 'Cette facture n\'est pas adressée à ce payeur.';

    public const OTHER_COMPANY = 'Cette facture a été émise par une autre société.';

    public const OVER_AMOUNT = 'La somme affectée dépasse le montant du règlement.';

    public const FUTURE = 'La date du règlement ne peut pas être dans le futur.';

    public const MAX_ALLOCATIONS = 200;

    public function __construct(private readonly AuditRecorder $audit, private readonly Fiches $fiches) {}

    /**
     * @param  array<string, mixed>  $data  payer_type, payer_id, company_id, received_on, amount, method_id, reference, notes, allocations: [{invoice_id, amount}]
     *
     * @throws ValidationException
     */
    public function record(User $by, array $data): Payment
    {
        $values = $this->validated($data);

        return DB::transaction(function () use ($by, $values): Payment {
            $this->checkAllocations($values, null);
            $payment = Payment::query()->create([
                ...$values['payment'], 'created_by' => $by->id, 'updated_by' => $by->id,
            ]);
            $this->writeAllocations($payment, $values['allocations'], $by);
            $this->audit->record('payment.recorded', $payment, [], ['amount_cents' => $payment->amount_cents, 'allocations' => $this->summary($values['allocations'])], $by);

            return $payment;
        });
    }

    /**
     * @param  array<string, mixed>  $data  the keys of record(); allocations replace the set
     *
     * @throws ValidationException
     */
    public function update(User $by, Payment $payment, array $data): Payment
    {
        $values = $this->validated($data);

        return DB::transaction(function () use ($by, $payment, $values): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $before = $this->summary($locked->allocations()->get(['invoice_id', 'amount_cents'])->map(fn (PaymentAllocation $a) => ['invoice_id' => $a->invoice_id, 'cents' => $a->amount_cents])->all());
            $this->checkAllocations($values, $locked->id);

            $locked->fill([...$values['payment'], 'updated_by' => $by->id])->save();
            $locked->allocations()->delete();
            $this->writeAllocations($locked, $values['allocations'], $by);
            $this->audit->record('payment.updated', $locked, ['allocations' => $before], ['amount_cents' => $locked->amount_cents, 'allocations' => $this->summary($values['allocations'])], $by);

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    public function delete(User $by, Payment $payment): void
    {
        DB::transaction(function () use ($by, $payment): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $allocations = $locked->allocations()->get(['invoice_id', 'amount_cents'])->map(fn (PaymentAllocation $a) => ['invoice_id' => $a->invoice_id, 'cents' => $a->amount_cents])->all();
            $locked->allocations()->delete();
            $this->audit->record('payment.deleted', $locked, ['amount_cents' => $locked->amount_cents, 'allocations' => $this->summary($allocations)], [], $by);
            $locked->delete();
        });
    }

    /**
     * The invoices of a payer still to settle (issued, remaining > 0),
     * oldest due date first; $companyId null: every company.
     *
     * @return list<array{id: string, number: string, issued_on: ?string, due_on: ?string, ttc_cents: int, remaining_cents: int, dossier_number: string, company_id: string}>
     */
    public function openInvoices(string $payerType, string $payerId, ?string $companyId = null): array
    {
        $invoices = Invoice::query()
            ->where('recipient_type', $payerType)->where('recipient_id', $payerId)
            ->where('kind', Invoice::INVOICE)
            ->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->when($companyId, fn ($q, string $id) => $q->where('company_id', $id))
            ->whereRaw(Balances::remainingSql().' > 0')
            ->orderBy('due_on')->orderBy('number')
            ->limit(self::MAX_ALLOCATIONS)
            ->get(['id', 'number', 'issued_on', 'due_on', 'total_ttc_cents', 'dossier_id', 'company_id']);
        $balances = Balances::of($invoices->pluck('id')->all());
        $numbers = Billing::numbers($invoices->pluck('dossier_id')->unique()->values()->all());

        return $invoices->map(fn (Invoice $i) => [
            'id' => $i->id,
            'number' => (string) $i->number,
            'issued_on' => $i->issued_on?->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'ttc_cents' => $i->total_ttc_cents,
            'remaining_cents' => $balances[$i->id]['remaining_cents'] ?? 0,
            'dossier_number' => $numbers[$i->dossier_id]['number'] ?? '-',
            'company_id' => $i->company_id,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment: array<string, mixed>, allocations: list<array{invoice_id: string, cents: int, index: int}>}
     *
     * @throws ValidationException
     */
    private function validated(array $data): array
    {
        $amount = Money::parse($data['amount'] ?? null);
        $allocations = is_array($data['allocations'] ?? null) ? array_values($data['allocations']) : [];
        $today = Balances::today();

        $validator = Validator::make([...$data, 'allocations' => $allocations], [
            'payer_type' => ['required', Rule::in(['organisation', 'person'])],
            'payer_id' => ['required', 'uuid'],
            'company_id' => ['required', 'uuid', Rule::exists(Company::class, 'id')->whereNull('deleted_at')],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'method_id' => ['required', 'uuid', Rule::exists('reference_items', 'id')->where('list', ReferenceList::PaymentMethod->value)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['array', 'max:'.self::MAX_ALLOCATIONS],
            'allocations.*' => ['array'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required'],
        ], [
            'payer_type.*' => 'Choisissez le payeur.',
            'payer_id.*' => 'Choisissez le payeur.',
            'company_id.*' => 'Choisissez la société qui a reçu le règlement.',
            'received_on.*' => 'Indiquez la date du règlement.',
            'method_id.*' => 'Choisissez le mode de règlement.',
            'reference.max' => 'La référence ne peut pas dépasser 100 caractères.',
            'notes.max' => 'Les notes ne peuvent pas dépasser 2 000 caractères.',
            'allocations.max' => 'Un règlement se répartit sur '.self::MAX_ALLOCATIONS.' factures au plus.',
            'allocations.*.invoice_id.distinct' => 'Une facture ne figure qu\'une fois dans la répartition.',
            'allocations.*.invoice_id.*' => 'Choisissez la facture.',
            'allocations.*.amount.required' => 'Indiquez le montant affecté.',
        ]);
        $validator->after(function ($v) use ($data, $amount, $allocations, $today): void {
            if ($amount === null || $amount <= 0) {
                $v->errors()->add('amount', 'Indiquez un montant positif (ex. 1 200,50).');
            }
            if (! $v->errors()->has('received_on') && is_string($data['received_on'] ?? null) && $data['received_on'] > $today) {
                $v->errors()->add('received_on', self::FUTURE);
            }
            if (! $v->errors()->has('payer_id') && ! $v->errors()->has('payer_type') && ! $this->payerExists((string) $data['payer_type'], (string) $data['payer_id'])) {
                $v->errors()->add('payer_id', 'Choisissez le payeur.');
            }
            $sum = 0;
            foreach ($allocations as $i => $allocation) {
                $cents = is_array($allocation) ? Money::parse($allocation['amount'] ?? null) : null;
                if ($cents === null || $cents <= 0) {
                    $v->errors()->add("allocations.{$i}.amount", 'Indiquez un montant affecté positif (ex. 120,50).');
                } else {
                    $sum += $cents;
                }
            }
            if ($amount !== null && $amount > 0 && $sum > $amount) {
                $v->errors()->add('allocations', self::OVER_AMOUNT);
            }
        });
        $validator->validate();

        return [
            'payment' => [
                'payer_type' => $data['payer_type'],
                'payer_id' => $data['payer_id'],
                'company_id' => $data['company_id'],
                'received_on' => $data['received_on'],
                'amount_cents' => (int) $amount,
                'method_id' => $data['method_id'],
                'reference' => self::text($data['reference'] ?? null),
                'notes' => self::text($data['notes'] ?? null),
            ],
            'allocations' => array_map(fn (array $a, int $i) => ['invoice_id' => (string) $a['invoice_id'], 'cents' => (int) Money::parse($a['amount']), 'index' => $i], $allocations, array_keys($allocations)),
        ];
    }

    /**
     * Under the locks of the invoices (by id): each allocation settles an
     * issued invoice of the payer and the company, within its remaining due
     * (this payment's own previous allocations not counted).
     *
     * @param  array{payment: array<string, mixed>, allocations: list<array{invoice_id: string, cents: int, index: int}>}  $values
     *
     * @throws ValidationException
     */
    private function checkAllocations(array $values, ?string $paymentId): void
    {
        $ids = array_column($values['allocations'], 'invoice_id');
        if ($ids === []) {
            return;
        }
        sort($ids);
        $invoices = Invoice::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $balances = Balances::of($ids);
        $own = $paymentId === null ? [] : PaymentAllocation::query()->where('payment_id', $paymentId)->pluck('amount_cents', 'invoice_id')->all();

        $errors = [];
        foreach ($values['allocations'] as $a) {
            $key = 'allocations.'.$a['index'];
            $invoice = $invoices[$a['invoice_id']] ?? null;
            if ($invoice === null || $invoice->kind !== Invoice::INVOICE || $invoice->isDraft()) {
                $errors[$key.'.invoice_id'] = self::NOT_PAYABLE;
            } elseif ($invoice->recipient_type !== $values['payment']['payer_type'] || $invoice->recipient_id !== $values['payment']['payer_id']) {
                $errors[$key.'.invoice_id'] = self::OTHER_PAYER;
            } elseif ($invoice->company_id !== $values['payment']['company_id']) {
                $errors[$key.'.invoice_id'] = self::OTHER_COMPANY;
            } else {
                $remaining = ($balances[$invoice->id]['remaining_cents'] ?? 0) + (int) ($own[$invoice->id] ?? 0);
                if ($a['cents'] > $remaining) {
                    $errors[$key.'.amount'] = 'Le montant affecté dépasse le reste dû de la facture ('.Money::format(max(0, $remaining)).').';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<array{invoice_id: string, cents: int, index?: int}>  $allocations
     */
    private function writeAllocations(Payment $payment, array $allocations, User $by): void
    {
        foreach ($allocations as $a) {
            PaymentAllocation::query()->create(['payment_id' => $payment->id, 'invoice_id' => $a['invoice_id'], 'amount_cents' => $a['cents'], 'created_by' => $by->id]);
        }
    }

    /**
     * @param  list<array{invoice_id: string, cents: int}>  $allocations
     * @return list<array{invoice_id: string, amount_cents: int}>
     */
    private function summary(array $allocations): array
    {
        return array_map(fn (array $a) => ['invoice_id' => $a['invoice_id'], 'amount_cents' => $a['cents']], $allocations);
    }

    private function payerExists(string $type, string $id): bool
    {
        return $type === 'organisation'
            ? $this->fiches->organisationLabels([$id]) !== []
            : $this->fiches->personLabels([$id]) !== [];
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === '' ? null : $text;
    }
}
