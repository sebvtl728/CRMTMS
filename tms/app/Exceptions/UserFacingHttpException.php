<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An HTTP error whose message is written in French for the end user: the
 * error pages display it. Any other HttpException (framework, throttling,
 * authorisation...) shows the fixed French text of its status code, never
 * its own message.
 */
class UserFacingHttpException extends HttpException
{
    public function __construct(int $statusCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $previous);
    }
}
