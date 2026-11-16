<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a dossier: a training of the catalogue (kind training, priced
 * per trainee or for the group) or a fee (travel, meals, flat fee: no
 * training, no session, counted in the price). Written by DossierWriter
 * only (total HT from Amounts::lineTotal()).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dossier_id
 * @property int $position
 * @property string $kind training|fee
 * @property string|null $training_id id of the catalogue (Catalogue\Trainings)
 * @property string|null $das_id the training's DAS; a fee: the dossier's
 * @property string $label
 * @property string $quantity numeric(8,2)
 * @property string $unit one of UNITS
 * @property string $unit_price numeric(12,2) HT
 * @property string $discount_percent numeric(5,2)
 * @property string $total_ht numeric(12,2)
 * @property string|null $pricing_mode per_trainee|group, null for a fee
 */
class DossierLine extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'dossier_line';

    public const TRAINING = 'training';

    public const FEE = 'fee';

    public const KINDS = [self::TRAINING => 'Formation', self::FEE => 'Frais'];

    public const PER_TRAINEE = 'per_trainee';

    public const GROUP = 'group';

    public const PRICING_MODES = [self::PER_TRAINEE => 'Prix par stagiaire', self::GROUP => 'Prix de groupe'];

    /** Units of a line (those of the quotes): value => French label. */
    public const UNITS = [
        'stagiaire' => 'stagiaire',
        'jour' => 'jour',
        'heure' => 'heure',
        'forfait' => 'forfait',
        'session' => 'session',
    ];

    protected $table = 'dossier_lines';

    /** @var list<string> */
    protected $fillable = [
        'dossier_id', 'position', 'kind', 'training_id', 'das_id', 'label', 'quantity', 'unit',
        'unit_price', 'discount_percent', 'total_ht', 'pricing_mode',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'total_ht' => 'decimal:2',
        ];
    }

    /**
     * Mode proposed from the unit of the line (spec § 10): « stagiaire »
     * → per trainee, any other unit → for the group.
     */
    public static function proposedPricingMode(string $unit): string
    {
        return $unit === 'stagiaire' ? self::PER_TRAINEE : self::GROUP;
    }

    public function isTraining(): bool
    {
        return $this->kind === self::TRAINING;
    }

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
