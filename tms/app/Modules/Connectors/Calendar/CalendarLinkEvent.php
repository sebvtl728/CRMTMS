<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Outlook event of one item of a source (a CRM action: SOURCE_ACTIVITY;
 * another module's item: OutlookSources) in the calendar of one link.
 * Created before the event (transaction_id, sent to Graph, makes a replayed
 * creation idempotent); outlook_event_id is set once Graph answered.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $calendar_link_id
 * @property string $source « crm.activity », « sessions.seance »…
 * @property string $source_id id of the item in its module (no foreign key)
 * @property string $transaction_id
 * @property string|null $outlook_event_id
 * @property-read CalendarLink|null $link
 */
class CalendarLinkEvent extends Model
{
    use BelongsToTenant, HasUuids;

    /** The source of the CRM actions (PushActivityToOutlook). */
    public const SOURCE_ACTIVITY = 'crm.activity';

    /** Exact instants with their offset. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['calendar_link_id', 'source', 'source_id', 'transaction_id', 'outlook_event_id'];

    /**
     * The copies of one item of a source.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOf(Builder $query, string $source, string $id): Builder
    {
        return $query->where('source', $source)->where('source_id', $id);
    }

    /**
     * @return BelongsTo<CalendarLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(CalendarLink::class, 'calendar_link_id');
    }
}
