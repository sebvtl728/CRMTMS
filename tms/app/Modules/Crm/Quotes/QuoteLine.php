<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a quote (written by QuoteWriter). The training is read through
 * Catalogue\Trainings only: its id is all this table holds, with its DAS
 * copied (turnover by DAS) and its title as the editable label.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $quote_id
 * @property int $position from 0
 * @property string|null $training_id
 * @property string|null $das_id
 * @property string $label
 * @property string|null $description
 * @property string $quantity numeric(8,2) as a string
 * @property string $unit one of UNITS
 * @property string $unit_price numeric(12,2) HT
 * @property string $discount_percent numeric(5,2)
 * @property string $vat_rate numeric(4,2) (« 5.50 »)
 * @property string $total_ht numeric(12,2), rounded (QuoteTotals)
 */
class QuoteLine extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** Units of a line (CHECK of quote_lines.unit), with their French label. */
    public const UNITS = [
        'stagiaire' => 'stagiaire',
        'jour' => 'jour',
        'heure' => 'heure',
        'forfait' => 'forfait',
        'session' => 'session',
    ];

    /**
     * Free text that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['description'];

    /** @var list<string> */
    protected $fillable = [
        'quote_id', 'position', 'training_id', 'das_id', 'label', 'description',
        'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate', 'total_ht',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'total_ht' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function das(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'das_id')->withTrashed();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function unitOptions(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::UNITS), self::UNITS);
    }
}
