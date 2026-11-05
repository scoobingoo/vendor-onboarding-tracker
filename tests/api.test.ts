import { beforeEach, describe, expect, it } from 'vitest';
import request from 'supertest';
import type { Express } from 'express';
import { createApp } from '../server/app';
import { openDatabase, type DB } from '../server/db';
import { seed, SEED_PASSWORD } from '../server/seed';
import type { Clock } from '../server/clock';
import type { StageTransition, VendorListResponse, VendorView } from '../shared/types';

/**
 * End-to-end through HTTP with a real (in-memory) SQLite database and a
 * controllable clock. These cover the requirements that matter most: every
 * stage change is recorded with who/when/from/to, invalid or stale changes
 * leave no trace, and stuck status is derived from history.
 */

const DAY = 24 * 60 * 60 * 1000;
const START = new Date('2026-08-10T09:00:00.000Z');

let db: DB;
let app: Express;
let now: Date;
const clock: Clock = () => new Date(now);

beforeEach(() => {
  now = new Date(START);
  db = openDatabase(':memory:');
  seed(db, START);
  app = createApp({ db, clock });
});

async function loginAs(username: string): Promise<string> {
  const res = await request(app).post('/api/auth/login').send({ username, password: SEED_PASSWORD });
  expect(res.status).toBe(200);
  return res.body.token;
}

async function listVendors(token: string): Promise<VendorView[]> {
  const res = await request(app).get('/api/vendors').set('Authorization', `Bearer ${token}`);
  expect(res.status).toBe(200);
  return (res.body as VendorListResponse).vendors;
}

async function vendorByName(token: string, name: string): Promise<VendorView> {
  const vendor = (await listVendors(token)).find((v) => v.name === name);
  if (!vendor) throw new Error(`No seeded vendor ${name}`);
  return vendor;
}

async function history(token: string, id: number): Promise<StageTransition[]> {
  const res = await request(app).get(`/api/vendors/${id}/history`).set('Authorization', `Bearer ${token}`);
  expect(res.status).toBe(200);
  return res.body.history;
}

function transition(token: string, id: number, body: object) {
  return request(app)
    .post(`/api/vendors/${id}/transitions`)
    .set('Authorization', `Bearer ${token}`)
    .send(body);
}

describe('authentication', () => {
  it('rejects requests without a valid session', async () => {
    expect((await request(app).get('/api/vendors')).status).toBe(401);
    expect(
      (await request(app).get('/api/vendors').set('Authorization', 'Bearer not-a-token')).status,
    ).toBe(401);
  });

  it('rejects a wrong password', async () => {
    const res = await request(app).post('/api/auth/login').send({ username: 'linh', password: 'nope' });
    expect(res.status).toBe(401);
  });

  it('identifies the logged-in coordinator', async () => {
    const token = await loginAs('Linh'); // usernames are case-insensitive
    const res = await request(app).get('/api/auth/me').set('Authorization', `Bearer ${token}`);
    expect(res.body.user).toMatchObject({ username: 'linh', displayName: 'Linh Nguyen' });
  });

  it('expires sessions after 12 hours', async () => {
    const token = await loginAs('huy');
    now = new Date(START.getTime() + 13 * 60 * 60 * 1000);
    expect((await request(app).get('/api/vendors').set('Authorization', `Bearer ${token}`)).status).toBe(401);
  });

  it('invalidates the session on logout', async () => {
    const token = await loginAs('mai');
    await request(app).post('/api/auth/logout').set('Authorization', `Bearer ${token}`).expect(204);
    expect((await request(app).get('/api/vendors').set('Authorization', `Bearer ${token}`)).status).toBe(401);
  });
});

describe('listing vendors', () => {
  it('derives time-in-stage and stuck status from history', async () => {
    const token = await loginAs('linh');
    const vendors = await listVendors(token);

    const c = vendors.find((v) => v.name === 'Company C')!; // Contract Signed 9 days ago
    expect(c).toMatchObject({ stage: 'CONTRACT_SIGNED', daysInStage: 9, isStuck: true });

    const d = vendors.find((v) => v.name === 'Company D')!; // KYC Verified 3 days ago
    expect(d).toMatchObject({ stage: 'KYC_VERIFIED', daysInStage: 3, isStuck: false });

    const a = vendors.find((v) => v.name === 'Company A')!; // Active 24 days ago
    expect(a).toMatchObject({ stage: 'ACTIVE', isStuck: false });
  });

  it('updates stuck status as time passes, with no manual "Last Updated" field', async () => {
    const token = await loginAs('linh');
    expect((await vendorByName(token, 'Company H')).isStuck).toBe(false); // 2 days in stage

    now = new Date(START.getTime() + 6 * DAY);
    const laterToken = await loginAs('linh'); // the first session has expired by now
    expect((await vendorByName(laterToken, 'Company H')).isStuck).toBe(true); // 8 days in stage
  });
});

describe('changing a stage', () => {
  it('records who, when, previous stage and new stage', async () => {
    const token = await loginAs('mai');
    const vendor = await vendorByName(token, 'Company F'); // Contract Sent
    now = new Date(START.getTime() + 60_000);

    const res = await transition(token, vendor.id, {
      toStage: 'CONTRACT_SIGNED',
      expectedFromStage: 'CONTRACT_SENT',
      note: '  Signed via email  ',
    });

    expect(res.status).toBe(201);
    expect(res.body.vendor).toMatchObject({
      stage: 'CONTRACT_SIGNED',
      stageEnteredAt: now.toISOString(),
      daysInStage: 0,
      isStuck: false,
    });

    const [latest] = await history(token, vendor.id);
    expect(latest).toMatchObject({
      fromStage: 'CONTRACT_SENT',
      toStage: 'CONTRACT_SIGNED',
      changedAt: now.toISOString(),
      changedBy: { username: 'mai' },
      note: 'Signed via email',
    });
  });

  it('resets the stuck clock when a stuck vendor moves on', async () => {
    const token = await loginAs('huy');
    const e = await vendorByName(token, 'Company E');
    expect(e.isStuck).toBe(true);

    await transition(token, e.id, { toStage: 'KYC_VERIFIED', expectedFromStage: 'KYC_DOCS_RECEIVED' }).expect(201);
    expect(await vendorByName(token, 'Company E')).toMatchObject({ isStuck: false, daysInStage: 0 });
  });

  it('rejects a stale update when another coordinator changed the vendor first', async () => {
    const linh = await loginAs('linh');
    const huy = await loginAs('huy');
    const c = await vendorByName(linh, 'Company C'); // both load it at Contract Signed

    await transition(huy, c.id, { toStage: 'KYC_DOCS_RECEIVED', expectedFromStage: 'CONTRACT_SIGNED' }).expect(201);

    // Linh, still looking at the old data, tries to move it "back" a step.
    const res = await transition(linh, c.id, {
      toStage: 'CONTRACT_SENT',
      expectedFromStage: 'CONTRACT_SIGNED',
      note: 'Contract needs re-signing',
    });

    expect(res.status).toBe(409);
    expect(res.body.code).toBe('CONFLICT');
    expect(res.body.current).toMatchObject({
      stage: 'KYC_DOCS_RECEIVED',
      lastChangedBy: { username: 'huy' },
    });
    expect((await history(linh, c.id))[0]!.changedBy?.username).toBe('huy');
  });

  it('rejects skipping a stage and leaves no trace', async () => {
    const token = await loginAs('linh');
    const c = await vendorByName(token, 'Company C');
    const before = await history(token, c.id);

    const res = await transition(token, c.id, { toStage: 'ACTIVE', expectedFromStage: 'CONTRACT_SIGNED' });

    expect(res.status).toBe(422);
    expect(res.body.code).toBe('INVALID_TRANSITION');
    expect(await history(token, c.id)).toEqual(before);
    expect((await vendorByName(token, 'Company C')).stage).toBe('CONTRACT_SIGNED');
  });

  it('allows a backward correction only with a reason', async () => {
    const token = await loginAs('mai');
    const d = await vendorByName(token, 'Company D'); // KYC Verified

    const noReason = await transition(token, d.id, {
      toStage: 'KYC_DOCS_RECEIVED',
      expectedFromStage: 'KYC_VERIFIED',
      note: '   ',
    });
    expect(noReason.status).toBe(422);

    const withReason = await transition(token, d.id, {
      toStage: 'KYC_DOCS_RECEIVED',
      expectedFromStage: 'KYC_VERIFIED',
      note: 'ID document was blurry, Compliance asked for a new scan',
    });
    expect(withReason.status).toBe(201);
    expect((await history(token, d.id))[0]).toMatchObject({
      fromStage: 'KYC_VERIFIED',
      toStage: 'KYC_DOCS_RECEIVED',
      note: 'ID document was blurry, Compliance asked for a new scan',
    });
  });

  it('validates the request body', async () => {
    const token = await loginAs('linh');
    const c = await vendorByName(token, 'Company C');

    await transition(token, c.id, { toStage: 'Done', expectedFromStage: 'CONTRACT_SIGNED' }).expect(400);
    await transition(token, c.id, { toStage: 'KYC_DOCS_RECEIVED' }).expect(400);
    await transition(token, c.id, {
      toStage: 'KYC_DOCS_RECEIVED',
      expectedFromStage: 'CONTRACT_SIGNED',
      note: 'x'.repeat(501),
    }).expect(400);
    await transition(token, 9999, { toStage: 'KYC_DOCS_RECEIVED', expectedFromStage: 'CONTRACT_SIGNED' }).expect(404);
  });
});

describe('audit log integrity', () => {
  it('cannot be edited or deleted, even directly in the database', () => {
    expect(() => db.prepare("UPDATE stage_transitions SET note = 'rewritten'").run()).toThrow(/append-only/);
    expect(() => db.prepare('DELETE FROM stage_transitions').run()).toThrow(/append-only/);
  });
});
