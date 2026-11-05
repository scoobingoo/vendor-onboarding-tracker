import { TERMINAL_STAGES, type Stage } from './workflow.js';

/** A vendor is "stuck" once it has spent MORE than this many days in a non-terminal stage. */
export const STUCK_AFTER_DAYS = 7;

const MS_PER_DAY = 24 * 60 * 60 * 1000;

/** Whole days elapsed since `enteredAt` (floored, never negative). */
export function daysInStage(enteredAt: Date, now: Date): number {
  const elapsed = now.getTime() - enteredAt.getTime();
  return Math.max(0, Math.floor(elapsed / MS_PER_DAY));
}

/**
 * Compares exact elapsed time rather than whole days, so a vendor becomes stuck
 * the moment it passes 7 x 24 hours in the stage, not at some midnight boundary
 * that depends on the server's timezone.
 */
export function isStuck(
  stage: Stage,
  enteredAt: Date,
  now: Date,
  thresholdDays: number = STUCK_AFTER_DAYS,
): boolean {
  if (TERMINAL_STAGES.has(stage)) return false;
  return now.getTime() - enteredAt.getTime() > thresholdDays * MS_PER_DAY;
}
