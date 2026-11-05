import type { DB } from './db.js';
import type { Clock } from './clock.js';
import type { StageTransition, User, VendorView } from '../shared/types.js';
import { checkTransition, type Stage } from '../shared/workflow.js';
import { daysInStage, isStuck } from '../shared/stuck.js';

/**
 * Vendor reads and the one write the app supports: moving a vendor to a new
 * stage. All workflow rules come from shared/workflow.ts; this module adds
 * persistence, atomicity and concurrency control.
 */

interface VendorRow {
  id: number;
  name: string;
  region: string;
  stage: Stage;
  stage_entered_at: string;
  coordinator_id: number | null;
  coordinator_username: string | null;
  coordinator_display_name: string | null;
  last_by_id: number | null;
  last_by_username: string | null;
  last_by_display_name: string | null;
  last_note: string | null;
}

interface TransitionRow {
  id: number;
  vendor_id: number;
  from_stage: Stage | null;
  to_stage: Stage;
  changed_at: string;
  note: string | null;
  user_id: number | null;
  username: string | null;
  display_name: string | null;
}

const VENDOR_SELECT = `
  SELECT v.id, v.name, v.region, v.stage, v.stage_entered_at,
         c.id AS coordinator_id, c.username AS coordinator_username,
         c.display_name AS coordinator_display_name,
         lb.id AS last_by_id, lb.username AS last_by_username,
         lb.display_name AS last_by_display_name,
         t.note AS last_note
  FROM vendors v
  LEFT JOIN users c ON c.id = v.coordinator_id
  LEFT JOIN stage_transitions t ON t.id = (
    SELECT id FROM stage_transitions
    WHERE vendor_id = v.id
    ORDER BY changed_at DESC, id DESC
    LIMIT 1
  )
  LEFT JOIN users lb ON lb.id = t.changed_by
`;

function userOrNull(id: number | null, username: string | null, displayName: string | null): User | null {
  return id !== null && username !== null && displayName !== null
    ? { id, username, displayName }
    : null;
}

function toVendorView(row: VendorRow, now: Date): VendorView {
  const enteredAt = new Date(row.stage_entered_at);
  return {
    id: row.id,
    name: row.name,
    region: row.region,
    stage: row.stage,
    stageEnteredAt: row.stage_entered_at,
    coordinator: userOrNull(row.coordinator_id, row.coordinator_username, row.coordinator_display_name),
    daysInStage: daysInStage(enteredAt, now),
    isStuck: isStuck(row.stage, enteredAt, now),
    lastChangedBy: userOrNull(row.last_by_id, row.last_by_username, row.last_by_display_name),
    lastNote: row.last_note,
  };
}

function toTransition(row: TransitionRow): StageTransition {
  return {
    id: row.id,
    vendorId: row.vendor_id,
    fromStage: row.from_stage,
    toStage: row.to_stage,
    changedAt: row.changed_at,
    changedBy: userOrNull(row.user_id, row.username, row.display_name),
    note: row.note,
  };
}

export type TransitionResult =
  | { ok: true; vendor: VendorView; transition: StageTransition }
  | { ok: false; code: 'NOT_FOUND'; error: string }
  | { ok: false; code: 'CONFLICT'; error: string; current: VendorView }
  | { ok: false; code: 'INVALID_TRANSITION'; error: string };

export interface TransitionInput {
  vendorId: number;
  toStage: Stage;
  expectedFromStage: Stage;
  note: string | null;
  actorId: number;
}

export function createVendorService(db: DB, clock: Clock) {
  const selectAll = db.prepare(`${VENDOR_SELECT} ORDER BY v.name`);
  const selectOne = db.prepare(`${VENDOR_SELECT} WHERE v.id = ?`);
  const selectHistory = db.prepare(`
    SELECT t.id, t.vendor_id, t.from_stage, t.to_stage, t.changed_at, t.note,
           u.id AS user_id, u.username, u.display_name
    FROM stage_transitions t
    LEFT JOIN users u ON u.id = t.changed_by
    WHERE t.vendor_id = ?
    ORDER BY t.changed_at DESC, t.id DESC
  `);
  const selectTransition = db.prepare(`
    SELECT t.id, t.vendor_id, t.from_stage, t.to_stage, t.changed_at, t.note,
           u.id AS user_id, u.username, u.display_name
    FROM stage_transitions t
    LEFT JOIN users u ON u.id = t.changed_by
    WHERE t.id = ?
  `);
  const compareAndSetStage = db.prepare(`
    UPDATE vendors SET stage = @toStage, stage_entered_at = @now
    WHERE id = @vendorId AND stage = @expectedFromStage
  `);
  const insertTransition = db.prepare(`
    INSERT INTO stage_transitions (vendor_id, from_stage, to_stage, changed_by, changed_at, note)
    VALUES (@vendorId, @fromStage, @toStage, @actorId, @now, @note)
  `);

  function getVendor(id: number): VendorView | null {
    const row = selectOne.get(id) as VendorRow | undefined;
    return row ? toVendorView(row, clock()) : null;
  }

  function listVendors(): VendorView[] {
    const now = clock();
    return (selectAll.all() as VendorRow[]).map((row) => toVendorView(row, now));
  }

  function getHistory(vendorId: number): StageTransition[] | null {
    if (!selectOne.get(vendorId)) return null;
    return (selectHistory.all(vendorId) as TransitionRow[]).map(toTransition);
  }

  /**
   * Runs as a single IMMEDIATE transaction (takes the write lock up front), so
   * the stage update and its history row are written together or not at all.
   *
   * `expectedFromStage` is optimistic concurrency control: if another
   * coordinator moved the vendor since this client loaded it, we return
   * CONFLICT instead of silently applying a change based on stale data. That
   * is the "two people editing the spreadsheet at once" problem.
   */
  const transition = db.transaction((input: TransitionInput): TransitionResult => {
    const current = getVendor(input.vendorId);
    if (!current) {
      return { ok: false, code: 'NOT_FOUND', error: 'Vendor not found.' };
    }

    if (current.stage !== input.expectedFromStage) {
      return {
        ok: false,
        code: 'CONFLICT',
        error: 'This vendor was updated by someone else since you loaded it.',
        current,
      };
    }

    const check = checkTransition(current.stage, input.toStage, input.note);
    if (!check.ok) {
      return { ok: false, code: 'INVALID_TRANSITION', error: check.reason };
    }

    const now = clock().toISOString();
    const updated = compareAndSetStage.run({ ...input, now });
    if (updated.changes !== 1) {
      // Can't happen inside an IMMEDIATE transaction; kept as a guard in case
      // the locking strategy changes later.
      throw new Error(`Compare-and-set failed for vendor ${input.vendorId}`);
    }

    const inserted = insertTransition.run({
      vendorId: input.vendorId,
      fromStage: current.stage,
      toStage: input.toStage,
      actorId: input.actorId,
      now,
      note: input.note,
    });

    return {
      ok: true,
      vendor: getVendor(input.vendorId)!,
      transition: toTransition(selectTransition.get(inserted.lastInsertRowid) as TransitionRow),
    };
  });

  return {
    listVendors,
    getVendor,
    getHistory,
    transitionVendor: (input: TransitionInput) => transition.immediate(input),
  };
}

export type VendorService = ReturnType<typeof createVendorService>;
