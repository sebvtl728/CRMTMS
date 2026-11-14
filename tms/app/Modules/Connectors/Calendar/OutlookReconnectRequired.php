<?php

namespace App\Modules\Connectors\Calendar;

use RuntimeException;

/**
 * Microsoft refused to refresh the tokens of a link (revoked consent,
 * changed password, expired refresh token): the link is « À reconnecter ».
 */
final class OutlookReconnectRequired extends RuntimeException
{
    public const MESSAGE = 'Lien Outlook à reconnecter : Microsoft a refusé le renouvellement de l\'accès.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
