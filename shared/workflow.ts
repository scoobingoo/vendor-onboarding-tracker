/**
 * The onboarding workflow: the five stages, their order, and which moves
 * between them are allowed. This module is pure (no I/O) and is shared by the
 * server, which enforces the rules, and the client, which uses them to offer
 * only valid choices.
 */

export const STAGES = [
  'CONTRACT_SENT',
  'CONTRACT_SIGNED',
  'KYC_DOCS_RECEIVED',
  'KYC_VERIFIED',
  'ACTIVE',
] as const;

export type Stage = (typeof STAGES)[number];

export const STAGE_LABELS: Record<Stage, string> = {
  CONTRACT_SENT: 'Contract Sent',
  CONTRACT_SIGNED: 'Contract Signed',
  KYC_DOCS_RECEIVED: 'KYC Docs Received',
  KYC_VERIFIED: 'KYC Verified',
  ACTIVE: 'Active',
};

/** Stages where the vendor is done onboarding and can't be "stuck". */
export const TERMINAL_STAGES: ReadonlySet<Stage> = new Set<Stage>(['ACTIVE']);

export const MAX_NOTE_LENGTH = 500;

export function isStage(value: unknown): value is Stage {
  return typeof value === 'string' && (STAGES as readonly string[]).includes(value);
}

export function stageIndex(stage: Stage): number {
  return STAGES.indexOf(stage);
}

export type TransitionDirection = 'forward' | 'backward';

export type TransitionCheck =
  | { ok: true; direction: TransitionDirection }
  | { ok: false; reason: string };

/**
 * Rules:
 *  - Forward moves go exactly one stage at a time. Skipping a stage (e.g.
 *    Contract Signed -> KYC Verified) would hide the fact that a step such
 *    as Compliance review never happened.
 *  - Backward moves to any earlier stage are allowed, because they are how
 *    coordinators correct mistakes (a mis-click, or an activation that has
 *    to be rolled back). They must include a reason so the audit trail
 *    explains them.
 *  - Moving to the stage the vendor is already in is rejected.
 */
export function checkTransition(from: Stage, to: Stage, note?: string | null): TransitionCheck {
  const fromIdx = stageIndex(from);
  const toIdx = stageIndex(to);

  if (toIdx === fromIdx) {
    return { ok: false, reason: `Vendor is already in "${STAGE_LABELS[to]}".` };
  }

  if (toIdx > fromIdx) {
    if (toIdx !== fromIdx + 1) {
      const next = STAGES[fromIdx + 1]!;
      return {
        ok: false,
        reason: `Stages can't be skipped. The next stage after "${STAGE_LABELS[from]}" is "${STAGE_LABELS[next]}".`,
      };
    }
    return { ok: true, direction: 'forward' };
  }

  if (!note || note.trim().length === 0) {
    return {
      ok: false,
      reason: 'Moving a vendor back to an earlier stage requires a reason.',
    };
  }
  return { ok: true, direction: 'backward' };
}

/** Every stage a vendor in `from` could legally move to (a backward move still needs a reason). */
export function allowedTargets(from: Stage): { stage: Stage; direction: TransitionDirection }[] {
  const fromIdx = stageIndex(from);
  const targets: { stage: Stage; direction: TransitionDirection }[] = [];
  const next = STAGES[fromIdx + 1];
  if (next) targets.push({ stage: next, direction: 'forward' });
  for (let i = fromIdx - 1; i >= 0; i--) {
    targets.push({ stage: STAGES[i]!, direction: 'backward' });
  }
  return targets;
}
