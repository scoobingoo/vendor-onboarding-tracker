<?php

namespace App\Domain;

/** The list filters, read from and written back to the query string. */
final readonly class VendorFilters
{
    public function __construct(
        public ?Stage $stage = null,
        public bool $stuckOnly = false,
        public bool $mineOnly = false,
        public string $search = '',
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $stage = $query['stage'] ?? null;
        $search = $query['q'] ?? '';

        return new self(
            stage: is_string($stage) ? Stage::tryFrom($stage) : null,
            stuckOnly: ! empty($query['stuck']),
            mineOnly: ! empty($query['mine']),
            search: is_string($search) ? trim($search) : '',
        );
    }

    /** @return array<string, string> */
    public function toQuery(): array
    {
        return array_filter([
            'stage' => $this->stage?->value,
            'stuck' => $this->stuckOnly ? '1' : null,
            'mine' => $this->mineOnly ? '1' : null,
            'q' => $this->search,
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    public function withStage(?Stage $stage): self
    {
        return new self($stage, $this->stuckOnly, $this->mineOnly, $this->search);
    }

    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }
}
