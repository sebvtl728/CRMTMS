<?php

namespace Tests\Support;

use App\Modules\Connectors\Ai\AnthropicClient;
use App\Modules\Connectors\Core\ConnectorConfig;

/**
 * Offline AI of the end-to-end stack (compose.e2e.yaml, TMS_E2E_FAKE_AI):
 * answers a fixed training sheet whatever it is asked, without any network
 * call. Bound by ConnectorsServiceProvider in place of the Anthropic client,
 * never in production (and absent from the production image: tests/ is
 * autoload-dev). Ai::structured still checks the answer against the schema
 * and journals the call.
 */
class E2eAiClient extends AnthropicClient
{
    public const TITLE = 'Accueil client en hôtellerie (proposition IA)';

    public function structured(ConnectorConfig $config, string $system, string $user, array $schema): array
    {
        return [
            'title' => self::TITLE,
            'das' => 'Hôtellerie-restauration',
            'duration_hours' => 14,
            'duration_days' => 2,
            'centre_hours' => null,
            'company_hours' => null,
            'price_company' => null,
            'price_individual' => null,
            'description' => 'Réussir l’accueil des clients à la réception d’un hôtel.',
            'objectives' => ['Accueillir un client avec professionnalisme', 'Traiter une réclamation'],
            'audience' => 'Réceptionnistes et agents d’accueil.',
            'prerequisites' => 'Aucun prérequis.',
            'methods' => 'Mises en situation filmées.',
            'evaluation' => 'Jeu de rôle final.',
            'sanction' => 'Attestation de fin de formation.',
            'accessibility' => null,
            'access_terms' => null,
            'results' => null,
            'modules' => [
                ['title' => 'Les codes de l’accueil', 'duration_hours' => 7, 'modality' => 'Présentiel', 'content' => 'Posture, langage, premières secondes.'],
                ['title' => 'Gérer les réclamations', 'duration_hours' => 7, 'modality' => 'Inconnue', 'content' => null],
            ],
        ];
    }

    public function model(ConnectorConfig $config): string
    {
        return 'e2e-fake';
    }
}
