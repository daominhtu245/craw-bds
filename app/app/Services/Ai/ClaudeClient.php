<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Thin client for the Anthropic Messages API (POST /v1/messages) using the
 * Laravel HTTP client (SPEC 9.1).
 *
 * - Retries 429 / 5xx / connection errors up to `max_retries` times, honouring retry-after.
 * - Never sends sampling parameters (removed in API version 2026-10-21).
 * - Adds the server-side refusal fallback for models listed in `fallback_models`.
 */
class ClaudeClient
{
    /**
     * Ask the model to call exactly one tool and return its input.
     *
     * Forced tool_choice is rejected by some current models (e.g. claude-sonnet-5-5),
     * so we use tool_choice "auto" + strict schema + an explicit instruction, and
     * retry once if the model answers without calling the tool.
     *
     * @param  array<string, mixed>  $tool  {name, description, input_schema}
     */
    public function callTool(string $model, string $system, string $userMessage, array $tool, string $effort, int $maxTokens): ToolCallResult
    {
        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'tools' => [$tool + ['strict' => true]],
            'tool_choice' => ['type' => 'auto', 'disable_parallel_tool_use' => true],
            'output_config' => ['effort' => $effort],
        ];

        $inputTokens = 0;
        $outputTokens = 0;
        $last = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $last = $this->createMessage($payload);
            $inputTokens += (int) data_get($last, 'usage.input_tokens', 0);
            $outputTokens += (int) data_get($last, 'usage.output_tokens', 0);

            $stopReason = (string) data_get($last, 'stop_reason');
            if ($stopReason === 'refusal') {
                throw new ClaudeException('Model refused: '.data_get($last, 'stop_details.category', 'unknown'));
            }

            foreach ((array) data_get($last, 'content', []) as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $tool['name']) {
                    return new ToolCallResult(
                        input: (array) $block['input'],
                        model: (string) data_get($last, 'model', $model),
                        inputTokens: $inputTokens,
                        outputTokens: $outputTokens,
                        stopReason: $stopReason,
                    );
                }
            }

            Log::warning('ai.no_tool_call', ['model' => $model, 'stop_reason' => $stopReason, 'attempt' => $attempt]);
        }

        throw new ClaudeException('Model did not call tool '.$tool['name'].' (stop_reason: '.data_get($last, 'stop_reason').')');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> Decoded Message object.
     */
    public function createMessage(array $payload): array
    {
        $apiKey = (string) config('leadradar.ai.api_key');
        if ($apiKey === '') {
            throw new ClaudeException('ANTHROPIC_API_KEY is not configured.');
        }

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('leadradar.ai.version'),
        ];

        if (in_array($payload['model'], (array) config('leadradar.ai.fallback_models'), true)) {
            $headers['anthropic-beta'] = (string) config('leadradar.ai.fallback_beta');
            $payload['fallbacks'] = 'default';
        }

        $url = rtrim((string) config('leadradar.ai.base_url'), '/').'/v1/messages';
        $maxRetries = (int) config('leadradar.ai.max_retries');

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('leadradar.ai.timeout_seconds'))
                    ->post($url, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $maxRetries) {
                    throw new ClaudeException('Connection to Anthropic failed: '.$e->getMessage(), 0, $e);
                }
                Sleep::for($this->backoffSeconds($attempt))->seconds();

                continue;
            }

            if ($response->successful()) {
                return (array) $response->json();
            }

            if ($this->isRetryable($response) && $attempt < $maxRetries) {
                Sleep::for($this->retryAfterSeconds($response, $attempt))->seconds();

                continue;
            }

            throw new ClaudeException(sprintf(
                'Anthropic API error %d: %s',
                $response->status(),
                (string) data_get($response->json(), 'error.message', 'unknown error'),
            ), $response->status());
        }
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->status() === 529 || $response->serverError();
    }

    private function retryAfterSeconds(Response $response, int $attempt): int
    {
        $header = $response->header('retry-after');

        if (is_numeric($header)) {
            return max(1, min(60, (int) ceil((float) $header)));
        }

        return $this->backoffSeconds($attempt);
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(30, 2 ** $attempt);
    }
}
