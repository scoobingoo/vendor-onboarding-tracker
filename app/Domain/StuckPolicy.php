<?php

namespace App\Domain;

use Carbon\CarbonInterface;

/**
 * Time in stage and "stuck" status, derived from when the vendor entered its
 * current stage. Nobody types these in.
 */
final class StuckPolicy
{
    /** A vendor is stuck once it has spent MORE than this many days in a non-terminal stage. */
    public const STUCK_AFTER_DAYS = 7;

    private const MS_PER_DAY = 24 * 60 * 60 * 1000;

    /** Whole days elapsed since $enteredAt (floored, never negative). */
    public static function daysInStage(CarbonInterface $enteredAt, CarbonInterface $now): int
    {
        return max(0, intdiv(self::elapsedMs($enteredAt, $now), self::MS_PER_DAY));
    }

    /**
     * Compares exact elapsed time rather than whole days, so a vendor becomes
     * stuck the moment it passes 7 x 24 hours in the stage, not at a midnight
     * boundary that depends on the server's timezone.
     */
    public static function isStuck(
        Stage $stage,
        CarbonInterface $enteredAt,
        CarbonInterface $now,
        int $thresholdDays = self::STUCK_AFTER_DAYS,
    ): bool {
        if ($stage->isTerminal()) {
            return false;
        }

        return self::elapsedMs($enteredAt, $now) > $thresholdDays * self::MS_PER_DAY;
    }

    private static function elapsedMs(CarbonInterface $from, CarbonInterface $to): int
    {
        return $to->getTimestampMs() - $from->getTimestampMs();
    }
}
