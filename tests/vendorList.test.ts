import { describe, expect, it } from 'vitest';
import { filterAndSortVendors, NO_FILTERS } from '../client/src/vendorList';
import type { VendorView } from '../shared/types';
import type { Stage } from '../shared/workflow';

// The list order is how coordinators decide what to work on next, so check
// that stuck vendors always come first.
function vendor(id: number, stage: Stage, daysInStage: number, isStuck: boolean, coordinatorId: number | null = 1): VendorView {
  return {
    id,
    name: `Vendor ${id}`,
    region: 'HCMC',
    stage,
    stageEnteredAt: '2026-08-01T00:00:00Z',
    coordinator: coordinatorId ? { id: coordinatorId, username: 'u', displayName: 'U' } : null,
    daysInStage,
    isStuck,
    lastChangedBy: null,
    lastNote: null,
  };
}

const vendors = [
  vendor(1, 'ACTIVE', 30, false),
  vendor(2, 'CONTRACT_SENT', 2, false),
  vendor(3, 'KYC_DOCS_RECEIVED', 21, true, 2),
  vendor(4, 'CONTRACT_SIGNED', 9, true),
  vendor(5, 'KYC_VERIFIED', 5, false, null),
];

describe('filterAndSortVendors', () => {
  it('puts stuck vendors first (longest waiting on top), finished vendors last', () => {
    expect(filterAndSortVendors(vendors, NO_FILTERS, 1).map((v) => v.id)).toEqual([3, 4, 5, 2, 1]);
  });

  it('combines filters', () => {
    const ids = (f: Partial<typeof NO_FILTERS>) =>
      filterAndSortVendors(vendors, { ...NO_FILTERS, ...f }, 1).map((v) => v.id);

    expect(ids({ stuckOnly: true })).toEqual([3, 4]);
    expect(ids({ mineOnly: true })).toEqual([4, 2, 1]);
    expect(ids({ stuckOnly: true, mineOnly: true })).toEqual([4]);
    expect(ids({ stage: 'KYC_VERIFIED' })).toEqual([5]);
    expect(ids({ search: 'vendor 2' })).toEqual([2]);
  });
});
