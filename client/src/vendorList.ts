import type { VendorView } from '../../shared/types';
import { TERMINAL_STAGES, type Stage } from '../../shared/workflow';

export interface VendorFilters {
  stage: Stage | null;
  stuckOnly: boolean;
  mineOnly: boolean;
  search: string;
}

export const NO_FILTERS: VendorFilters = { stage: null, stuckOnly: false, mineOnly: false, search: '' };

/**
 * The order a coordinator should work through the list: stuck vendors first
 * (longest-waiting at the top), then other in-progress vendors, then the ones
 * that have finished onboarding.
 */
export function compareByAttention(a: VendorView, b: VendorView): number {
  if (a.isStuck !== b.isStuck) return a.isStuck ? -1 : 1;
  const aDone = TERMINAL_STAGES.has(a.stage);
  const bDone = TERMINAL_STAGES.has(b.stage);
  if (aDone !== bDone) return aDone ? 1 : -1;
  if (a.daysInStage !== b.daysInStage) return b.daysInStage - a.daysInStage;
  return a.name.localeCompare(b.name);
}

export function filterAndSortVendors(
  vendors: VendorView[],
  filters: VendorFilters,
  currentUserId: number,
): VendorView[] {
  const search = filters.search.trim().toLowerCase();
  return vendors
    .filter((v) => !filters.stage || v.stage === filters.stage)
    .filter((v) => !filters.stuckOnly || v.isStuck)
    .filter((v) => !filters.mineOnly || v.coordinator?.id === currentUserId)
    .filter(
      (v) =>
        !search || v.name.toLowerCase().includes(search) || v.region.toLowerCase().includes(search),
    )
    .sort(compareByAttention);
}
