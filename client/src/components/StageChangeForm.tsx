import { useState, type FormEvent } from 'react';
import type { VendorView } from '../../../shared/types';
import {
  allowedTargets,
  checkTransition,
  MAX_NOTE_LENGTH,
  STAGE_LABELS,
  type Stage,
} from '../../../shared/workflow';
import { api, ApiRequestError } from '../api';

interface Props {
  vendor: VendorView;
  onSuccess: (to: Stage) => Promise<void>;
  onConflict: (current: VendorView | undefined) => Promise<void>;
}

export function StageChangeForm({ vendor, onSuccess, onConflict }: Props) {
  const targets = allowedTargets(vendor.stage);
  const [toStage, setToStage] = useState<Stage | ''>(targets[0]?.stage ?? '');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const direction = targets.find((t) => t.stage === toStage)?.direction;
  // Same rules the server enforces; checked here only to give instant feedback.
  const check = toStage ? checkTransition(vendor.stage, toStage, note) : null;

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (!toStage || !check?.ok) return;
    setSubmitting(true);
    setError(null);
    try {
      await api.transition(vendor.id, { toStage, expectedFromStage: vendor.stage, note });
      await onSuccess(toStage);
    } catch (err) {
      if (err instanceof ApiRequestError && err.body.code === 'CONFLICT') {
        await onConflict(err.body.current);
      } else {
        setError(err instanceof ApiRequestError ? err.message : 'Could not reach the server.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="stage-form" onSubmit={handleSubmit}>
      <label>
        Move to
        <select value={toStage} onChange={(e) => setToStage(e.target.value as Stage)}>
          {targets.map((t) => (
            <option key={t.stage} value={t.stage}>
              {t.direction === 'forward' ? `Next: ${STAGE_LABELS[t.stage]}` : `Back to: ${STAGE_LABELS[t.stage]}`}
            </option>
          ))}
        </select>
      </label>

      <label>
        {direction === 'backward' ? 'Reason (required)' : 'Note (optional)'}
        <textarea
          value={note}
          onChange={(e) => setNote(e.target.value)}
          maxLength={MAX_NOTE_LENGTH}
          rows={2}
          placeholder={
            direction === 'backward'
              ? 'Why is this vendor going back? e.g. Compliance rejected the business license'
              : 'e.g. Contract countersigned via email'
          }
        />
      </label>

      {direction === 'backward' && (
        <p className="warning small">
          Moving backwards is for corrections and rejections. It resets the time-in-stage clock.
        </p>
      )}
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}

      <button type="submit" className="primary" disabled={submitting || !check?.ok}>
        {submitting ? 'Saving…' : 'Update stage'}
      </button>
    </form>
  );
}
