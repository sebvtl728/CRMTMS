<?php

namespace App\Modules\Connectors\Ai;

use App\Exceptions\UserFacingHttpException;

/**
 * The AI answered, but its answer is unusable: not JSON, not an object,
 * not matching the requested schema (type, required field, length, range),
 * truncated or refused. Nothing from it may be shown or filled in.
 */
final class AiInvalidResponse extends UserFacingHttpException
{
    public const MESSAGE = 'La réponse de l\'intelligence artificielle est inutilisable. Réessayez, ou remplissez la fiche manuellement.';

    public function __construct()
    {
        parent::__construct(502, self::MESSAGE);
    }
}
