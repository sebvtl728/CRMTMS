<?php

namespace App\Modules\Core\Documents;

use App\Exceptions\UserFacingHttpException;
use Throwable;

/**
 * The PDF converter is unreachable, too slow or answered an error: 503 with a
 * French message (shown by the error page). Never carries the document.
 */
class PdfRenderingFailed extends UserFacingHttpException
{
    public const MESSAGE = "Le PDF n'a pas pu être généré, réessayez dans un instant.";

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(503, self::MESSAGE, $previous);
    }
}
