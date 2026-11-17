<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the invoice journal (spec 0.9 § 4.4): written once by
 * InvoiceValidator for each validated invoice or credit note, never
 * changed (append-only, PostgreSQL trigger). position orders the chain of
 * the organisme (1, 2, …); hash = SHA-256 of the invoice's canonical
 * content followed by previous_hash (null for the first: '').
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $position
 * @property string $number
 * @property string $kind
 * @property Carbon $issued_on
 * @property int $total_ht_cents
 * @property int $total_vat_cents
 * @property int $total_ttc_cents
 * @property string $hash
 * @property string|null $previous_hash
 * @property string|null $pdf_sha256 SHA-256 of the archived PDF
 * @property Carbon|null $created_at
 */
class InvoiceJournalEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'invoice_journal';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'position', 'number', 'kind', 'issued_on',
        'total_ht_cents', 'total_vat_cents', 'total_ttc_cents', 'hash', 'previous_hash', 'pdf_sha256',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'issued_on' => 'date',
            'total_ht_cents' => 'integer',
            'total_vat_cents' => 'integer',
            'total_ttc_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
