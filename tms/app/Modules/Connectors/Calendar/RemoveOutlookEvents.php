<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Core\Tenancy\SetTenantForJob;
use App\Modules\Core\Tenancy\TenantAwareJob;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

/**
 * « Déconnecter Outlook »: removes from the user's calendar the events the
 * CRM sent, with the tokens still valid, by batches of BATCH (the next
 * batch is queued), then drops the tokens. Best effort: a copy Microsoft
 * refuses to delete is kept, so that a reconnection updates it rather than
 * creating it again. Stops as soon as the user connected again.
 *
 * Journaled in connector_runs (link id and counts only).
 */
class RemoveOutlookEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const BATCH = 200;

    /**
     * @param  string|null  $after  id of the last copy handled by the previous batch
     */
    public function __construct(public string $linkId, public ?string $after = null)
    {
        $this->captureTenant();
    }

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
            (new WithoutOverlapping("tenant:{$this->tenantId}:outlook:link:{$this->linkId}"))->releaseAfter(10)->expireAfter(600),
        ];
    }

    public function handle(GraphClient $graph): void
    {
        $link = CalendarLink::query()->find($this->linkId);
        if ($link === null || $link->status !== CalendarLink::DISCONNECTED) {
            return;
        }
        $config = Microsoft365Connector::activeConfig();
        $copies = $link->events()->whereNotNull('outlook_event_id')
            ->when($this->after !== null, fn ($q) => $q->where('id', '>', $this->after))
            ->orderBy('id')->limit(self::BATCH)->get();

        if ($config === null || ! $link->canCall() || $copies->isEmpty()) {
            $link->dropTokens();

            return;
        }

        $retry = null;
        $last = $this->after;
        $stop = false;
        ConnectorRun::track($config, 'out', function () use ($graph, $config, $link, $copies, &$retry, &$last, &$stop): string {
            $removed = 0;
            $kept = 0;
            foreach ($copies as $copy) {
                $link->refresh();
                if ($link->status !== CalendarLink::DISCONNECTED) {
                    $stop = true;
                    break;
                }
                try {
                    $graph->deleteEvent($config, $link, (string) $copy->outlook_event_id);
                    $copy->delete();
                    $removed++;
                } catch (GraphRetryLater $e) {
                    $retry = $e;
                    break;
                } catch (OutlookReconnectRequired) {
                    $stop = true;
                    break;
                } catch (RuntimeException) {
                    $kept++;
                }
                $last = $copy->id;
            }

            return "Déconnexion Outlook : {$removed} évènement(s) retiré(s), {$kept} conservé(s).";
        }, payloadRef: 'calendar_link:'.$link->id);

        /** @var GraphRetryLater|null $retry set by the closure */
        if ($retry !== null) {
            $this->release($retry->retryAfter ?? PushActivityToOutlook::BACKOFF[min(max($this->attempts(), 1), count(PushActivityToOutlook::BACKOFF)) - 1]);

            return;
        }

        $link->refresh();
        if ($link->status !== CalendarLink::DISCONNECTED) {
            return;
        }
        if (! $stop && $copies->count() === self::BATCH) {
            self::dispatch($link->id, $last);

            return;
        }
        $link->dropTokens();
    }
}
