<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectorConfig;

/**
 * Anthropic Messages API (POST https://api.anthropic.com/v1/messages).
 *
 * The structured answer is requested with output_config.format (JSON
 * schema), then read from the text blocks. A forced tool call
 * (tool_choice "tool") is not used: the current models refuse it (400).
 * A refusal or a truncated answer (stop_reason refusal / max_tokens) is
 * an AiInvalidResponse. No sampling parameter is sent (refused by the API
 * version used). Effort « low » (output_config.effort): the suggested
 * models accept it; a model typed by the administrator must too.
 */
class AnthropicClient extends AiClient
{
    public const BASE_URL = 'https://api.anthropic.com';

    public const API_VERSION = '2026-10-21';

    /** Default model; the administrator may type another one. */
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** Models proposed on the configuration screen (editable list). */
    public const SUGGESTED_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    /**
     * Ceiling of the answer, thinking included (thinking cannot be turned off
     * on the current models): higher than the other providers' bound.
     */
    public const MAX_OUTPUT_TOKENS = 32000;

    /** Structured extraction needs little reasoning: faster, cheaper, within the 120 s. */
    public const EFFORT = 'low';

    public function model(ConnectorConfig $config): string
    {
        $model = $config->settings['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;
    }

    public function structured(ConnectorConfig $config, string $system, string $user, array $schema): array
    {
        $key = $config->secret('api_key') ?? throw AiUnavailable::notConfigured();

        $response = $this->post(AiEndpoint::fromConfig($config), self::BASE_URL.'/v1/messages', [
            'x-api-key' => $key,
            'anthropic-version' => self::API_VERSION,
        ], [
            'model' => $this->model($config),
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['effort' => self::EFFORT, 'format' => ['type' => 'json_schema', 'schema' => AiSchema::sendable($schema)]],
        ]);

        if (! $response->successful()) {
            throw $this->failure($response);
        }
        if ($response->json('stop_reason') !== 'end_turn') {
            throw new AiInvalidResponse;
        }

        $text = '';
        foreach ((array) $response->json('content') as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return self::decodeObject($text);
    }
}
