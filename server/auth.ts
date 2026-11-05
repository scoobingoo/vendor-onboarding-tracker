import { randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import type { RequestHandler } from 'express';
import type { DB } from './db.js';
import type { Clock } from './clock.js';
import type { User } from '../shared/types.js';

/**
 * Deliberately simple auth: seeded accounts, scrypt-hashed passwords and
 * opaque bearer tokens stored in a `sessions` table. It exists so the app
 * knows who is making each change. It is not hardened (no rate limiting,
 * no CSRF concerns since we use a header rather than cookies, no rotation).
 */

export const SESSION_TTL_MS = 12 * 60 * 60 * 1000;

declare global {
  namespace Express {
    interface Locals {
      user: User;
      token: string;
    }
  }
}

export function hashPassword(password: string): string {
  const salt = randomBytes(16);
  const hash = scryptSync(password, salt, 32);
  return `${salt.toString('hex')}:${hash.toString('hex')}`;
}

export function verifyPassword(password: string, stored: string): boolean {
  const [saltHex, hashHex] = stored.split(':');
  if (!saltHex || !hashHex) return false;
  const expected = Buffer.from(hashHex, 'hex');
  const actual = scryptSync(password, Buffer.from(saltHex, 'hex'), expected.length);
  return timingSafeEqual(actual, expected);
}

interface UserRow {
  id: number;
  username: string;
  display_name: string;
  password_hash: string;
}

const toUser = (row: Pick<UserRow, 'id' | 'username' | 'display_name'>): User => ({
  id: row.id,
  username: row.username,
  displayName: row.display_name,
});

export function login(db: DB, clock: Clock, username: string, password: string) {
  const row = db
    .prepare('SELECT * FROM users WHERE username = ?')
    .get(username.trim().toLowerCase()) as UserRow | undefined;
  if (!row || !verifyPassword(password, row.password_hash)) return null;

  const token = randomBytes(32).toString('hex');
  db.prepare('INSERT INTO sessions (token, user_id, created_at) VALUES (?, ?, ?)').run(
    token,
    row.id,
    clock().toISOString(),
  );
  return { token, user: toUser(row) };
}

export function logout(db: DB, token: string): void {
  db.prepare('DELETE FROM sessions WHERE token = ?').run(token);
}

export function requireAuth(db: DB, clock: Clock): RequestHandler {
  const findSession = db.prepare(`
    SELECT u.id, u.username, u.display_name, s.created_at
    FROM sessions s JOIN users u ON u.id = s.user_id
    WHERE s.token = ?
  `);

  return (req, res, next) => {
    const header = req.get('authorization') ?? '';
    const token = header.startsWith('Bearer ') ? header.slice('Bearer '.length) : '';
    const row = token
      ? (findSession.get(token) as (UserRow & { created_at: string }) | undefined)
      : undefined;

    const expired =
      row && clock().getTime() - new Date(row.created_at).getTime() > SESSION_TTL_MS;

    if (!row || expired) {
      res.status(401).json({ error: 'Please log in.', code: 'UNAUTHENTICATED' });
      return;
    }
    res.locals.user = toUser(row);
    res.locals.token = token;
    next();
  };
}
