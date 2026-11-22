<?php

namespace App\Modules\Qualite\Registers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A complaint of the register (indicator 31), written by Registers only:
 * open → processing → closed (closed requires the answer and its date, CHECK).
 * Who complained and what may be personal: never journaled in clear.
 *
 * @property string $id
 * @property string $tenant_id
 * @property CarbonImmutable $received_on
 * @property string $channel_id
 * @property string $complainant
 * @property string $subject
 * @property string|null $description
 * @property string $status open|processing|closed
 * @property string|null $response
 * @property CarbonImmutable|null $responded_on
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Complaint extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'quality_complaint';

    public const STATUSES = ['open' => 'Ouverte', 'processing' => 'En cours', 'closed' => 'Close'];

    protected $table = 'quality_complaints';

    /** @var list<string> */
    protected array $auditPersonal = ['complainant', 'description', 'response'];

    /** @var list<string> */
    protected $fillable = ['received_on', 'channel_id', 'complainant', 'subject', 'description', 'status', 'response', 'responded_on', 'created_by'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date', 'responded_on' => 'immutable_date'];
    }
}
