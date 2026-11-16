<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectorConfig;

/**
 * OpenAI-compatible chat completions (POST {base_url}/chat/completions):
 * OpenAI, Mistral, a local server (Ollama, vLLM, LM Studio...).
 *
 * The answer is requested with response_format json_schema (strict); a
 * server that rejects it (400 / 422) is asked once more with json_object,
 * the schema then given in the system message. The message content is
 * decoded as JSON (a Markdown fence is tolerated). Authorization: Bearer
 * only when a key is saved.
 */
class OpenAiCompatibleClient extends AiClient
{
    public function model(ConnectorConfig $config): string
    {
        $model = $config->settings['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : throw AiUnavailable::notConfigured();
    }

    public function structured(ConnectorConfig $config, string $system, string $user, array $schema): array
    {
        $endpoint = AiEndpoint::fromConfig($config);
        $url = $endpoint->baseUrl.'/chat/completions';
        $key = $config->secret('api_key');
        $headers = $key === null ? [] : ['Authorization' => 'Bearer '.$key];
        $sendable = AiSchema::sendable($schema);
        $body = [
            'model' => $this->model($config),
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ];

        $response = $this->post($endpoint, $url, $headers, [
            ...$body,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'reponse', 'schema' => $sendable, 'strict' => true]],
        ]);

        if (in_array($response->status(), [400, 422], true)) {
            $body['messages'][0]['content'] = $system."\n\nRéponds uniquement par un objet JSON conforme à ce schéma JSON :\n"
                .json_encode($sendable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = $this->post($endpoint, $url, $headers, [...$body, 'response_format' => ['type' => 'json_object']]);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $response->json('choices.0.finish_reason') === 'length') {
            throw new AiInvalidResponse;
        }

        return self::decodeObject($content);
    }
}
