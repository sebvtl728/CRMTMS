<?php

namespace App\Modules\Connectors\Calendar;

use RuntimeException;

/**
 * Microsoft is busy or down (429, 5xx, timeout): try again after
 * $retryAfter seconds (its Retry-After when it gave one). The message
 * holds no personal data.
 */
final class GraphRetryLater extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
