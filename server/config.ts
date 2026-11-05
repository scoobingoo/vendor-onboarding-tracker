import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { fixedClock, systemClock } from './clock.js';

const root = fileURLToPath(new URL('..', import.meta.url));

export const config = {
  // Not PORT: dev tooling often sets PORT for the Vite server, and the two would collide.
  port: Number(process.env.API_PORT ?? 3001),
  dbPath: process.env.DB_PATH ?? join(root, 'data', 'tracker.db'),
  staticDir: join(root, 'dist', 'client'),
  /** Set FIXED_NOW=2026-08-10T09:00:00Z to pin "now" for a demo. */
  clock: process.env.FIXED_NOW ? fixedClock(process.env.FIXED_NOW) : systemClock,
};
