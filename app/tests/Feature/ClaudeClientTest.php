<?php

namespace Tests\Feature;

use App\Services\Ai\ClassificationSchema;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ClaudeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ClaudeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['leadradar.ai.api_key' => 'test-key']);
        Sleep::fake();
    }

    private function toolUseResponse(array $input = ['intent' => 'other'], string $model = 'claude-haiku-5-5'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => ClassificationSchema::TOOL_NAME, 'input' => $input],
            ],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 120],
        ];
    }

    private function callClaude(string $model = 'claude-haiku-5-5')
    {
        return app(ClaudeClient::class)->callTool($model, 'system prompt', 'user message', ClassificationSchema::tool(), 'low', 4000);
    }

    public function test_request_shape_and_result(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolUseResponse(['intent' => 'tenant_demand']))]);

        $result = $this->callClaude();

        $this->assertSame(['intent' => 'tenant_demand'], $result->input);
        $this->assertSame(1000, $result->inputTokens);
        $this->assertSame(120, $result->outputTokens);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2026-10-21')
                && ! $request->hasHeader('anthropic-beta')
                && $body['model'] === 'claude-haiku-5-5'
                && $body['tools'][0]['name'] === 'record_classification'
                && $body['tools'][0]['strict'] === true
                && $body['tool_choice'] === ['type' => 'auto', 'disable_parallel_tool_use' => true]
                && $body['output_config'] === ['effort' => 'low']
                && ! array_key_exists('temperature', $body)
                && ! array_key_exists('fallbacks', $body);
        });
    }

    public function test_sonnet_gets_server_side_fallback(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->toolUseResponse(model: 'claude-sonnet-5-5'))]);

        $this->callClaude('claude-sonnet-5-5');

        Http::assertSent(fn (Request $r) => $r->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $r->data()['fallbacks'] === 'default');
    }

    public function test_retries_429_honouring_retry_after(): void
    {
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429, ['retry-after' => '7'])
            ->push(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'boom']], 500)
            ->push($this->toolUseResponse());

        $this->callClaude();

        Http::assertSentCount(3);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 7, 1);
    }

    public function test_gives_up_after_three_retries(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        try {
            $this->callClaude();
            $this->fail('Expected ClaudeException');
        } catch (ClaudeException $e) {
            $this->assertSame(529, $e->getCode());
        }

        Http::assertSentCount(4);
    }

    public function test_400_is_not_retried(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid request']], 400)]);

        $this->expectException(ClaudeException::class);
        $this->expectExceptionMessage('invalid request');

        try {
            $this->callClaude();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_retries_once_when_model_answers_without_tool(): void
    {
        $text = ['model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];

        Http::fakeSequence('api.anthropic.com/*')->push($text)->push($this->toolUseResponse());

        $result = $this->callClaude();

        $this->assertSame(1010, $result->inputTokens);
        Http::assertSentCount(2);
    }

    public function test_refusal_throws(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
            'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ])]);

        $this->expectException(ClaudeException::class);
        $this->callClaude();
    }

    public function test_missing_api_key_fails_without_request(): void
    {
        config(['leadradar.ai.api_key' => '']);
        Http::fake();

        $this->expectException(ClaudeException::class);

        try {
            $this->callClaude();
        } finally {
            Http::assertNothingSent();
        }
    }
}
