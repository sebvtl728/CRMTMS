<?php

namespace App\Modules\Crm\Import;

/**
 * A row deliberately not imported (the fiche must not be touched): the
 * row is « ignorée » with this French message, not in error.
 */
class SkipRow extends ImportRowException {}
