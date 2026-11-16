<?php

namespace App\Modules\Crm\Brochures;

use RuntimeException;

/**
 * A brochure e-mail that did not leave, as reported by SendBrochure: a fixed
 * French reason and the SMTP reply code at most, never the transport's text
 * (it may quote the recipient's address or the SMTP account).
 */
final class BrochureMailFailed extends RuntimeException {}
