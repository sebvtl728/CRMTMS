<?php

namespace App\Modules\Crm\Import;

use RuntimeException;

/**
 * A file that cannot be imported at all (format, encoding, empty). The
 * message is French and shown as is to the user.
 */
class ImportFileException extends RuntimeException {}
