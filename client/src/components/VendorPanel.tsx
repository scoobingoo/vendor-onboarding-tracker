import { useEffect, useState } from 'react';
import type { StageTransition, VendorView } from '../../../shared/types';
import { STAGES, STAGE_LABELS, stageIndex } from '../../../shared/workflow';
import { api, ApiRequestError } from '../api';
import { formatDateTime, formatDays } from '../format';
import { StageBadge } from './StageBadge';
import { StageChangeForm } from './StageChangeForm';

interface Props {
  vendor: VendorView;
  onClose: () => void;
  /** Called after the vendor changed (by us or, on conflict, by someone else). */
  onChanged: () => Promise<void>;
}

type Notice = { kind: 'success' | 'warning'; text: string } | null;

export function VendorPanel({ vendor, onClose, onChanged }: Props) {
  const [history, setHistory] = useState<StageTransition[] | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice>(null);

  // Reload history whenever the vendor's stage changes (including changes
  // made by other coordinators that we pick up on refresh).
  useEffect(() => {
    let cancelled = false;
    api
      .history(vendor.id)
      .then((h) => {
        if (cancelled) return;
        setHistory(h);
        setHistoryError(null);
      })
      .catch((err) => {
        if (!cancelled) setHistoryError(err instanceof ApiRequestError ? err.message : 'Could not load history.');
      });
    return () => {
      cancelled = true;
    };
  }, [vendor.id, vendor.stageEnteredAt]);

  const currentIdx = stageIndex(vendor.stage);

  return (
    <aside className="card panel" aria-label={`${vendor.name} details`}>
      <div className="panel-head">
        <div>
          <h2>{vendor.name}</h2>
          <p className="muted">
            {vendor.region} · Coordinator: {vendor.coordinator?.displayName ?? 'Unassigned'}
          </p>
        </div>
        <button className="link" onClick={onClose} aria-label="Close panel">
          ✕
        </button>
      </div>

      <ol className="stepper">
        {STAGES.map((s, i) => (
          <li key={s} className={i < currentIdx ? 'done' : i === currentIdx ? 'current' : ''}>
            {STAGE_LABELS[s]}
          </li>
        ))}
      </ol>

      <p>
        In <StageBadge stage={vendor.stage} /> for <strong>{formatDays(vendor.daysInStage)}</strong>{' '}
        <span className="muted small">(since {formatDateTime(vendor.stageEnteredAt)})</span>
        {vendor.isStuck && <span className="stuck-pill">Stuck</span>}
      </p>

      {notice && <p className={notice.kind === 'success' ? 'success' : 'warning'}>{notice.text}</p>}

      <StageChangeForm
        key={vendor.stageEnteredAt}
        vendor={vendor}
        onSuccess={async (to) => {
          setNotice({ kind: 'success', text: `Moved to "${STAGE_LABELS[to]}".` });
          await onChanged();
        }}
        onConflict={async (current) => {
          const by = current?.lastChangedBy?.displayName ?? 'someone else';
          const stage = current ? `"${STAGE_LABELS[current.stage]}"` : 'another stage';
          setNotice({
            kind: 'warning',
            text: `Not saved: ${by} already moved this vendor to ${stage}. The view has been refreshed. Please review before trying again.`,
          });
          await onChanged();
        }}
      />

      <h3>History</h3>
      {historyError && <p className="error">{historyError}</p>}
      {!history && !historyError && <p className="muted">Loading…</p>}
      {history && (
        <ol className="history">
          {history.map((t) => (
            <li key={t.id} className={isBackward(t) ? 'backward' : ''}>
              <div>
                <strong>{t.changedBy?.displayName ?? 'Sales'}</strong>{' '}
                {t.fromStage ? (
                  <>
                    moved <StageBadge stage={t.fromStage} /> → <StageBadge stage={t.toStage} />
                  </>
                ) : (
                  <>
                    added vendor at <StageBadge stage={t.toStage} />
                  </>
                )}
              </div>
              <div className="muted small">{formatDateTime(t.changedAt)}</div>
              {t.note && <div className="note">“{t.note}”</div>}
            </li>
          ))}
        </ol>
      )}
    </aside>
  );
}

function isBackward(t: StageTransition): boolean {
  return t.fromStage !== null && stageIndex(t.toStage) < stageIndex(t.fromStage);
}
