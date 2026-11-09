<?php

namespace App\Services\Prefilter;

use App\Enums\FilterReason;

/**
 * Result of the prefilter steps. `reason` is null when the item passes.
 */
final class TextAnalysis
{
    /**
     * @param  list<string>  $positiveMatches
     * @param  list<string>  $negativeMatches
     */
    public function __construct(
        public readonly ?FilterReason $reason,
        public readonly ?string $language,
        public readonly array $positiveMatches = [],
        public readonly array $negativeMatches = [],
        public readonly int $negativeScore = 0,
        public readonly bool $hasStrongPositive = false,
        public readonly bool $mentionsHousing = false,
    ) {}

    public function passed(): bool
    {
        return $this->reason === null;
    }

    public function withReason(?FilterReason $reason): self
    {
        return new self($reason, $this->language, $this->positiveMatches, $this->negativeMatches,
            $this->negativeScore, $this->hasStrongPositive, $this->mentionsHousing);
    }
}
