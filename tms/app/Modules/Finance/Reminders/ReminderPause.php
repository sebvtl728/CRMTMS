<?php

namespace App\Modules\Finance\Reminders;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * « Suspendre les relances » of an invoice (dispute, payment plan agreed):
 * one row per invoice; « Reprendre » deletes it (spec 0.9.1 § 4.3).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $reason
 * @property string|null $paused_by
 * @property Carbon|null $created_at
 */
class ReminderPause extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'invoice_reminder_pause';

    protected $table = 'invoice_reminder_pauses';

    /** @var list<string> */
    protected array $auditPersonal = ['reason'];

    /** @var list<string> */
    protected $fillable = ['invoice_id', 'reason', 'paused_by'];
}
