<?php

namespace App\Modules\Finance\Payments;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The part of a payment that settles one invoice (once per pair), written
 * by Payments only (journaled there, with the payment).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property int $amount_cents
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class PaymentAllocation extends Model
{
    use BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'payment_allocation';

    /** @var list<string> */
    protected $fillable = ['payment_id', 'invoice_id', 'amount_cents', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
