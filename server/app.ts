import express, { type ErrorRequestHandler, type Response } from 'express';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import type { DB } from './db.js';
import type { Clock } from './clock.js';
import { login, logout, requireAuth } from './auth.js';
import { createVendorService } from './vendors.js';
import { isStage, MAX_NOTE_LENGTH } from '../shared/workflow.js';
import { STUCK_AFTER_DAYS } from '../shared/stuck.js';
import type { ApiError, VendorListResponse } from '../shared/types.js';

export interface AppOptions {
  db: DB;
  clock: Clock;
  /** Directory with the built SPA; served when present. */
  staticDir?: string;
}

function sendError(res: Response, status: number, body: ApiError): void {
  res.status(status).json(body);
}

function parseId(raw: string | string[] | undefined): number | null {
  const id = Number(raw);
  return Number.isInteger(id) && id > 0 ? id : null;
}

export function createApp({ db, clock, staticDir }: AppOptions) {
  const app = express();
  const vendors = createVendorService(db, clock);
  const auth = requireAuth(db, clock);

  app.use(express.json({ limit: '10kb' }));

  // ---- Auth ----------------------------------------------------------------

  app.post('/api/auth/login', (req, res) => {
    const { username, password } = req.body ?? {};
    if (typeof username !== 'string' || typeof password !== 'string') {
      return sendError(res, 400, { error: 'Username and password are required.', code: 'BAD_REQUEST' });
    }
    const session = login(db, clock, username, password);
    if (!session) {
      return sendError(res, 401, { error: 'Invalid username or password.', code: 'UNAUTHENTICATED' });
    }
    res.json(session);
  });

  app.post('/api/auth/logout', auth, (_req, res) => {
    logout(db, res.locals.token);
    res.status(204).end();
  });

  app.get('/api/auth/me', auth, (_req, res) => {
    res.json({ user: res.locals.user });
  });

  // ---- Vendors -------------------------------------------------------------

  app.get('/api/vendors', auth, (_req, res) => {
    const body: VendorListResponse = {
      vendors: vendors.listVendors(),
      stuckAfterDays: STUCK_AFTER_DAYS,
      now: clock().toISOString(),
    };
    res.json(body);
  });

  app.get('/api/vendors/:id', auth, (req, res) => {
    const id = parseId(req.params.id);
    const vendor = id ? vendors.getVendor(id) : null;
    if (!vendor) return sendError(res, 404, { error: 'Vendor not found.', code: 'NOT_FOUND' });
    res.json({ vendor });
  });

  app.get('/api/vendors/:id/history', auth, (req, res) => {
    const id = parseId(req.params.id);
    const history = id ? vendors.getHistory(id) : null;
    if (!history) return sendError(res, 404, { error: 'Vendor not found.', code: 'NOT_FOUND' });
    res.json({ history });
  });

  app.post('/api/vendors/:id/transitions', auth, (req, res) => {
    const id = parseId(req.params.id);
    if (!id) return sendError(res, 404, { error: 'Vendor not found.', code: 'NOT_FOUND' });

    const { toStage, expectedFromStage, note } = req.body ?? {};
    if (!isStage(toStage) || !isStage(expectedFromStage)) {
      return sendError(res, 400, {
        error: 'toStage and expectedFromStage must be valid stages.',
        code: 'BAD_REQUEST',
      });
    }
    if (note !== undefined && note !== null && typeof note !== 'string') {
      return sendError(res, 400, { error: 'note must be a string.', code: 'BAD_REQUEST' });
    }
    const trimmedNote = typeof note === 'string' && note.trim() ? note.trim() : null;
    if (trimmedNote && trimmedNote.length > MAX_NOTE_LENGTH) {
      return sendError(res, 400, {
        error: `Note must be at most ${MAX_NOTE_LENGTH} characters.`,
        code: 'BAD_REQUEST',
      });
    }

    const result = vendors.transitionVendor({
      vendorId: id,
      toStage,
      expectedFromStage,
      note: trimmedNote,
      actorId: res.locals.user.id,
    });

    if (result.ok) {
      return res.status(201).json({ vendor: result.vendor, transition: result.transition });
    }
    switch (result.code) {
      case 'NOT_FOUND':
        return sendError(res, 404, { error: result.error, code: result.code });
      case 'CONFLICT':
        return sendError(res, 409, { error: result.error, code: result.code, current: result.current });
      case 'INVALID_TRANSITION':
        return sendError(res, 422, { error: result.error, code: result.code });
    }
  });

  app.use('/api', (_req, res) => {
    sendError(res, 404, { error: 'Not found.', code: 'NOT_FOUND' });
  });

  // ---- SPA (production build) ---------------------------------------------

  if (staticDir && existsSync(staticDir)) {
    app.use(express.static(staticDir));
    app.get('/{*splat}', (_req, res) => res.sendFile(join(staticDir, 'index.html')));
  }

  const onError: ErrorRequestHandler = (err, _req, res, _next) => {
    if (err?.type === 'entity.parse.failed') {
      return sendError(res, 400, { error: 'Malformed JSON body.', code: 'BAD_REQUEST' });
    }
    console.error(err);
    res.status(500).json({ error: 'Something went wrong.' });
  };
  app.use(onError);

  return app;
}
