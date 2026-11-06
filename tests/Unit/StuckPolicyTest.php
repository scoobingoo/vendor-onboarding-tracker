<?php

namespace Tests\Unit;

use App\Domain\Stage;
use App\Domain\StuckPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Stuck detection is a requirement with an easy off-by-one; the boundary is
 * what matters most.
 */
class StuckPolicyTest extends TestCase
{
    private const DAY_MS = 24 * 60 * 60 * 1000;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-08-10T09:00:00Z');
    }

    private function ago(int|float $ms): CarbonImmutable
    {
        return $this->now->subMilliseconds($ms);
    }

    public function test_is_not_stuck_at_exactly_the_threshold(): void
    {
        $enteredAt = $this->ago(StuckPolicy::STUCK_AFTER_DAYS * self::DAY_MS);

        $this->assertFalse(StuckPolicy::isStuck(Stage::ContractSigned, $enteredAt, $this->now));
    }

    public function test_is_stuck_as_soon_as_the_threshold_is_passed(): void
    {
        $enteredAt = $this->ago(StuckPolicy::STUCK_AFTER_DAYS * self::DAY_MS + 1);

        $this->assertTrue(StuckPolicy::isStuck(Stage::ContractSigned, $enteredAt, $this->now));
    }

    public function test_never_flags_active_vendors_because_onboarding_is_finished(): void
    {
        $this->assertFalse(StuckPolicy::isStuck(Stage::Active, $this->ago(365 * self::DAY_MS), $this->now));
    }

    public function test_respects_a_custom_threshold(): void
    {
        $this->assertTrue(StuckPolicy::isStuck(Stage::KycDocsReceived, $this->ago(4 * self::DAY_MS), $this->now, 3));
    }

    public function test_counts_whole_elapsed_days(): void
    {
        $this->assertSame(0, StuckPolicy::daysInStage($this->ago(0), $this->now));
        $this->assertSame(0, StuckPolicy::daysInStage($this->ago(self::DAY_MS - 1), $this->now));
        $this->assertSame(1, StuckPolicy::daysInStage($this->ago(self::DAY_MS), $this->now));
        $this->assertSame(9, StuckPolicy::daysInStage($this->ago(9.5 * self::DAY_MS), $this->now));
    }

    public function test_never_goes_negative_if_a_timestamp_is_slightly_in_the_future(): void
    {
        $this->assertSame(0, StuckPolicy::daysInStage($this->now->addSeconds(5), $this->now));
    }
}
