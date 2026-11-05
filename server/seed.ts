import type { DB } from './db.js';
import { hashPassword } from './auth.js';
import type { Stage } from '../shared/workflow.js';

/**
 * Demo data. Timestamps are relative to "now" so the stuck highlighting looks
 * the same whenever the app is started. Each vendor gets a full history, as
 * if the spreadsheet had been migrated with its past changes.
 */

export const SEED_PASSWORD = 'password';

const USERS = [
  { username: 'linh', displayName: 'Linh Nguyen' },
  { username: 'huy', displayName: 'Huy Tran' },
  { username: 'mai', displayName: 'Mai Pham' },
] as const;

type Username = (typeof USERS)[number]['username'];

/** [stage entered, days ago, who moved it (null = Sales/imported), note] */
type HistoryEntry = [Stage, number, Username | null, string?];

interface SeedVendor {
  name: string;
  region: string;
  coordinator: Username | null;
  history: HistoryEntry[];
}

const VENDORS: SeedVendor[] = [
  {
    name: 'Company A',
    region: 'HCMC',
    coordinator: 'linh',
    history: [
      ['CONTRACT_SENT', 40, null],
      ['CONTRACT_SIGNED', 35, 'linh'],
      ['KYC_DOCS_RECEIVED', 30, 'linh'],
      ['KYC_VERIFIED', 26, 'linh', 'Approved by Compliance on Slack.'],
      ['ACTIVE', 24, 'linh'],
    ],
  },
  {
    // Shows how the audit trail explains data that "looks wrong".
    name: 'Company B',
    region: 'Can Tho',
    coordinator: 'huy',
    history: [
      ['CONTRACT_SENT', 30, null],
      ['CONTRACT_SIGNED', 26, 'huy'],
      ['KYC_DOCS_RECEIVED', 22, 'huy'],
      ['KYC_VERIFIED', 19, 'mai'],
      [
        'KYC_DOCS_RECEIVED',
        18,
        'huy',
        'Marked verified by mistake: Compliance rejected the business license (expired). Waiting on re-upload.',
      ],
    ],
  },
  {
    name: 'Company C',
    region: 'Hanoi',
    coordinator: 'linh',
    history: [
      ['CONTRACT_SENT', 12, null],
      ['CONTRACT_SIGNED', 9, 'linh'],
    ],
  },
  {
    name: 'Company D',
    region: 'HCMC',
    coordinator: 'mai',
    history: [
      ['CONTRACT_SENT', 20, null],
      ['CONTRACT_SIGNED', 15, 'mai'],
      ['KYC_DOCS_RECEIVED', 10, 'mai'],
      ['KYC_VERIFIED', 3, 'mai'],
    ],
  },
  {
    name: 'Company E',
    region: 'Da Nang',
    coordinator: 'huy',
    history: [
      ['CONTRACT_SENT', 28, null],
      ['CONTRACT_SIGNED', 25, 'huy'],
      ['KYC_DOCS_RECEIVED', 21, 'huy'],
    ],
  },
  {
    name: 'Company F',
    region: 'HCMC',
    coordinator: 'mai',
    history: [['CONTRACT_SENT', 1, null]],
  },
  {
    // Nobody has picked this vendor up, which is easy to miss in a spreadsheet.
    name: 'Company G',
    region: 'Hai Phong',
    coordinator: null,
    history: [['CONTRACT_SENT', 8, null, 'Signed by Sales; no coordinator assigned yet.']],
  },
  {
    name: 'Company H',
    region: 'Hanoi',
    coordinator: 'linh',
    history: [
      ['CONTRACT_SENT', 6, null],
      ['CONTRACT_SIGNED', 2, 'linh'],
    ],
  },
];

const MS_PER_HOUR = 60 * 60 * 1000;

export function seed(db: DB, now: Date): void {
  const insertUser = db.prepare(
    'INSERT INTO users (username, display_name, password_hash) VALUES (?, ?, ?)',
  );
  const insertVendor = db.prepare(
    'INSERT INTO vendors (name, region, coordinator_id, stage, stage_entered_at) VALUES (?, ?, ?, ?, ?)',
  );
  const insertTransition = db.prepare(
    `INSERT INTO stage_transitions (vendor_id, from_stage, to_stage, changed_by, changed_at, note)
     VALUES (?, ?, ?, ?, ?, ?)`,
  );

  db.transaction(() => {
    const userIds = new Map<Username, number>();
    for (const u of USERS) {
      const { lastInsertRowid } = insertUser.run(u.username, u.displayName, hashPassword(SEED_PASSWORD));
      userIds.set(u.username, Number(lastInsertRowid));
    }
    const idOf = (u: Username | null) => (u ? userIds.get(u)! : null);

    VENDORS.forEach((v, vendorIdx) => {
      // Offset by a few hours per vendor so timestamps don't all line up.
      const at = (daysAgo: number) =>
        new Date(now.getTime() - (daysAgo * 24 + 2 + vendorIdx) * MS_PER_HOUR).toISOString();

      const [lastStage, lastDaysAgo] = v.history.at(-1)!;
      const { lastInsertRowid } = insertVendor.run(
        v.name,
        v.region,
        idOf(v.coordinator),
        lastStage,
        at(lastDaysAgo),
      );

      let previous: Stage | null = null;
      for (const [stage, daysAgo, by, note] of v.history) {
        insertTransition.run(lastInsertRowid, previous, stage, idOf(by), at(daysAgo), note ?? null);
        previous = stage;
      }
    });
  })();
}

export function isEmpty(db: DB): boolean {
  const row = db.prepare('SELECT COUNT(*) AS n FROM users').get() as { n: number };
  return row.n === 0;
}
