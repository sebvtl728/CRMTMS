<?php

namespace App\Modules\Admissions\Positioning;

use RuntimeException;

/** A failed positioning e-mail: a fixed French reason, never the server's text. */
class PositioningMailFailed extends RuntimeException {}
