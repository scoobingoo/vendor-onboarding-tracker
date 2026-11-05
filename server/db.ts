import Database from 'better-sqlite3';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { STAGES } from '../shared/workflow.js';

export type DB = Database.Database;

const stageList = STAGES.map((s) => `'${s}'`).join(', ');

/**
 * Schema notes:
 *  - `stage_transitions` is the source of truth: one row per stage change,
 *    recording who, when, from and to. Triggers make it append-only, so history
 *    can't be edited or deleted from inside the app. A mistake is fixed by
 *    adding a new (backward) transition, never by rewriting the log.
 *  - `vendors.stage` / `vendors.stage_entered_at` cache the latest transition
 *    so the list query stays simple and so stage changes can do a
 *    compare-and-set (UPDATE ... WHERE stage = expected). Both are written in
 *    the same transaction as the history row, so they can't drift apart.
 */
const SCHEMA = `
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY,
  username      TEXT    NOT NULL UNIQUE,
  display_name  TEXT    NOT NULL,
  password_hash TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
  token      TEXT    PRIMARY KEY,
  user_id    INTEGER NOT NULL REFERENCES users(id),
  created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS vendors (
  id               INTEGER PRIMARY KEY,
  name             TEXT    NOT NULL,
  region           TEXT    NOT NULL,
  coordinator_id   INTEGER REFERENCES users(id),
  stage            TEXT    NOT NULL CHECK (stage IN (${stageList})),
  stage_entered_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS stage_transitions (
  id         INTEGER PRIMARY KEY,
  vendor_id  INTEGER NOT NULL REFERENCES vendors(id),
  from_stage TEXT    CHECK (from_stage IS NULL OR from_stage IN (${stageList})),
  to_stage   TEXT    NOT NULL CHECK (to_stage IN (${stageList})),
  changed_by INTEGER REFERENCES users(id),
  changed_at TEXT    NOT NULL,
  note       TEXT
);

CREATE INDEX IF NOT EXISTS idx_transitions_vendor
  ON stage_transitions (vendor_id, changed_at, id);

CREATE TRIGGER IF NOT EXISTS stage_transitions_no_update
  BEFORE UPDATE ON stage_transitions
  BEGIN SELECT RAISE(ABORT, 'stage_transitions is append-only'); END;

CREATE TRIGGER IF NOT EXISTS stage_transitions_no_delete
  BEFORE DELETE ON stage_transitions
  BEGIN SELECT RAISE(ABORT, 'stage_transitions is append-only'); END;
`;

/** Opens (or creates) the database and applies the schema. Use ':memory:' in tests. */
export function openDatabase(path: string): DB {
  if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true });
  const db = new Database(path);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  db.exec(SCHEMA);
  return db;
}
