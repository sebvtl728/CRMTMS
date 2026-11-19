<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;

/**
 * Public service of the AI connector: the only door business modules use.
 *
 *  - configured(): the organisme has an enabled and complete AI connector;
 *  - structured(): one request whose answer must be a JSON object matching
 *    $jsonSchema; returns it checked (AiSchema::conform) or throws
 *    AiUnavailable (fixed French message, ->retryable) / AiInvalidResponse.
 *
 * Each call is journaled in connector_runs: purpose, provider, model,
 * duration, user id and outcome - never the prompt nor the answer. The
 * caller applies the named limiter « ai » (20 requests an hour per user).
 */
class Ai
{
    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly OpenAiCompatibleClient $openAi,
    ) {}

    public function configured(): bool
    {
        return AiConnector::activeConfig() !== null;
    }

    /**
     * @param  array<string, mixed>  $jsonSchema  root type "object"
     * @param  string  $purpose  short machine name journaled (e.g. "training.from_pdf")
     * @param  int|null  $timeoutSeconds  longer wait for a background job (at most AiClient::MAX_TIMEOUT_SECONDS); never in a web request
     * @return array<string, mixed>
     *
     * @throws AiUnavailable|AiInvalidResponse
     */
    public function structured(string $system, string $user, array $jsonSchema, string $purpose, ?int $timeoutSeconds = null): array
    {
        if (! preg_match('/^[a-z0-9_.-]{1,64}$/', $purpose)) {
            throw new InvalidArgumentException('Purpose must be a short machine name ([a-z0-9_.-]).');
        }

        $config = AiConnector::activeConfig() ?? throw AiUnavailable::notConfigured();
        $provider = AiProvider::from((string) $config->settings['provider']);
        $client = $this->client($provider);
        $client->timeoutSeconds = max(1, min($timeoutSeconds ?? AiClient::TIMEOUT_SECONDS, AiClient::MAX_TIMEOUT_SECONDS));

        $run = ConnectorRun::create([
            'connector_config_id' => $config->id,
            'direction' => 'out',
            'status' => 'pending',
            'started_at' => now(),
        ]);
        $started = hrtime(true);
        $summary = fn (): string => sprintf('%s · %s · modèle %s · %d ms · utilisateur %s',
            $purpose, $provider->label(), self::safeModel($client, $config), intdiv(hrtime(true) - $started, 1_000_000), Auth::id() ?? 'système');

        try {
            $answer = AiSchema::conform($client->structured($config, $system, $user, $jsonSchema), $jsonSchema);
        } catch (AiUnavailable|AiInvalidResponse $e) {
            $run->fill(['status' => 'failed', 'summary' => $summary(), 'error' => $e->getMessage(), 'finished_at' => now()])->save();

            throw $e;
        } catch (Throwable) {
            $run->fill(['status' => 'failed', 'summary' => $summary(), 'error' => AiUnavailable::UNREACHABLE, 'finished_at' => now()])->save();

            throw AiUnavailable::unreachable();
        }

        $run->fill(['status' => 'success', 'summary' => $summary(), 'finished_at' => now()])->save();

        /** @var array<string, mixed> $answer */
        return $answer;
    }

    public function client(AiProvider $provider): AiClient
    {
        return match ($provider) {
            AiProvider::Anthropic => $this->anthropic,
            AiProvider::OpenAiCompatible => $this->openAi,
        };
    }

    private static function safeModel(AiClient $client, ConnectorConfig $config): string
    {
        try {
            return mb_substr($client->model($config), 0, 200);
        } catch (Throwable) {
            return '?';
        }
    }
}
