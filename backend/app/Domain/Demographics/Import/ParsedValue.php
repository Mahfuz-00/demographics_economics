<?php

namespace App\Domain\Demographics\Import;

/**
 * Outcome of interpreting one spreadsheet cell. Three states only:
 * a usable value, a declared absence, or something we refuse to guess at.
 */
final class ParsedValue
{
    private function __construct(
        public readonly string $state,
        public readonly ?float $value = null,
        public readonly ?string $rawValue = null,
        public readonly ?int $year = null,
        public readonly string $status = 'reported',
        public readonly ?string $missingReason = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $note = null,
        public readonly bool $isEstimate = false,
        public readonly bool $isProjection = false,
    ) {}

    public static function value(
        ?float $value,
        ?string $raw,
        ?int $year = null,
        string $status = 'reported',
        ?string $note = null,
        bool $isEstimate = false,
        bool $isProjection = false,
    ): self {
        return new self(
            state: 'value',
            value: $value,
            rawValue: $raw,
            year: $year,
            status: $status,
            note: $note,
            isEstimate: $isEstimate,
            isProjection: $isProjection,
        );
    }

    public static function missing(?string $raw, string $reason = 'not_published'): self
    {
        return new self(state: 'missing', rawValue: $raw, status: 'missing', missingReason: $reason);
    }

    public static function error(string $code, ?string $raw, ?string $note = null): self
    {
        return new self(state: 'error', rawValue: $raw, errorCode: $code, note: $note);
    }

    public function isValue(): bool
    {
        return $this->state === 'value';
    }

    public function isMissing(): bool
    {
        return $this->state === 'missing';
    }

    public function isError(): bool
    {
        return $this->state === 'error';
    }
}
