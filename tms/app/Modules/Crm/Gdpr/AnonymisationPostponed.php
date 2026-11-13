<?php

namespace App\Modules\Crm\Gdpr;

use RuntimeException;

/**
 * An import is running, simulating or being undone in the organisme: it may
 * write the person's values while she is scrubbed. Try again once it ends.
 */
final class AnonymisationPostponed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Un import est en cours : l\'anonymisation sera possible à la fin de l\'import.');
    }
}
