<?php

namespace App\Modules\Connectors\Ai;

use App\Exceptions\UserFacingHttpException;

/**
 * The AI cannot answer: not configured, refused credentials or request,
 * refused address, busy, down or too slow. The message is a fixed French
 * text safe to show (never the provider's own text); $retryable tells a
 * passing outage (429, 5xx, timeout) from a configuration problem.
 */
final class AiUnavailable extends UserFacingHttpException
{
    public const NOT_CONFIGURED = 'L\'assistant IA n\'est pas configuré. Demandez à l\'administrateur d\'activer le connecteur « Intelligence artificielle ».';

    public const CREDENTIALS = 'La clé d\'API de l\'intelligence artificielle a été refusée. Demandez à l\'administrateur de vérifier le connecteur.';

    public const REJECTED = 'L\'intelligence artificielle a refusé la demande (modèle ou paramètres incorrects). Demandez à l\'administrateur de vérifier le connecteur.';

    public const HOST_REFUSED = 'L\'adresse du serveur d\'intelligence artificielle n\'est pas autorisée. Demandez à l\'administrateur de vérifier le connecteur.';

    public const BUSY = 'L\'intelligence artificielle est saturée pour le moment. Réessayez dans quelques minutes.';

    public const UNREACHABLE = 'L\'intelligence artificielle est injoignable ou trop lente pour le moment. Réessayez dans quelques minutes.';

    private function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct(503, $message);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, false);
    }

    public static function credentials(): self
    {
        return new self(self::CREDENTIALS, false);
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED, false);
    }

    /**
     * The server refuses the configured model (unknown name, or not allowed
     * for this key). The model name comes from the connector settings, never
     * from the provider's answer.
     */
    public static function modelRefused(string $model): self
    {
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $model)), 0, 100);

        return new self('Le modèle « '.$name.' » n\'est pas disponible sur le serveur d\'intelligence artificielle '
            .'(nom incorrect, majuscules comprises, ou modèle non autorisé pour cette clé). '
            .'Demandez à l\'administrateur de vérifier le nom du modèle dans le connecteur.', false);
    }

    public static function hostRefused(): self
    {
        return new self(self::HOST_REFUSED, false);
    }

    public static function busy(): self
    {
        return new self(self::BUSY, true);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE, true);
    }
}
