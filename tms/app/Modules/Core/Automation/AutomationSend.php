<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An automatic send claimed once (AutomationSends::claim).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $subject_id
 * @property string $occurrence
 * @property CarbonImmutable $sent_at
 */
class AutomationSend extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['kind', 'subject_id', 'occurrence', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
