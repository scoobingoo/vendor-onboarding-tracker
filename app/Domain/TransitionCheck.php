<?php

namespace App\Domain;

/** The outcome of checking a stage change against the workflow rules. */
final readonly class TransitionCheck
{
    private function __construct(
        public bool $ok,
        public ?TransitionDirection $direction = null,
        public ?string $reason = null,
    ) {}

    public static function allowed(TransitionDirection $direction): self
    {
        return new self(true, direction: $direction);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, reason: $reason);
    }
}
