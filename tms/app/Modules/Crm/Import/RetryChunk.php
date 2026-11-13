<?php

namespace App\Modules\Crm\Import;

use RuntimeException;

/**
 * A row failed while its chunk ran without per-row savepoints: the chunk is
 * rolled back and replayed row by row (RunImport::chunk()).
 */
class RetryChunk extends RuntimeException {}
