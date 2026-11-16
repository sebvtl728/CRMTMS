<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\HasFormFields;
use App\Modules\Connectors\Core\HasFormLabels;
use App\Modules\Connectors\Core\OrganismeWide;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * « Intelligence artificielle »: the AI that proposes content (training
 * sheets...). One configuration for the whole organisme, admin only
 * (connectors.manage). Providers: Anthropic (key, model) or any
 * OpenAI-compatible server (base address « …/v1 », model, optional key,
 * box « Serveur sur le réseau local » for a private / loopback server).
 * Business modules call it through the Ai service only. See
 * docs/connectors.md.
 */
class AiConnector implements Connector, HasFormFields, HasFormLabels, OrganismeWide
{
    public function __construct(private readonly Ai $ai) {}

    public static function key(): string
    {
        return 'ai';
    }

    public static function label(): string
    {
        return 'Intelligence artificielle';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Assistant;
    }

    /**
     * The organisme's configuration when it can be used: enabled, a known
     * provider, and complete (Anthropic: a key; OpenAI-compatible: a valid
     * address and a model).
     */
    public static function activeConfig(): ?ConnectorConfig
    {
        $config = ConnectorConfig::query()->where('connector_key', self::key())->whereNull('company_id')->where('enabled', true)->first();
        if ($config === null) {
            return null;
        }
        $settings = $config->settings;

        return match (AiProvider::tryFrom((string) ($settings['provider'] ?? ''))) {
            AiProvider::Anthropic => $config->secret('api_key') !== null ? $config : null,
            AiProvider::OpenAiCompatible => is_string($settings['base_url'] ?? null) && AiBaseUrl::parse($settings['base_url']) !== null
                && is_string($settings['model'] ?? null) && trim($settings['model']) !== '' ? $config : null,
            null => null,
        };
    }

    public function settingsRules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::enum(AiProvider::class)],
            'model' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._:\/@+-]+$/', 'required_if:settings.provider,'.AiProvider::OpenAiCompatible->value],
            'base_url' => ['nullable', 'string', 'max:255', 'required_if:settings.provider,'.AiProvider::OpenAiCompatible->value, new AiBaseUrl],
            'local_network' => ['nullable', 'boolean'],
        ];
    }

    public function secretKeys(): array
    {
        return ['api_key'];
    }

    public function labels(): array
    {
        return [
            'provider' => 'Fournisseur',
            'model' => 'Modèle',
            'base_url' => 'Adresse de base de l\'API (…/v1)',
            'local_network' => 'Serveur sur le réseau local',
            'api_key' => 'Clé d\'API',
        ];
    }

    public function fields(): array
    {
        $openAi = ['provider' => [AiProvider::OpenAiCompatible->value]];

        return [
            'provider' => [
                'type' => 'select',
                'default' => AiProvider::Anthropic->value,
                'options' => array_map(fn (AiProvider $p) => ['value' => $p->value, 'label' => $p->label()], AiProvider::cases()),
            ],
            'model' => [
                'type' => 'text',
                'suggestions' => AnthropicClient::SUGGESTED_MODELS,
                'help' => 'Anthropic : laisser vide pour '.AnthropicClient::DEFAULT_MODEL.' ou choisir un autre modèle. Compatible OpenAI : obligatoire (ex. gpt-4.1, mistral-large-latest, llama3.1:8b).',
            ],
            'base_url' => [
                'type' => 'text',
                'help' => 'Ex. https://api.openai.com/v1, https://api.mistral.ai/v1 ou http://192.168.1.20:11434/v1 (serveur local).',
                'visible_when' => $openAi,
            ],
            'local_network' => [
                'type' => 'checkbox',
                'default' => false,
                'warning' => 'À cocher seulement pour une IA installée sur le réseau local de l\'organisme (adresse privée ou cette machine). Le TMS pourra alors joindre cette adresse et ce port précis, en http si besoin : vérifiez qu\'il s\'agit bien de votre serveur d\'IA. Les contenus envoyés à l\'IA (programmes de formation) y transitent.',
                'visible_when' => $openAi,
            ],
            'api_key' => [
                'type' => 'text',
                'help' => 'Obligatoire pour Anthropic ; facultative pour un serveur compatible OpenAI qui n\'en demande pas.',
            ],
        ];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        try {
            $provider = AiProvider::tryFrom((string) ($c->settings['provider'] ?? '')) ?? throw AiUnavailable::notConfigured();
            $client = $this->ai->client($provider);
            $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false];
            AiSchema::conform($client->structured($c, 'Tu vérifies une connexion. Réponds uniquement par l\'objet JSON demandé.', 'Réponds {"ok": true}.', $schema), $schema);

            return new ConnectionTestResult(true, 'Intelligence artificielle joignable (modèle '.$client->model($c).').');
        } catch (AiUnavailable|AiInvalidResponse $e) {
            return new ConnectionTestResult(false, $e->getMessage());
        } catch (Throwable) {
            return new ConnectionTestResult(false, 'Échec inattendu lors du test de l\'intelligence artificielle. Vérifiez les paramètres.');
        }
    }
}
