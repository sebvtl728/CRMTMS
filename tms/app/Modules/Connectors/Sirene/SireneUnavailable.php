<?php

namespace App\Modules\Connectors\Sirene;

use RuntimeException;

/**
 * The company directory cannot answer (down, disabled, misconfigured). The
 * message is French and safe to show to the user.
 */
final class SireneUnavailable extends RuntimeException
{
    public const MESSAGE = 'Service de l\'annuaire indisponible, saisissez les informations manuellement.';

    public static function generic(): self
    {
        return new self(self::MESSAGE);
    }

    public static function disabled(): self
    {
        return new self('Le préremplissage par SIRET est désactivé pour votre organisme.');
    }
}
