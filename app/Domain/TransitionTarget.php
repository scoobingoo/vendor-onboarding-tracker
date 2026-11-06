<?php

namespace App\Domain;

/** A stage a vendor can be moved to from where it is now. */
final readonly class TransitionTarget
{
    public function __construct(
        public Stage $stage,
        public TransitionDirection $direction,
    ) {}

    public function isBackward(): bool
    {
        return $this->direction === TransitionDirection::Backward;
    }

    public function label(): string
    {
        return ($this->isBackward() ? 'Back to: ' : 'Next: ').$this->stage->label();
    }
}
