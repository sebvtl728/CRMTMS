<?php

namespace App\Modules\Crm\Import;

use RuntimeException;

/**
 * A row that cannot be imported; the French message goes to the row report.
 */
class ImportRowException extends RuntimeException {}
