<?php

namespace App\Modules\Core\Signatures\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PDF was signed online (after commit): the module owning the subject
 * (Documents for « document », Crm for « quote ») updates it.
 */
final class SignatureCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $requestId,
        public readonly CarbonImmutable $signedAt,
    ) {}
}
