<?php

namespace Tests\Concerns;

use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ToolCallResult;
use Mockery;
use Mockery\MockInterface;

/**
 * Replaces ClaudeClient with a mock that returns fixture classifications.
 * Tests never call the real Anthropic API.
 */
trait FakesClaude
{
    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/classifications/{$name}.json")), true);
    }

    protected function toolResult(string|array $fixture, string $model = 'claude-haiku-5-5'): ToolCallResult
    {
        return new ToolCallResult(
            input: is_array($fixture) ? $fixture : $this->fixture($fixture),
            model: $model,
            inputTokens: 900,
            outputTokens: 150,
            stopReason: 'tool_use',
        );
    }

    /**
     * @param  list<string|array|ToolCallResult>  $responses  Returned in order by callTool().
     */
    protected function fakeClaude(array $responses): MockInterface
    {
        $mock = Mockery::mock(ClaudeClient::class);
        $mock->shouldReceive('callTool')
            ->times(count($responses))
            ->andReturnValues(array_map(
                fn ($r) => $r instanceof ToolCallResult ? $r : $this->toolResult($r),
                $responses,
            ));

        $this->app->instance(ClaudeClient::class, $mock);

        return $mock;
    }
}
