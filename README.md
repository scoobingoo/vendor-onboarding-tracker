# Vendor Onboarding Tracker

A small web app that replaces the shared onboarding spreadsheet. Coordinators log in, see every vendor and how long it has been in its current stage, move vendors between the five stages, and see the full history of who changed what, when, and why.

**Stack:** TypeScript throughout · React 19 + Vite (SPA) · Express 5 · SQLite (better-sqlite3) · Vitest + Supertest

## Running it

Requires Node 20+.

```bash
npm install
npm run dev        # API on :3001, UI on http://localhost:5173
```

Log in as `linh`, `huy` or `mai` with the password `password`. The database (`data/tracker.db`) is created and seeded on first start. Seed timestamps are relative to "now", so the demo always shows a mix of stuck and healthy vendors.

| Command | What it does |
| --- | --- |
| `npm test` | Runs all tests (31) |
| `npm run typecheck` | Type-checks server, client and tests |
| `npm run seed` | Wipes and reseeds the local database |
| `npm run build && npm start` | Production-style run: Express serves the built SPA on :3001 |

Optional env vars: `API_PORT` (default 3001), `DB_PATH`, and `FIXED_NOW=2026-08-10T09:00:00Z` to pin the current time for a demo.

---

## Problems with the spreadsheet process

1. **"Stuck" is invisible because time-in-stage depends on a hand-typed date.** "Last Updated" is only correct if someone remembers to change it, and nothing compares it to today. In the example, Company E has been at *KYC Docs Received* since 15 July and nothing draws attention to it. A vendor no one is assigned to is even easier to forget.
2. **No history, so wrong-looking data can't be explained.** Changing a cell overwrites the old value. If a vendor looks like it's in the wrong stage, there is no record of who moved it, from where, or why, only whatever someone thought to type into "Notes". KYC rejections happen in Slack, so the reason a vendor went backwards lives outside the sheet.
3. **Three people editing at once, with no rules.** Two coordinators can act on the same row at the same time and overwrite each other. Any stage can be typed into any row, so a vendor can jump from *Contract Sent* to *Active* and skip KYC with nothing to stop it.

## How the app addresses them

| Problem | What the app does |
| --- | --- |
| Invisible stuck vendors | Time in stage is **derived** from the timestamp of the vendor's last stage change. No one enters it. Vendors in a non-terminal stage for more than 7 days are highlighted, counted per stage, and sorted to the top. Unassigned vendors are flagged. |
| No history | Every stage change writes a row to an **append-only** `stage_transitions` log (who, when, from, to, note). Each vendor's panel shows the full timeline. Moving backwards requires a reason, so corrections explain themselves. Database triggers reject UPDATE/DELETE on the log. |
| Concurrent edits, no rules | Stage changes use **optimistic concurrency**: the client sends the stage it thinks the vendor is in, and if someone else changed it first, the server returns `409` and the UI shows who changed it. The workflow rules (no skipping forward, a reason required to go back) are enforced on the server. |

## Approach

Most of the effort went into modelling the workflow and its history correctly. The UI is deliberately plain: one page with a stage summary bar, a filterable table and a detail panel.

```
shared/        Pure domain logic, used by both server and client
  workflow.ts    stages, labels, transition rules (checkTransition, allowedTargets)
  stuck.ts       time-in-stage and stuck calculation
  types.ts       API contracts
server/
  db.ts          schema (incl. append-only triggers)
  vendors.ts     reads + the transactional stage change
  auth.ts        seeded users, scrypt hashes, bearer-token sessions
  app.ts         Express routes, validation, HTTP error mapping
  clock.ts       injectable clock
  seed.ts        demo data with realistic histories
client/src/
  components/    LoginForm, VendorTracker (list), VendorPanel (detail + history), StageChangeForm
  vendorList.ts  filtering / "needs attention" sort
tests/           domain, API integration, list ordering
```

### Data model

```
users(id, username, display_name, password_hash)
sessions(token, user_id, created_at)
vendors(id, name, region, coordinator_id, stage, stage_entered_at)
stage_transitions(id, vendor_id, from_stage, to_stage, changed_by, changed_at, note)   -- append-only
```

`stage_transitions` is the source of truth. `vendors.stage` and `vendors.stage_entered_at` are a cache of the latest transition. They're kept because they make the list query trivial and give the concurrency check something to compare against, and they are written **in the same transaction** as the log row, so the two can't diverge. A vendor's first row has `from_stage = NULL` (Sales created it). `changed_by = NULL` means the change came from outside the app (Sales / imported).

### API

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/auth/login` | `{ username, password }` → `{ token, user }` |
| POST | `/api/auth/logout` | |
| GET | `/api/auth/me` | |
| GET | `/api/vendors` | Vendors with derived `daysInStage` / `isStuck`, plus `now` and the threshold |
| GET | `/api/vendors/:id/history` | Newest first |
| POST | `/api/vendors/:id/transitions` | `{ toStage, expectedFromStage, note? }` → `201` / `400` bad input / `404` / `409` stale / `422` rule violated |

## Key technical decisions

- **Workflow rules live in one pure module shared by client and server.** The server is the authority. The client uses the same functions only to offer valid choices and give instant feedback, and a test checks that the UI can never offer a move the server would reject.
- **History is append-only and is the source of truth.** Mistakes are fixed by adding a new (backward) transition, never by editing the past. That is what makes "why does this look wrong?" answerable.
- **Optimistic concurrency instead of locking.** With 3 coordinators conflicts are rare, so detecting them (`expectedFromStage` + compare-and-set inside an `IMMEDIATE` transaction) is cheaper and simpler than locking rows. On a conflict the user sees what changed and decides again, rather than the second write silently winning.
- **Stuck status is computed on the server from an injectable clock.** Every client gets the same answer, tests control time precisely, and `FIXED_NOW` makes demos repeatable. The threshold is compared against exact elapsed time (`> 7 × 24h`), not calendar days, so results don't depend on the server's timezone.
- **`409` vs `422`.** "Your data is stale" (refresh and retry) and "that move isn't allowed" (fix your request) need different UI handling, so they get different status codes.
- **SQLite.** It needs no setup to run and gives real transactions and constraints. Moving to Postgres would only touch the data layer.

## Assumptions

- **Forward moves go one stage at a time.** Skipping, e.g. *Contract Signed → KYC Verified*, would hide that a step never happened.
- **Backward moves to any earlier stage are allowed, but need a reason.** They cover corrections (a mis-click, a vendor marked verified before Compliance actually approved it) and rollbacks. A normal KYC rejection does **not** create a transition: per the brief, the vendor simply stays at *KYC Docs Received*.
- Any logged-in coordinator can update any vendor, not just their own (they all share the sheet today). Each vendor still has an assigned coordinator, shown in the list and filterable with "My vendors".
- Vendor creation (by Sales) happens outside the app, so the initial *Contract Sent* entry is recorded with no app user.
- One stuck threshold, **more than 7 days**, applies to every non-terminal stage. *Active* is terminal and never stuck.
- Any stage change, including a backward one, resets the time-in-stage clock, because the requirement is time in the *current* stage.
- Timestamps are stored in UTC and shown in the browser's local time.

## Shortcuts and trade-offs (time-boxed to ~3h)

- **Auth is minimal:** seeded users, bearer tokens in `localStorage`, 12-hour sessions, no rate limiting. Enough to know who is acting, nothing more.
- **No real-time updates.** The list refetches on window focus, after every change, and via a Refresh button. Optimistic concurrency covers correctness. Live updates would only be a convenience.
- **No pagination or server-side filtering.** Filtering happens in the browser, which is fine for tens or hundreds of vendors.
- **No frontend component or E2E tests.** The logic worth testing lives in `shared/`, the server, and `vendorList.ts`. The UI was checked by hand in the browser (login, stuck highlighting, history, backward move with reason, and a real conflict between two users).
- **Schema is created with `CREATE TABLE IF NOT EXISTS`** rather than a migration tool.
- **Notes exist only on stage changes.** There is no free-form, editable vendor note.

## What I chose to test, and why

- **`tests/workflow.test.ts`:** the transition rules exhaustively, because they are the core business logic and both sides depend on them. Includes a consistency check between `allowedTargets` (what the UI offers) and `checkTransition` (what the server accepts).
- **`tests/stuck.test.ts`:** the boundary (exactly 7 days is *not* stuck, 7 days + 1 ms is), *Active* never being stuck, and clock skew. This requirement is easy to get off by one.
- **`tests/api.test.ts`:** HTTP-level tests against a real in-memory SQLite database and a controllable clock:
  - a stage change records who, when, from, to and the (trimmed) note;
  - stuck status changes as time passes and resets after a move;
  - a **stale update returns 409** and writes nothing;
  - an invalid move returns 422 and **leaves history untouched**;
  - backward moves need a reason;
  - input validation, 404s, auth, session expiry and logout;
  - the audit log rejects UPDATE/DELETE even through direct SQL.
- **`tests/vendorList.test.ts`:** the "needs attention" sort (stuck first, longest-waiting on top, finished last) and combined filters, because this order is how coordinators decide what to work on.

## What I'd do next

1. **Record KYC rejections as events without a stage change.** Today a rejection keeps the vendor at *KYC Docs Received*, and the stuck timer keeps running from when the docs first arrived (Company B in the seed data). Rejections should be logged, and the team should decide whether one restarts the clock.
2. **Per-stage thresholds.** Waiting for a signature and waiting on Compliance probably have different normal durations.
3. **Assignment changes with an audit trail**, plus a team-wide activity feed ("what changed today").
4. **Roles:** Sales creates vendors, Compliance records KYC outcomes, coordinators do the rest. Today only coordinators exist.
5. **At larger scale:** Postgres with migrations, server-side filtering and pagination, real auth (SSO), stuck-vendor notifications, and Playwright E2E tests for the main flows.
