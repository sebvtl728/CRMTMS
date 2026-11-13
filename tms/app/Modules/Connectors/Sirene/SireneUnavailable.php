<?php

namespace App\Modules\Connectors\Sirene;

use RuntimeException;

/**
 * The company directory cannot answer (down, disabled, misconfigured, or
 * the entreprise is not diffusible: code 404). The message is French and
 * safe to show to the user.
 */
final class SireneUnavailable extends RuntimeException
{
    public const NOT_DIFFUSIBLE = 'Entreprise non diffusible : saisissez les informations manuellement';

    public const MESSAGE = 'Service de l\'annuaire indisponible, saisissez les informations manuellement.';

    public static function generic(): self
    {
        return new self(self::MESSAGE);
    }

    /** Not an outage: the entreprise refused the publication of its data. */
    public static function notDiffusible(): self
    {
        return new self(self::NOT_DIFFUSIBLE, 404);
    }

    public static function disabled(): self
    {
        return new self('Le préremplissage par SIRET est désactivé pour votre organisme.');
    }
}
