import { describe, expect, it } from 'vitest';
import { allowedTargets, checkTransition, isStage, STAGES } from '../shared/workflow';

// The transition rules are the core business logic and are shared by server
// and client, so they get exhaustive, table-style coverage.
describe('checkTransition', () => {
  it('allows moving forward exactly one stage', () => {
    for (let i = 0; i < STAGES.length - 1; i++) {
      expect(checkTransition(STAGES[i]!, STAGES[i + 1]!)).toEqual({ ok: true, direction: 'forward' });
    }
  });

  it('rejects skipping stages', () => {
    const result = checkTransition('CONTRACT_SIGNED', 'KYC_VERIFIED');
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.reason).toMatch(/KYC Docs Received/);

    expect(checkTransition('CONTRACT_SENT', 'ACTIVE').ok).toBe(false);
  });

  it('rejects moving to the current stage', () => {
    for (const stage of STAGES) {
      expect(checkTransition(stage, stage, 'some note').ok).toBe(false);
    }
  });

  it('requires a non-blank reason to move backwards', () => {
    expect(checkTransition('KYC_VERIFIED', 'KYC_DOCS_RECEIVED').ok).toBe(false);
    expect(checkTransition('KYC_VERIFIED', 'KYC_DOCS_RECEIVED', '   ').ok).toBe(false);
    expect(checkTransition('KYC_VERIFIED', 'KYC_DOCS_RECEIVED', 'Compliance rejected')).toEqual({
      ok: true,
      direction: 'backward',
    });
  });

  it('allows correcting by more than one stage backwards', () => {
    expect(checkTransition('ACTIVE', 'CONTRACT_SENT', 'Wrong vendor activated').ok).toBe(true);
  });
});

describe('allowedTargets', () => {
  it('offers only the next stage forward plus every earlier stage', () => {
    expect(allowedTargets('KYC_DOCS_RECEIVED')).toEqual([
      { stage: 'KYC_VERIFIED', direction: 'forward' },
      { stage: 'CONTRACT_SIGNED', direction: 'backward' },
      { stage: 'CONTRACT_SENT', direction: 'backward' },
    ]);
  });

  it('offers nothing forward from Active and nothing backward from Contract Sent', () => {
    expect(allowedTargets('ACTIVE').every((t) => t.direction === 'backward')).toBe(true);
    expect(allowedTargets('CONTRACT_SENT')).toEqual([{ stage: 'CONTRACT_SIGNED', direction: 'forward' }]);
  });

  it('agrees with checkTransition (the UI never offers a move the server would reject)', () => {
    for (const from of STAGES) {
      for (const { stage } of allowedTargets(from)) {
        expect(checkTransition(from, stage, 'reason').ok).toBe(true);
      }
    }
  });
});

describe('isStage', () => {
  it('accepts only known stage codes', () => {
    expect(isStage('ACTIVE')).toBe(true);
    expect(isStage('Active')).toBe(false);
    expect(isStage(undefined)).toBe(false);
    expect(isStage(3)).toBe(false);
  });
});
