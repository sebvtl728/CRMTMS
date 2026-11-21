<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an invoice (written by InvoiceDrafts): computed from the
 * dossier (component training, dedit, absences, or an informative mention
 * at 0) or free (never counted in what was invoiced to the recipient).
 * Amounts in integer cents HT; negative on a credit note.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $position from 0
 * @property string $kind computed|free
 * @property string|null $component training|dedit|absences|mention (computed lines)
 * @property string $label
 * @property string|null $description the trainees on a funder's invoice (personal)
 * @property string $quantity numeric(10,2) as a string
 * @property string $unit
 * @property int $unit_price_cents
 * @property string $vat_rate numeric(4,2) (« 20.00 »)
 * @property int $total_ht_cents round(quantity × unit price)
 * @property int|null $computed_cents what the computation proposed
 * @property int|null $planned_minutes
 * @property int|null $realised_minutes
 * @property string|null $dossier_line_id
 * @property string|null $enrolment_id
 * @property string|null $funding_id
 * @property string|null $cotraitant_id the co-contractor (organisation fiche) who delivered the line
 */
class InvoiceLine extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'invoice_line';

    public const COMPUTED = 'computed';

    public const FREE = 'free';

    public const TRAINING = 'training';

    public const DEDIT = 'dedit';

    public const ABSENCES = 'absences';

    public const MENTION = 'mention';

    /** Components counted in « already invoiced ». */
    public const BILLED = [self::TRAINING, self::DEDIT, self::ABSENCES];

    /**
     * Free text that may name a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['description'];

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'position', 'kind', 'component', 'label', 'description', 'quantity', 'unit', 'unit_price_cents',
        'vat_rate', 'total_ht_cents', 'computed_cents', 'planned_minutes', 'realised_minutes', 'dossier_line_id', 'enrolment_id', 'funding_id',
        'cotraitant_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'vat_rate' => 'decimal:2',
            'total_ht_cents' => 'integer',
            'computed_cents' => 'integer',
            'planned_minutes' => 'integer',
            'realised_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
