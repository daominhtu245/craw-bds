<?php

namespace App\Services\Ingest;

class IngestResult
{
    public int $accepted = 0;

    public int $duplicates = 0;

    /** @var list<array{index: int, error: string}> */
    public array $rejected = [];

    public function reject(int $index, string $error): void
    {
        $this->rejected[] = ['index' => $index, 'error' => $error];
    }

    public function merge(self $other, int $indexOffset = 0): void
    {
        $this->accepted += $other->accepted;
        $this->duplicates += $other->duplicates;

        foreach ($other->rejected as $rejection) {
            $this->reject($rejection['index'] + $indexOffset, $rejection['error']);
        }
    }

    /** @return array{accepted: int, duplicates: int, rejected: list<array{index: int, error: string}>} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'rejected' => $this->rejected,
        ];
    }
}
