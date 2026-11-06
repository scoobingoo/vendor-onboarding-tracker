# Vendor Onboarding Tracker

A small web app that replaces the shared onboarding spreadsheet. Coordinators log in, see every vendor and how long it has been in its current stage, move vendors between the five stages, and see the full history of who changed what, when, and why.

**Stack:** PHP 8.3+ · Laravel 13 · Blade (server-rendered, no JS build step) · SQLite · PHPUnit

## Running it

Requires PHP 8.3+ with the `pdo_sqlite` extension, and Composer. No Node.js needed.

```bash
composer setup     # install packages, create .env + app key, create the SQLite DB, migrate and seed
composer dev       # http://localhost:8000
```

Log in as `linh`, `huy` or `mai` with the password `password`. Seed timestamps are relative to "now", so the demo always shows a mix of stuck and healthy vendors.

| Command | What it does |
| --- | --- |
| `composer test` | Runs all tests (38) |
| `composer reset-demo` | Wipes and reseeds the local database |
| `vendor/bin/pint` | Formats the code (Laravel's style) |

Settings in `.env`: `FIXED_NOW=2026-08-10T09:00:00Z` pins the current time for a demo, `SESSION_LIFETIME` (default 720 minutes), and `DB_CONNECTION` (`sqlite` by default; `mysql`/`mariadb` also work).

> If `php -v` shows an older version than 8.3, call a newer PHP explicitly, e.g. with Laragon:
> `C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe C:\laragon\bin\composer\composer.phar setup`

---

## Problems with the spreadsheet process

1. **"Stuck" is invisible because time-in-stage depends on a hand-typed date.** "Last Updated" is only correct if someone remembers to change it, and nothing compares it to today. In the example, Company E has been at *KYC Docs Received* since 15 July and nothing draws attention to it. A vendor no one is assigned to is even easier to forget.
2. **No history, so wrong-looking data can't be explained.** Changing a cell overwrites the old value. If a vendor looks like it's in the wrong stage, there is no record of who moved it, from where, or why, only whatever someone thought to type into "Notes". KYC rejections happen in Slack, so the reason a vendor went backwards lives outside the sheet.
3. **Three people editing at once, with no rules.** Two coordinators can act on the same row at the same time and overwrite each other. Any stage can be typed into any row, so a vendor can jump from *Contract Sent* to *Active* and skip KYC with nothing to stop it.

## How the app addresses them

| Problem | What the app does |
| --- | --- |
| Invisible stuck vendors | Time in stage is **derived** from the timestamp of the vendor's last stage change. No one enters it. Vendors in a non-terminal stage for more than 7 days are highlighted, counted per stage, and sorted to the top. Unassigned vendors are flagged. |
| No history | Every stage change writes a row to an **append-only** `stage_transitions` log (who, when, from, to, note). Each vendor's panel shows the full timeline. Moving backwards requires a reason, so corrections explain themselves. The model refuses updates and deletes, and database triggers reject UPDATE/DELETE on the log even from raw SQL. |
| Concurrent edits, no rules | Stage changes use **optimistic concurrency**: the form carries the stage the user saw, and if someone else changed the vendor first, nothing is saved and the user is told who moved it and where. The workflow rules (no skipping forward, a reason required to go back) are enforced on the server. |

## Approach

Most of the effort went into modelling the workflow and its history correctly. The UI is deliberately plain: one page with a stage summary bar, a filterable table and a detail panel.

```
app/Domain/                 Domain logic, no HTTP
  Stage.php                   the five stages, labels and order (backed enum)
  Workflow.php                transition rules: check(), allowedTargets()
  StuckPolicy.php             time-in-stage and stuck calculation
  ChangeVendorStage.php       the one write: transactional, compare-and-set stage change
  VendorSummary.php           a vendor plus its derived status at one moment
  VendorList.php              "needs attention" sort and filtering
  VendorFilters.php           list filters <-> query string
app/Models/                 User, Vendor, StageTransition (append-only)
app/Http/Controllers/       LoginController, VendorController (tracker page), StageTransitionController
app/Http/Requests/          ChangeStageRequest (input validation)
database/migrations/        schema, including the append-only triggers
database/seeders/           demo data with realistic histories
resources/views/            Blade: login, tracker page, vendor panel, stage badge, local time
public/css/app.css          plain CSS
public/js/app.js            small progressive-enhancement script (the app works without it)
tests/Unit, tests/Feature   domain rules, HTTP flows, audit log integrity
```

### Data model

```
users(id, username, display_name, password, remember_token, created_at, updated_at)
vendors(id, name, region, coordinator_id, stage, stage_entered_at)
stage_transitions(id, vendor_id, from_stage, to_stage, changed_by, changed_at, note)   -- append-only
```

Plus Laravel's own `sessions`, `cache` and `jobs` tables.

`stage_transitions` is the source of truth. `vendors.stage` and `vendors.stage_entered_at` are a cache of the latest transition. They're kept because they make the list query trivial and give the concurrency check something to compare against, and they are written **in the same transaction** as the log row, so the two can't diverge. A vendor's first row has `from_stage = NULL` (Sales created it). `changed_by = NULL` means the change came from outside the app (Sales / imported).

### Routes

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/login` | |
| POST | `/login` | `username`, `password`; throttled to 10 attempts a minute |
| POST | `/logout` | |
| GET | `/` | The tracker. Filters live in the query string: `stage`, `stuck=1`, `mine=1`, `q` |
| GET | `/vendors/{vendor}` | The tracker with that vendor's panel open (same filters) |
| POST | `/vendors/{vendor}/transitions` | `to_stage`, `expected_from_stage`, `note` → redirects back to the panel with a success message, a stale-data warning, or errors |

## Key technical decisions

- **Workflow rules live in one pure class.** `ChangeVendorStage` enforces them. The form uses `Workflow::allowedTargets()` only to offer valid choices, and a test checks that the UI can never offer a move the server would reject.
- **History is append-only and is the source of truth.** Mistakes are fixed by adding a new (backward) transition, never by editing the past. That is what makes "why does this look wrong?" answerable.
- **Optimistic concurrency instead of locking.** With 3 coordinators conflicts are rare, so detecting them is cheaper and simpler than locking rows. The write is a compare-and-set (`UPDATE vendors ... WHERE id = ? AND stage = expected_from_stage`) inside a transaction, so it also catches a change that lands between reading the vendor and writing it. On a conflict the user sees what changed and decides again, rather than the second write silently winning.
- **"Stale" and "not allowed" are handled differently.** A stale view gets a warning naming who moved the vendor, and a fresh form, because the user has to look again before deciding. A rule violation keeps what the user typed and explains the rule.
- **Stuck status is computed on the server from Laravel's clock.** Every user gets the same answer, tests control time precisely with `travelTo()`, and `FIXED_NOW` makes demos repeatable. The threshold is compared against exact elapsed time (`> 7 × 24h`), not calendar days, so results don't depend on the server's timezone.
- **Server-rendered Blade, not an SPA.** No build step and no API/token layer to maintain. Filters are in the URL, so a filtered view can be shared or bookmarked. The JavaScript only improves things: local-time display, clickable rows, filters that apply on click, and the "Reason (required)" label for backward moves. The server checks everything again.
- **SQLite by default.** It needs no setup and gives real transactions and constraints. The append-only triggers are also defined for MySQL/MariaDB.

## Assumptions

- **Forward moves go one stage at a time.** Skipping, e.g. *Contract Signed → KYC Verified*, would hide that a step never happened.
- **Backward moves to any earlier stage are allowed, but need a reason.** They cover corrections (a mis-click, a vendor marked verified before Compliance actually approved it) and rollbacks. A normal KYC rejection does **not** create a transition: per the brief, the vendor simply stays at *KYC Docs Received*.
- Any logged-in coordinator can update any vendor, not just their own (they all share the sheet today). Each vendor still has an assigned coordinator, shown in the list and filterable with "My vendors".
- Vendor creation (by Sales) happens outside the app, so the initial *Contract Sent* entry is recorded with no app user.
- One stuck threshold, **more than 7 days**, applies to every non-terminal stage. *Active* is terminal and never stuck.
- Any stage change, including a backward one, resets the time-in-stage clock, because the requirement is time in the *current* stage.
- Timestamps are stored in UTC and shown in the browser's local time.

## Shortcuts and trade-offs

- **Auth is minimal:** seeded users and Laravel's session guard. Sessions end after 12 hours of inactivity and login attempts are throttled. Enough to know who is acting, nothing more.
- **No real-time updates.** A page shows the state when it was loaded, and there is a Refresh button. The concurrency check covers correctness. Live updates would only be a convenience.
- **No pagination.** Filtering and sorting happen in PHP after loading every vendor, which is fine for tens or hundreds of vendors.
- **No browser E2E tests.** The UI was checked by hand in the browser: login, stuck highlighting, history, a backward move with a blank reason (rejected by the server), filters, and a real conflict between two users.
- **The append-only triggers are only tested on SQLite.** The MySQL/MariaDB versions are written but not exercised by the test suite.
- **Notes exist only on stage changes.** There is no free-form, editable vendor note.

## What I chose to test, and why

- **`tests/Unit/WorkflowTest.php`:** the transition rules exhaustively, because they are the core business logic. Includes a consistency check between `allowedTargets()` (what the UI offers) and `check()` (what the server accepts).
- **`tests/Unit/StuckPolicyTest.php`:** the boundary (exactly 7 days is *not* stuck, 7 days + 1 ms is), *Active* never being stuck, and clock skew. This requirement is easy to get off by one.
- **`tests/Unit/VendorListTest.php`:** the "needs attention" sort (stuck first, longest-waiting on top, finished last), combined filters, and reading filters from the query string.
- **`tests/Feature/ChangeStageTest.php`:** through HTTP, against a real in-memory SQLite database and a controlled clock:
  - a stage change records who, when, from, to and the (trimmed) note;
  - stuck status resets after a move;
  - a **stale update is refused** and writes nothing, including a change that lands between the read and the write;
  - an invalid move is rejected and **leaves history untouched**;
  - backward moves need a reason;
  - input validation and 404s, and the list filters survive the redirect.
- **`tests/Feature/TrackerPageTest.php`:** derived time-in-stage and stuck status, stuck status changing as time passes, list order, query-string filters, and the history panel.
- **`tests/Feature/AuthTest.php`:** guests are redirected, wrong passwords rejected, usernames are case-insensitive, logout ends the session.
- **`tests/Feature/AuditLogTest.php`:** the audit log rejects UPDATE/DELETE even through raw SQL, and the model refuses them too.

## What I'd do next

1. **Record KYC rejections as events without a stage change.** Today a rejection keeps the vendor at *KYC Docs Received*, and the stuck timer keeps running from when the docs first arrived (Company B in the seed data). Rejections should be logged, and the team should decide whether one restarts the clock.
2. **Per-stage thresholds.** Waiting for a signature and waiting on Compliance probably have different normal durations.
3. **Assignment changes with an audit trail**, plus a team-wide activity feed ("what changed today").
4. **Roles:** Sales creates vendors, Compliance records KYC outcomes, coordinators do the rest. Today only coordinators exist. Laravel policies would fit here.
5. **At larger scale:** Postgres, server-side filtering and pagination, real auth (SSO), stuck-vendor notifications (a scheduled command plus Laravel notifications), and browser tests for the main flows.
