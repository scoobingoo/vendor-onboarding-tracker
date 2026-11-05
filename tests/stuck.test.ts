import { describe, expect, it } from 'vitest';
import { daysInStage, isStuck, STUCK_AFTER_DAYS } from '../shared/stuck';

// Stuck detection is a requirement with an easy off-by-one; the boundary is
// what matters most.
const DAY = 24 * 60 * 60 * 1000;
const now = new Date('2026-08-10T09:00:00Z');
const ago = (ms: number) => new Date(now.getTime() - ms);

describe('isStuck', () => {
  it('is not stuck at exactly the threshold ("more than N days")', () => {
    expect(isStuck('CONTRACT_SIGNED', ago(STUCK_AFTER_DAYS * DAY), now)).toBe(false);
  });

  it('is stuck as soon as the threshold is passed', () => {
    expect(isStuck('CONTRACT_SIGNED', ago(STUCK_AFTER_DAYS * DAY + 1), now)).toBe(true);
  });

  it('never flags Active vendors: onboarding is finished', () => {
    expect(isStuck('ACTIVE', ago(365 * DAY), now)).toBe(false);
  });

  it('respects a custom threshold', () => {
    expect(isStuck('KYC_DOCS_RECEIVED', ago(4 * DAY), now, 3)).toBe(true);
  });
});

describe('daysInStage', () => {
  it('counts whole elapsed days', () => {
    expect(daysInStage(ago(0), now)).toBe(0);
    expect(daysInStage(ago(DAY - 1), now)).toBe(0);
    expect(daysInStage(ago(DAY), now)).toBe(1);
    expect(daysInStage(ago(9.5 * DAY), now)).toBe(9);
  });

  it('never goes negative if a timestamp is slightly in the future (clock skew)', () => {
    expect(daysInStage(new Date(now.getTime() + 5000), now)).toBe(0);
  });
});
