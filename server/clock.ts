/**
 * The server never calls `new Date()` directly for business logic; it asks a
 * Clock. Tests inject a fixed clock, and `FIXED_NOW` can pin the demo to a
 * specific moment.
 */
export type Clock = () => Date;

export const systemClock: Clock = () => new Date();

export function fixedClock(iso: string): Clock {
  const t = new Date(iso);
  if (Number.isNaN(t.getTime())) throw new Error(`Invalid date for fixed clock: ${iso}`);
  return () => new Date(t);
}
