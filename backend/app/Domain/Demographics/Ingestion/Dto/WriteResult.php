<?php

namespace App\Domain\Demographics\Ingestion\Dto;

/**
 * Tally of what one write pass did. Surfaced in the ingestion batch report so a
 * run can be audited without reading the observation tables.
 */
class WriteResult
{
    public int $created = 0;

    public int $unchanged = 0;

    public int $superseded = 0;

    public int $conflicts = 0;

    public int $rejected = 0;

    public array $rejections = [];

    public function reject(string $reason, string $detail): void
    {
        $this->rejected++;
        $this->rejections[] = ['reason' => $reason, 'detail' => $detail];
    }

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'unchanged' => $this->unchanged,
            'superseded' => $this->superseded,
            'conflicts' => $this->conflicts,
            'rejected' => $this->rejected,
        ];
    }
}
