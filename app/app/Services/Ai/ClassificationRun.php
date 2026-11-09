<?php

namespace App\Services\Ai;

final class ClassificationRun
{
    public function __construct(
        public readonly Classification $classification,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}
}
