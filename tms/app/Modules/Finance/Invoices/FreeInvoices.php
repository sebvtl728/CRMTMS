<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Free invoices (spec 2026-11-20 facture libre et co-traitance): a draft
 * created by hand, without dossier, for any entreprise or person (the
 * buyer of a public market, a one-off service…), with free lines only
 * (InvoiceDrafts::update). Numbering, validation, PDF, sending, payments,
 * reminders and credit notes are those of every invoice.
 */
final class FreeInvoices
{
    public const NO_RECIPIENT = 'Choisissez le client à facturer.';

    public const NO_COMPANY = 'Choisissez la société qui émet la facture.';

    public const NOT_ALL = 'Une facture libre exige de voir toutes les factures (périmètre « Tout »).';

    public const RHYTHM = 'free';

    public function __construct(private readonly Fiches $fiches) {}

    /**
     * @param  array<string, mixed>  $data  company_id, recipient_type (organisation|person), recipient_id, buyer_reference?, service_code?, period_from?, period_to?
     *
     * @throws UserFacingHttpException 403 without finance.invoices.manage on « Tout »
     * @throws ValidationException
     */
    public function create(User $user, array $data): Invoice
    {
        if (! InvoiceAccess::all($user, Permissions::MANAGE)) {
            throw new UserFacingHttpException(403, self::NOT_ALL);
        }
        $values = self::header($data, true);
        $type = (string) ($data['recipient_type'] ?? '');
        $id = (string) ($data['recipient_id'] ?? '');
        $recipient = match ($type) {
            'organisation' => $this->fiches->organisation($id),
            'person' => $this->fiches->person($id),
            default => null,
        };
        if ($recipient === null) {
            throw ValidationException::withMessages(['recipient_id' => self::NO_RECIPIENT]);
        }
        $company = is_string($data['company_id'] ?? null) ? Company::query()->find($data['company_id']) : null;
        if ($company === null) {
            throw ValidationException::withMessages(['company_id' => self::NO_COMPANY]);
        }

        return DB::transaction(function () use ($user, $values, $type, $recipient, $company): Invoice {
            $invoice = Invoice::query()->create([
                ...$values,
                'company_id' => $company->id, 'kind' => Invoice::INVOICE, 'origin' => Invoice::ORIGIN_FREE,
                'recipient_type' => $type, 'recipient_id' => $recipient['id'],
                'rhythm' => self::RHYTHM, 'basis' => self::RHYTHM,
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            InvoiceTotals::apply($invoice, $company);
            $invoice->save();

            return $invoice;
        });
    }

    /** A free invoice is addressed to the fiche, or one of its lines names it as co-contractor. */
    public static function links(string $type, string $id): bool
    {
        $client = Invoice::query()->where('origin', Invoice::ORIGIN_FREE)->where('recipient_type', $type)->where('recipient_id', $id)->exists();

        return $client || ($type === 'organisation' && InvoiceLine::query()->where('cotraitant_id', $id)->exists());
    }

    /**
     * Merge of two fiches (called by the CRM's Merger in its transaction):
     * the free drafts and their lines follow the fiche kept; a validated
     * piece is frozen, it and its payouts keep the fiche merged.
     */
    public static function merge(string $type, string $keepId, string $dropId): void
    {
        $drafts = Invoice::query()->where('origin', Invoice::ORIGIN_FREE)->where('status', InvoiceStatus::Draft);
        (clone $drafts)->where('recipient_type', $type)->where('recipient_id', $dropId)->update(['recipient_id' => $keepId]);
        if ($type === 'organisation') {
            InvoiceLine::query()->where('cotraitant_id', $dropId)->whereIn('invoice_id', (clone $drafts)->select('id'))->update(['cotraitant_id' => $keepId]);
        }
    }

    /**
     * The header a free invoice may change: the buyer's references (Chorus
     * Pro) and the period invoiced; only the keys present ($all: every key).
     *
     * @param  array<string, mixed>  $data
     * @return array{buyer_reference?: ?string, service_code?: ?string, period_from?: ?string, period_to?: ?string}
     *
     * @throws ValidationException
     */
    public static function header(array $data, bool $all = false): array
    {
        $keys = ['buyer_reference', 'service_code', 'period_from', 'period_to'];
        $input = [];
        foreach ($keys as $key) {
            if ($all || array_key_exists($key, $data)) {
                $value = $data[$key] ?? null;
                $input[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }
        Validator::make($input, [
            'buyer_reference' => ['nullable', 'string', 'max:60'],
            'service_code' => ['nullable', 'string', 'max:60'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ], [
            'buyer_reference.max' => 'Le numéro d\'engagement ne peut pas dépasser 60 caractères.',
            'service_code.max' => 'Le code service ne peut pas dépasser 60 caractères.',
            'period_from.date_format' => 'Indiquez une date valide.',
            'period_to.date_format' => 'Indiquez une date valide.',
            'period_to.after_or_equal' => 'La fin de période ne peut pas précéder son début.',
        ])->validate();

        return $input;
    }
}
