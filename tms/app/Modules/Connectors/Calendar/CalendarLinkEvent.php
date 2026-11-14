<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Outlook event of a CRM action in the calendar of one link. Created
 * before the event (transaction_id, sent to Graph, makes a replayed
 * creation idempotent); outlook_event_id is set once Graph answered.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $calendar_link_id
 * @property string $activity_id
 * @property string $transaction_id
 * @property string|null $outlook_event_id
 * @property-read CalendarLink|null $link
 */
class CalendarLinkEvent extends Model
{
    use BelongsToTenant, HasUuids;

    /** Exact instants with their offset. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['calendar_link_id', 'activity_id', 'transaction_id', 'outlook_event_id'];

    /**
     * @return BelongsTo<CalendarLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(CalendarLink::class, 'calendar_link_id');
    }
}
