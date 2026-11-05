import type { Stage } from './workflow.js';

/** API contracts shared by server and client. All timestamps are ISO-8601 UTC strings. */

export interface User {
  id: number;
  username: string;
  displayName: string;
}

export interface VendorView {
  id: number;
  name: string;
  region: string;
  stage: Stage;
  stageEnteredAt: string;
  coordinator: User | null;
  /** Derived on the server from the stage history; never entered by hand. */
  daysInStage: number;
  isStuck: boolean;
  /** Who made the most recent stage change (null = Sales / imported) and the note they left. */
  lastChangedBy: User | null;
  lastNote: string | null;
}

export interface StageTransition {
  id: number;
  vendorId: number;
  /** null for the vendor's first entry (created by Sales at "Contract Sent"). */
  fromStage: Stage | null;
  toStage: Stage;
  changedAt: string;
  /** null when the change was imported or made outside the app (e.g. by Sales). */
  changedBy: User | null;
  note: string | null;
}

export interface VendorListResponse {
  vendors: VendorView[];
  stuckAfterDays: number;
  now: string;
}

export interface TransitionRequest {
  toStage: Stage;
  /** The stage the client believed the vendor was in; used to detect concurrent edits. */
  expectedFromStage: Stage;
  note?: string;
}

export interface ApiError {
  error: string;
  code: 'UNAUTHENTICATED' | 'BAD_REQUEST' | 'NOT_FOUND' | 'INVALID_TRANSITION' | 'CONFLICT';
  /** For CONFLICT: the vendor as it is now, so the client can show what changed. */
  current?: VendorView;
}
