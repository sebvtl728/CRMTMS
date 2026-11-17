<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Core\Tenancy\SetTenantForJob;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Crm\Activities\CalendarEvent;
use App\Modules\Crm\Activities\CalendarEvents;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Brings the Outlook copies of one CRM action in line with its CURRENT
 * state (never a snapshot): replays and out-of-order runs converge.
 *
 * The event exists, in the calendar of its responsible only, when the
 * action is a dated meeting or call he can see on a live fiche and he has
 * an active link; any other copy (previous responsible, action no longer
 * qualifying) is deleted. One run at a time per action (WithoutOverlapping);
 * the creation carries a transactionId remembered beforehand, so a replay
 * after a crash does not duplicate the event.
 *
 * Journaled in connector_runs (no personal data: action id and outcome).
 * Microsoft busy or down: released, after its Retry-After when given.
 */
class PushActivityToOutlook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    /** Backoff (seconds) when Microsoft gives no Retry-After, by attempt. */
    public const BACKOFF = [30, 120, 600, 1800, 3600];

    /**
     * @param  string|null  $rootUrl  no longer used (jobs queued before 0.3.0 carry it): the address is the tenant's
     */
    public function __construct(public string $activityId, public ?string $rootUrl = null)
    {
        $this->captureTenant();
    }

    /**
     * Releases (overlap, throttling) do not use up the job: it gives up
     * after a day.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SetTenantForJob,
            (new WithoutOverlapping("tenant:{$this->tenantId}:outlook:activity:{$this->activityId}"))->releaseAfter(10)->expireAfter(300),
        ];
    }

    public function handle(GraphClient $graph): void
    {
        $config = Microsoft365Connector::activeConfig();
        if ($config === null) {
            return;
        }

        $event = $this->currentEvent();
        $target = $event === null ? null : CalendarLink::usableFor($event->responsibleUserId);
        /** @var Collection<int, CalendarLinkEvent> $copies */
        $copies = CalendarLinkEvent::query()->of(CalendarLinkEvent::SOURCE_ACTIVITY, $this->activityId)->with('link')->get();
        $stale = $copies->filter(fn (CalendarLinkEvent $c) => $c->calendar_link_id !== $target?->id && $c->link?->isActive());

        if ($target === null && $stale->isEmpty()) {
            return;
        }

        $retry = null;
        ConnectorRun::track($config, 'out', function () use ($graph, $config, $event, $target, $copies, $stale, &$retry): string {
            try {
                $done = [];
                foreach ($stale as $copy) {
                    if ($copy->outlook_event_id !== null) {
                        $graph->deleteEvent($config, $copy->link, $copy->outlook_event_id);
                    }
                    $copy->delete();
                    $done[] = 'supprimé';
                }
                if ($target !== null) {
                    $done[] = $this->upsert($graph, $config, $target, $event, $copies->firstWhere('calendar_link_id', $target->id));
                }

                return 'Action '.$this->activityId.' : évènement Outlook '.implode(', ', $done).'.';
            } catch (GraphRetryLater $e) {
                $retry = $e;

                throw $e;
            }
        }, payloadRef: 'activity:'.$this->activityId);

        /** @var GraphRetryLater|null $retry set by the closure */
        if ($retry !== null) {
            $this->release($retry->retryAfter ?? self::BACKOFF[min(max($this->attempts(), 1), count(self::BACKOFF)) - 1]);
        }
    }

    private function currentEvent(): ?CalendarEvent
    {
        // Links to the fiche on the organisme's address, not the worker's.
        $root = TenantUrl::root();
        URL::forceRootUrl($root);
        URL::forceScheme((string) parse_url($root, PHP_URL_SCHEME));

        try {
            return CalendarEvents::find($this->activityId);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    private function upsert(GraphClient $graph, ConnectorConfig $config, CalendarLink $link, CalendarEvent $event, ?CalendarLinkEvent $copy): string
    {
        $copy ??= CalendarLinkEvent::query()->create([
            'calendar_link_id' => $link->id,
            'source' => CalendarLinkEvent::SOURCE_ACTIVITY,
            'source_id' => $this->activityId,
            'transaction_id' => (string) Str::uuid7(),
        ]);

        if ($copy->outlook_event_id !== null) {
            if ($graph->updateEvent($config, $link, $copy->outlook_event_id, OutlookEvent::payload($event))) {
                return 'mis à jour';
            }
            // Deleted in Outlook: created again (new transaction).
            $copy->forceFill(['outlook_event_id' => null, 'transaction_id' => (string) Str::uuid7()])->save();
            $created = 'recréé';
        }

        $copy->forceFill(['outlook_event_id' => $graph->createEvent($config, $link, OutlookEvent::payload($event, $copy->transaction_id))])->save();

        return $created ?? 'créé';
    }
}
