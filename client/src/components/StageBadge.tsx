import { STAGE_LABELS, type Stage } from '../../../shared/workflow';

export function StageBadge({ stage }: { stage: Stage }) {
  return <span className={`badge stage-${stage.toLowerCase()}`}>{STAGE_LABELS[stage]}</span>;
}
