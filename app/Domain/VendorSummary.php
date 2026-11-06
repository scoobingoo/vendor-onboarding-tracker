<?php

namespace App\Domain;

use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One row of the tracker: a vendor plus the status derived from its history
 * at a given moment. Every row on a page is built with the same "now".
 */
final readonly class VendorSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $region,
        public Stage $stage,
        public CarbonImmutable $stageEnteredAt,
        public int $daysInStage,
        public bool $isStuck,
        public ?int $coordinatorId = null,
        public ?string $coordinatorName = null,
        /** Who made the most recent stage change; null means Sales / imported. */
        public ?string $lastChangedByName = null,
        public ?string $lastNote = null,
    ) {}

    /** Expects `coordinator` and `latestTransition.changedBy` to be eager loaded. */
    public static function fromVendor(Vendor $vendor, CarbonInterface $now): self
    {
        $enteredAt = $vendor->stage_entered_at;
        $last = $vendor->latestTransition;

        return new self(
            id: $vendor->id,
            name: $vendor->name,
            region: $vendor->region,
            stage: $vendor->stage,
            stageEnteredAt: $enteredAt,
            daysInStage: StuckPolicy::daysInStage($enteredAt, $now),
            isStuck: StuckPolicy::isStuck($vendor->stage, $enteredAt, $now),
            coordinatorId: $vendor->coordinator?->id,
            coordinatorName: $vendor->coordinator?->display_name,
            lastChangedByName: $last?->changedBy?->display_name,
            lastNote: $last?->note,
        );
    }

    public function timeInStage(): string
    {
        return match ($this->daysInStage) {
            0 => '< 1 day',
            1 => '1 day',
            default => "{$this->daysInStage} days",
        };
    }
}
