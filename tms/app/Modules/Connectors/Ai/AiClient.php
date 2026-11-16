<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\HostPolicy;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One AI provider's protocol. Every call: the endpoint's network policy
 * (AiEndpoint::guard, connection pinned to the checked addresses),
 * TIMEOUT_SECONDS at most, redirects never followed, output bounded by
 * MAX_OUTPUT_TOKENS, response body by MAX_RESPONSE_BYTES. Failures become AiUnavailable / AiInvalidResponse with
 * fixed French messages: the provider's text never leaves this class.
 */
abstract class AiClient
{
    /** A local AI can be slow; a hung server must not hold a worker forever. */
    public const TIMEOUT_SECONDS = 120;

    public const CONNECT_TIMEOUT_SECONDS = 10;

    /** Upper bound of the answer (a training sheet with 30 modules fits). */
    public const MAX_OUTPUT_TOKENS = 16000;

    /** Largest response body read; a bigger one is cut off and refused. */
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    /**
     * The decoded JSON object answered for $schema (not yet checked against
     * it: Ai::structured does that).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws AiUnavailable|AiInvalidResponse
     */
    abstract public function structured(ConnectorConfig $config, string $system, string $user, array $schema): array;

    /** The model the configuration uses. */
    abstract public function model(ConnectorConfig $config): string;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     *
     * @throws AiUnavailable
     */
    protected function post(AiEndpoint $endpoint, string $url, array $headers, array $body): Response
    {
        $ips = $endpoint->guard($url);
        $tooLarge = false;

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->withOptions([...HostPolicy::pinned($url, $ips), 'progress' => self::sizeLimit($tooLarge)])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->post($url, $body);
        } catch (Throwable) {
            // A body cut off by sizeLimit() surfaces as a transfer error.
            throw $tooLarge ? new AiInvalidResponse : AiUnavailable::unreachable();
        }
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new AiInvalidResponse;
        }

        if ($response->redirect()) {
            throw AiUnavailable::hostRefused();
        }

        return $response;
    }

    /**
     * Guzzle progress callback aborting the transfer past MAX_RESPONSE_BYTES
     * (a misbehaving server must not fill the worker's memory).
     *
     * @return Closure(int, int, int, int): void
     */
    public static function sizeLimit(bool &$tooLarge): Closure
    {
        return function (int $downloadTotal, int $downloaded) use (&$tooLarge): void {
            if ($downloadTotal > self::MAX_RESPONSE_BYTES || $downloaded > self::MAX_RESPONSE_BYTES) {
                $tooLarge = true;

                throw new RuntimeException('AI response too large.');
            }
        };
    }

    /** The exception for an unsuccessful answer. */
    protected function failure(Response $response, string $model): AiUnavailable
    {
        return match (true) {
            self::refusesModel($response) => AiUnavailable::modelRefused($model),
            in_array($response->status(), [401, 403], true) => AiUnavailable::credentials(),
            $response->status() === 429 => AiUnavailable::busy(),
            $response->status() === 408, $response->serverError() => AiUnavailable::unreachable(),
            default => AiUnavailable::rejected(),
        };
    }

    /**
     * Whether a 4xx answer says the model itself is refused: OpenAI
     * `model_not_found`, LiteLLM `key_model_access_denied`, an error on the
     * `model` parameter, or Anthropic's `not_found_error` on the model. The
     * provider's text is only inspected here, never shown.
     */
    private static function refusesModel(Response $response): bool
    {
        if (! $response->clientError()) {
            return false;
        }
        $error = $response->json('error');
        if (! is_array($error)) {
            return false;
        }
        $type = is_string($error['type'] ?? null) ? $error['type'] : '';
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';

        return in_array($code, ['model_not_found', 'key_model_access_denied'], true)
            || $type === 'key_model_access_denied'
            || ($error['param'] ?? null) === 'model'
            || ($type === 'not_found_error' && str_starts_with($message, 'model:'));
    }

    /**
     * The JSON object in $text (a Markdown code fence around it is tolerated).
     *
     * @return array<string, mixed>
     *
     * @throws AiInvalidResponse
     */
    protected static function decodeObject(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }

        $decoded = json_decode($text, true, 64);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new AiInvalidResponse;
        }

        return $decoded;
    }
}
