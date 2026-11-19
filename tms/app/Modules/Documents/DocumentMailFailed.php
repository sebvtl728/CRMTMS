<?php

namespace App\Modules\Documents;

use RuntimeException;

/**
 * A document e-mail that did not leave: a fixed French reason and the SMTP
 * reply code at most, thrown instead of the transport's exception (whose
 * text may quote the address or the SMTP account).
 */
final class DocumentMailFailed extends RuntimeException {}
