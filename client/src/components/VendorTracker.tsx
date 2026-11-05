import { useCallback, useEffect, useMemo, useState } from 'react';
import type { User, VendorListResponse } from '../../../shared/types';
import { STAGES, STAGE_LABELS } from '../../../shared/workflow';
import { api, ApiRequestError } from '../api';
import { formatDateTime, formatDays } from '../format';
import { filterAndSortVendors, NO_FILTERS, type VendorFilters } from '../vendorList';
import { StageBadge } from './StageBadge';
import { VendorPanel } from './VendorPanel';

interface Props {
  user: User;
  onLogout: () => void;
}

export function VendorTracker({ user, onLogout }: Props) {
  const [data, setData] = useState<VendorListResponse | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [filters, setFilters] = useState<VendorFilters>(NO_FILTERS);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const refresh = useCallback(async () => {
    try {
      setData(await api.listVendors());
      setLoadError(null);
    } catch (err) {
      setLoadError(err instanceof ApiRequestError ? err.message : 'Could not reach the server.');
    }
  }, []);

  // Other coordinators may change things while this tab is open. No real-time
  // updates (out of scope), but refetch when the user comes back to the tab.
  useEffect(() => {
    void refresh();
    const onFocus = () => void refresh();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [refresh]);

  const vendors = data?.vendors ?? [];
  const visible = useMemo(() => filterAndSortVendors(vendors, filters, user.id), [vendors, filters, user.id]);
  const selected = vendors.find((v) => v.id === selectedId) ?? null;
  const stuckCount = vendors.filter((v) => v.isStuck).length;
  const filtersActive = JSON.stringify(filters) !== JSON.stringify(NO_FILTERS);

  const update = (patch: Partial<VendorFilters>) => setFilters((f) => ({ ...f, ...patch }));

  return (
    <div className="app">
      <header className="topbar">
        <h1>Vendor Onboarding</h1>
        <div className="topbar-right">
          <span className="muted">
            Signed in as <strong>{user.displayName}</strong>
          </span>
          <button onClick={onLogout}>Log out</button>
        </div>
      </header>

      {loadError && (
        <p className="error banner" role="alert">
          {loadError} <button onClick={refresh}>Retry</button>
        </p>
      )}

      {data && (
        <>
          <section className="pipeline" aria-label="Vendors per stage">
            {STAGES.map((stage) => {
              const inStage = vendors.filter((v) => v.stage === stage);
              const stuck = inStage.filter((v) => v.isStuck).length;
              const active = filters.stage === stage;
              return (
                <button
                  key={stage}
                  className={`pipeline-step${active ? ' active' : ''}`}
                  aria-pressed={active}
                  onClick={() => update({ stage: active ? null : stage })}
                >
                  <span className="pipeline-label">{STAGE_LABELS[stage]}</span>
                  <span className="pipeline-count">{inStage.length}</span>
                  {stuck > 0 && <span className="stuck-pill">{stuck} stuck</span>}
                </button>
              );
            })}
          </section>

          <section className="toolbar">
            <input
              type="search"
              placeholder="Search vendor or region"
              value={filters.search}
              onChange={(e) => update({ search: e.target.value })}
            />
            <label className="check">
              <input
                type="checkbox"
                checked={filters.stuckOnly}
                onChange={(e) => update({ stuckOnly: e.target.checked })}
              />
              Stuck only ({stuckCount})
            </label>
            <label className="check">
              <input
                type="checkbox"
                checked={filters.mineOnly}
                onChange={(e) => update({ mineOnly: e.target.checked })}
              />
              My vendors
            </label>
            {filtersActive && (
              <button className="link" onClick={() => setFilters(NO_FILTERS)}>
                Clear filters
              </button>
            )}
            <span className="spacer" />
            <span className="muted small">
              Stuck = more than {data.stuckAfterDays} days in a stage · as of {formatDateTime(data.now)}
            </span>
            <button onClick={refresh}>Refresh</button>
          </section>

          <div className="layout">
            <div className="card table-wrap">
              <table className="vendors">
                <thead>
                  <tr>
                    <th>Vendor</th>
                    <th>Region</th>
                    <th>Stage</th>
                    <th>Time in stage</th>
                    <th>Coordinator</th>
                    <th>Last change</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((v) => (
                    <tr
                      key={v.id}
                      className={`${v.isStuck ? 'stuck' : ''}${v.id === selectedId ? ' selected' : ''}`}
                      onClick={() => setSelectedId(v.id)}
                      tabIndex={0}
                      onKeyDown={(e) => e.key === 'Enter' && setSelectedId(v.id)}
                    >
                      <td className="strong">{v.name}</td>
                      <td>{v.region}</td>
                      <td>
                        <StageBadge stage={v.stage} />
                      </td>
                      <td>
                        {formatDays(v.daysInStage)}
                        {v.isStuck && <span className="stuck-pill">Stuck</span>}
                      </td>
                      <td>{v.coordinator?.displayName ?? <span className="warn">Unassigned</span>}</td>
                      <td className="last-change">
                        <span>{v.lastChangedBy?.displayName ?? 'Sales'}</span>
                        {v.lastNote && <span className="muted note-preview"> · {v.lastNote}</span>}
                      </td>
                    </tr>
                  ))}
                  {visible.length === 0 && (
                    <tr>
                      <td colSpan={6} className="muted centered">
                        No vendors match these filters.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {selected ? (
              <VendorPanel
                key={selected.id}
                vendor={selected}
                onClose={() => setSelectedId(null)}
                onChanged={refresh}
              />
            ) : (
              <aside className="card panel placeholder muted">
                Select a vendor to see its history or change its stage.
              </aside>
            )}
          </div>
        </>
      )}
    </div>
  );
}
