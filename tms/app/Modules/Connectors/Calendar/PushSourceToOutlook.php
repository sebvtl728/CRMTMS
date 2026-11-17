<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Core\Tenancy\SetTenantForJob;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantUrl;
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
 * Brings the Outlook copies of one item of a registered source
 * (OutlookSources) in line with its CURRENT state, as PushActivityToOutlook
 * does for the CRM actions: replays and out-of-order runs converge.
 *
 * The event exists in the calendar of the item's recipient only, when he
 * has an active link (active account); any other copy (previous recipient,
 * item gone) is deleted. One run at a time per item (WithoutOverlapping);
 * the creation carries a transactionId remembered beforehand, so a replay
 * after a crash does not duplicate the event.
 *
 * Journaled in connector_runs (no personal data: source, id and outcome).
 * Microsoft busy or down: released, after its Retry-After when given.
 */
class PushSourceToOutlook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(public string $source, public string $id)
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
            (new WithoutOverlapping("tenant:{$this->tenantId}:outlook:{$this->source}:{$this->id}"))->releaseAfter(10)->expireAfter(300),
        ];
    }

    public function handle(GraphClient $graph): void
    {
        $config = Microsoft365Connector::activeConfig();
        if ($config === null) {
            return;
        }

        $item = $this->currentItem();
        $target = $item === null ? null : CalendarLink::activeFor($item->userId);
        /** @var Collection<int, CalendarLinkEvent> $copies */
        $copies = CalendarLinkEvent::query()->of($this->source, $this->id)->with('link')->get();
        $stale = $copies->filter(fn (CalendarLinkEvent $c) => $c->calendar_link_id !== $target?->id && $c->link?->isActive());

        if ($target === null && $stale->isEmpty()) {
            return;
        }

        $retry = null;
        ConnectorRun::track($config, 'out', function () use ($graph, $config, $item, $target, $copies, $stale, &$retry): string {
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
                    $done[] = $this->upsert($graph, $config, $target, $item, $copies->firstWhere('calendar_link_id', $target->id));
                }

                return 'Élément '.$this->source.' '.$this->id.' : évènement Outlook '.implode(', ', $done).'.';
            } catch (GraphRetryLater $e) {
                $retry = $e;

                throw $e;
            }
        }, payloadRef: $this->source.':'.$this->id);

        /** @var GraphRetryLater|null $retry set by the closure */
        if ($retry !== null) {
            $backoff = PushActivityToOutlook::BACKOFF;
            $this->release($retry->retryAfter ?? $backoff[min(max($this->attempts(), 1), count($backoff)) - 1]);
        }
    }

    private function currentItem(): ?OutlookItem
    {
        // Links on the organisme's address, not the worker's.
        $root = TenantUrl::root();
        URL::forceRootUrl($root);
        URL::forceScheme((string) parse_url($root, PHP_URL_SCHEME));

        try {
            return OutlookSources::find($this->source, $this->id);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    private function upsert(GraphClient $graph, ConnectorConfig $config, CalendarLink $link, OutlookItem $item, ?CalendarLinkEvent $copy): string
    {
        $copy ??= CalendarLinkEvent::query()->create([
            'calendar_link_id' => $link->id,
            'source' => $this->source,
            'source_id' => $this->id,
            'transaction_id' => (string) Str::uuid7(),
        ]);

        if ($copy->outlook_event_id !== null) {
            if ($graph->updateEvent($config, $link, $copy->outlook_event_id, OutlookEvent::fromItem($item))) {
                return 'mis à jour';
            }
            // Deleted in Outlook: created again (new transaction).
            $copy->forceFill(['outlook_event_id' => null, 'transaction_id' => (string) Str::uuid7()])->save();
            $created = 'recréé';
        }

        $copy->forceFill(['outlook_event_id' => $graph->createEvent($config, $link, OutlookEvent::fromItem($item, $copy->transaction_id))])->save();

        return $created ?? 'créé';
    }
}
