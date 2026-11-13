<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person listed « À anonymiser » (ids and date only). Bookkeeping, never
 * journaled (gdpr.kept and gdpr.anonymised tell the story); written in bulk.
 *
 * @property string $person_id
 * @property string $tenant_id
 * @property Carbon $listed_at
 */
class GdprPending extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'gdpr_pending';

    protected $primaryKey = 'person_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected array $auditIgnore = ['person_id', 'tenant_id', 'listed_at'];

    /** @var list<string> */
    protected $fillable = ['person_id', 'listed_at'];

    protected function casts(): array
    {
        return ['listed_at' => 'datetime'];
    }

    public function plannedAt(): Carbon
    {
        return $this->listed_at->copy()->addDays(AnonymiseExpired::DELAY_DAYS);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }
}
