<?php

namespace App\Modules\Finance\Payouts;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payout of the mandataire to a co-contractor for its lines of an
 * invoice (spec 2026-11-20), written by CotraitantPayouts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $cotraitant_id an organisation fiche
 * @property int $amount_cents
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $created_by
 */
class CotraitantPayout extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'cotraitant_payout';

    /** @var list<string> */
    protected array $auditPersonal = ['notes'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    /** @var list<string> */
    protected $fillable = ['invoice_id', 'cotraitant_id', 'amount_cents', 'paid_on', 'reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount_cents' => 'integer',
        ];
    }
}
