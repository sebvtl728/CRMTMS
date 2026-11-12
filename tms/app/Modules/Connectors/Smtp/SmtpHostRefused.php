<?php

namespace App\Modules\Connectors\Smtp;

use RuntimeException;

/**
 * The SMTP host is unknown or forbidden by the network policy. The message
 * is French, written by us and safe to show to the administrator.
 */
final class SmtpHostRefused extends RuntimeException {}
