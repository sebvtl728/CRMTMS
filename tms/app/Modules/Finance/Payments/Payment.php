<?php

namespace App\Modules\Finance\Payments;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money received from a payer (the recipient of invoices: an organisation
 * or a particulier) for one issuing company (spec 0.9.1 § 4.1), written by
 * Payments. Its allocations settle invoices of that payer and company; the
 * rest is « not allocated ». Kept 10 years like the invoices: the
 * anonymisation of a person clears the notes and the reference only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payer_type organisation|person
 * @property string $payer_id
 * @property Carbon $received_on
 * @property int $amount_cents
 * @property string $method_id reference item of « payment_method »
 * @property string|null $reference cheque number, transfer label
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'payment';

    /** @var list<string> */
    protected array $auditPersonal = ['notes', 'reference'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['company_id', 'payer_type', 'payer_id', 'received_on', 'amount_cents', 'method_id', 'reference', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
