<?php

namespace App\Modules\Connectors\Mail365;

use App\Modules\Connectors\Core\PermanentDeliveryFailure;
use RuntimeException;

/**
 * Microsoft 365 refused the e-mail for good (401 / 403 / 404, the
 * application credentials, an incomplete configuration): sending it again
 * would fail the same way, the e-mail is marked « Échec » at once. The
 * message is a fixed French text written here, never Microsoft's; $status is
 * the HTTP status (0 when no call was made). Retryable failures (429, 5xx,
 * timeouts) raise Calendar\GraphRetryLater instead.
 */
final class GraphMailFailed extends RuntimeException implements PermanentDeliveryFailure
{
    public const REFUSED = 'Microsoft 365 a refusé l\'envoi : vérifiez la permission Mail.Send et l\'adresse d\'expédition.';

    public const APP_REFUSED = 'Microsoft 365 a refusé l\'application : vérifiez l\'identifiant et le secret client (secret expiré ?).';

    public const UNKNOWN_UPLOAD = 'Microsoft 365 a proposé une adresse de dépôt non reconnue. Réessayez plus tard ou contactez le support.';

    public const INCOMPLETE = 'La configuration Microsoft 365 (envoi des e-mails) est incomplète.';

    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    public static function rejected(int $status): self
    {
        return in_array($status, [401, 403, 404], true)
            ? new self(self::REFUSED, $status)
            : new self("Microsoft 365 a refusé le message (HTTP {$status}).", $status);
    }
}
