<?php

namespace App\Modules\Connectors\Core;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Journal of connector executions (imports, exports, tests, deliveries).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_config_id
 * @property string $direction in|out
 * @property string $status pending|success|failed
 * @property string|null $summary
 * @property string|null $error
 * @property string|null $payload_ref opaque reference letting the connector replay the run
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class ConnectorRun extends Model
{
    use BelongsToTenant, HasUuids;

    public const MAX_ERROR_LENGTH = 2000;

    /** @var list<string> */
    protected $fillable = ['connector_config_id', 'direction', 'status', 'summary', 'error', 'payload_ref', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * Runs $fn and journals it: pending at start, then success (the returned
     * string becomes the summary) or failed (the exception is captured, its
     * message truncated and stripped of the configuration's secrets). The
     * exception is NOT rethrown: inspect the returned run.
     *
     * @param  callable(): (string|null)  $fn
     */
    public static function track(ConnectorConfig $c, string $direction, callable $fn, ?string $payloadRef = null): self
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException("Sens invalide : {$direction} (in|out).");
        }

        $run = self::create([
            'connector_config_id' => $c->id,
            'direction' => $direction,
            'status' => 'pending',
            'payload_ref' => $payloadRef,
            'started_at' => now(),
        ]);

        try {
            $summary = $fn();
            $run->fill(['status' => 'success', 'summary' => is_string($summary) ? $summary : null]);
        } catch (Throwable $e) {
            $run->fill(['status' => 'failed', 'error' => self::sanitise($e->getMessage(), $c)]);
        }

        $run->finished_at = now();
        $run->save();

        return $run;
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    private static function sanitise(string $message, ConnectorConfig $c): string
    {
        foreach ($c->secrets ?? [] as $secret) {
            if (mb_strlen($secret) >= 4) {
                $message = str_replace($secret, ConnectorConfig::AUDIT_MASK, $message);
            }
        }

        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @return BelongsTo<ConnectorConfig, $this>
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(ConnectorConfig::class, 'connector_config_id');
    }
}
