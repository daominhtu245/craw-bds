<?php

namespace App\Services\Ai;

final class ToolCallResult
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly array $input,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason,
    ) {}
}
