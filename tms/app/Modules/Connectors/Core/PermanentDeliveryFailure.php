<?php

namespace App\Modules\Connectors\Core;

/**
 * Marker for a connector's mail failure that retrying cannot fix (refused
 * credentials, incomplete configuration): the message is a fixed French text
 * safe to show, and the e-mail is marked « Échec » at once. Callers test this
 * interface, never a connector's own exception class.
 */
interface PermanentDeliveryFailure {}
