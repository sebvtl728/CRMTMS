<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An invoice or a credit note of a dossier (spec 0.9 § 4.4), written by
 * InvoiceDrafts (drafts) and the validation. Amounts in integer cents HT;
 * a credit note's totals are negative. The recipient is the contractor of
 * the dossier (funding_id null) or the funder of a funding paying directly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $kind invoice|credit_note
 * @property string|null $number set at validation
 * @property InvoiceStatus $status
 * @property string|null $dossier_id null for a free invoice
 * @property string $origin dossier|free
 * @property string|null $buyer_reference the buyer's engagement / order number (free invoice)
 * @property string|null $service_code the buyer's service code (free invoice)
 * @property string $recipient_type organisation|person
 * @property string $recipient_id
 * @property string|null $funding_id the funder's funding; null: the contractor
 * @property string $recipient_key « contractor » or the funding id (generated)
 * @property array<string, mixed>|null $recipient_snapshot frozen at validation
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property string|null $cut_off « 2026-09 » (monthly) or a day; null: a draft made by hand with nothing due
 * @property string $rhythm end|monthly|schedule at preparation
 * @property string $basis realised|planned at preparation
 * @property int $total_ht_cents
 * @property int $total_vat_cents
 * @property int $total_ttc_cents
 * @property string|null $vat_mention
 * @property string|null $corrected_invoice_id the invoice a credit note corrects
 * @property string|null $notes internal notes (personal)
 * @property string|null $pdf_path
 * @property string|null $pdf_sha256 SHA-256 of the archived PDF (validated)
 * @property string|null $hash
 * @property string|null $previous_hash
 * @property Carbon|null $sent_at
 * @property string|null $email_status
 * @property string|null $email_error_code
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $validated_by
 * @property Carbon|null $validated_at
 * @property Carbon|null $created_at
 */
class Invoice extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'invoice';

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    /** An invoice of a dossier (computed from what is due). */
    public const ORIGIN_DOSSIER = 'dossier';

    /** An invoice created by hand, without dossier (free lines only). */
    public const ORIGIN_FREE = 'free';

    /** recipient_key of the contractor of the dossier. */
    public const CONTRACTOR = 'contractor';

    /**
     * Free text and copies that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['notes', 'recipient_snapshot'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by', 'email_status', 'email_error_code', 'recipient_key'];

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'kind', 'dossier_id', 'recipient_type', 'recipient_id', 'funding_id',
        'period_from', 'period_to', 'cut_off', 'rhythm', 'basis', 'vat_mention', 'corrected_invoice_id', 'notes',
        'total_ht_cents', 'total_vat_cents', 'total_ttc_cents', 'created_by', 'updated_by',
        'origin', 'buyer_reference', 'service_code', 'is_advance',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'origin' => 'dossier',
        'total_ht_cents' => 0,
        'total_vat_cents' => 0,
        'total_ttc_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'is_advance' => 'boolean',
            'recipient_snapshot' => 'array',
            'issued_on' => 'date',
            'due_on' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'total_ht_cents' => 'integer',
            'total_vat_cents' => 'integer',
            'total_ttc_cents' => 'integer',
            'sent_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function isFree(): bool
    {
        return $this->origin === self::ORIGIN_FREE;
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function kindLabel(): string
    {
        return $this->kind === self::CREDIT_NOTE ? 'Avoir' : 'Facture';
    }

    /** « Facture F-2026-00001 », « Brouillon de facture ». */
    public function title(): string
    {
        return $this->number !== null
            ? $this->kindLabel().' '.$this->number
            : ($this->kind === self::CREDIT_NOTE ? 'Brouillon d\'avoir' : 'Brouillon de facture');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function corrected(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id');
    }
}
