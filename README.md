# Vendor Onboarding Tracker

A small web app that replaces the Ops team's shared onboarding spreadsheet. Coordinators log in, see every vendor with its current stage and how long it has been there, and move vendors through the five stages (*Contract Sent → Contract Signed → KYC Docs Received → KYC Verified → Active*). They can also see who changed what, when, and why. Vendors that have been in a stage for more than 7 days are highlighted.

**Stack:** PHP 8.3+ · Laravel 13 · Blade (server-rendered, no JS build step) · SQLite · PHPUnit

## Running it

Requires PHP 8.3+ with the `pdo_sqlite` extension, and Composer. No Node.js or database server needed.

```bash
composer setup     # install packages, create .env and the app key, create the SQLite database, migrate and seed the demo data
composer dev       # run the app at http://localhost:8000
composer test      # run all tests (38)
```

Log in as `linh`, `huy` or `mai`, the three coordinators from the example sheet. The password is `password`.

| Other commands | What it does |
| --- | --- |
| `composer reset-demo` | Wipes the local database and seeds the demo data again |
| `vendor/bin/pint` | Formats the code (Laravel's style) |

The seed data is the example spreadsheet from the brief (Companies A–F) plus two extra vendors. Timestamps are relative to "now", so whenever you seed, each vendor has been in its stage as long as in the sheet read on 10 Aug 2026.

To see the sheet's exact dates, set `FIXED_NOW=2026-08-10T09:00:00Z` in `.env` and run `composer reset-demo`. This also freezes the clock, so every change you make is recorded at that moment.

Other settings in `.env`:

- `SESSION_LIFETIME`: default 720 minutes.
- `DB_CONNECTION`: `sqlite` by default; `mysql` and `mariadb` also work.

> If `php -v` shows a version older than 8.3, run each command with a newer PHP explicitly. For example, with Laragon:
> `C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe C:\laragon\bin\composer\composer.phar setup` (then the same with `dev` and `test`)

---

## Status against the brief

All four requirements are done.

| Requirement | How it works |
| --- | --- |
| **1. Login** | Seeded coordinator accounts and a session login. The header shows who is signed in, and every change is recorded under that user. |
| **2. View vendors** | One table shows each vendor's region, stage, time in stage and coordinator, plus the last change (who made it and its note). A bar above the table counts vendors, and stuck vendors, per stage. |
| **3. Update a vendor's stage** | Click a vendor, choose the new stage, then click *Update stage*. The app records who made the change, when, the previous stage and the new stage, plus an optional note. The user never types any of these. The vendor's panel shows its full history, newest first. |
| **4. Identify stuck vendors** | Time in stage is derived from the last stage change. A vendor that has been in any stage except *Active* for more than 7 days is stuck. Its row is highlighted and sorted to the top, and each stage shows its stuck count. The threshold is hardcoded (`StuckPolicy::STUCK_AFTER_DAYS`) and the current time can be pinned (`FIXED_NOW`). |

**Added beyond the four requirements**, because they address the problems below:

- workflow rules: no skipping forward, and a reason is required to move back;
- protection against two coordinators overwriting each other;
- list filters: stage, stuck only, my vendors, and search.

**Not built**, because the brief puts these out of scope:

- vendor creation and deletion;
- registration and account management;
- production-grade authentication;
- email;
- the Compliance review and activation systems;
- notifications and real-time updates;
- deployment.

**What remains:** nothing from the requirements. Known gaps are listed under [shortcuts](#6-shortcuts-and-trade-offs) and [what I'd improve](#7-what-id-improve-with-more-time).

---

## Write-up

### 1. Problems with the spreadsheet process

I read the example sheet as of 10 Aug 2026, a few days after its last update.

1. **Stuck vendors go unnoticed.**
   - How long a vendor has been in a stage depends on a hand-typed "Last Updated" date, and nothing compares that date to today.
   - In the example, four of the five vendors still in progress (B, C, D, E) have been in their stage for more than a week. Company E has been at *KYC Docs Received* for 26 days. Nothing in the sheet points this out.
   - The date is also unreliable. It is only right if someone remembers to change it, and it may record a note edit rather than a stage change. Company B's 18 July could be when its documents arrived or when the note was added.
2. **There is no history, so data that looks wrong can't be explained.**
   - Editing a cell overwrites the old value.
   - If a vendor seems to be in the wrong stage, nothing records who moved it, from where, when, or why. All you have is whatever someone typed into Notes.
   - KYC rejections are discussed in Slack, so the reason a vendor is back at *KYC Docs Received* is kept outside the sheet.
3. **Three coordinators edit the same sheet with no rules.**
   - Two people can update the same row at the same time, and the last one silently wins.
   - Any value can be typed into Stage. A vendor can jump from *Contract Sent* to *Active* and skip KYC, and nothing stops it.

### 2. How the app addresses them

**Stuck vendors go unnoticed**

- Time in stage is **derived** from the vendor's last stage change. Nobody types a date.
- Stuck vendors are highlighted, counted per stage and sorted to the top.
- Vendors with no coordinator assigned are flagged.

**No history**

- Every stage change is written to an **append-only** log: who, when, from, to, and a note.
- The log row is written in the same transaction as the change, and each vendor shows its log as a timeline.
- Moving backwards requires a reason, so corrections explain themselves.
- The log can't be edited. The model refuses, and database triggers reject UPDATE and DELETE even from raw SQL.

**Concurrent edits with no rules**

- The server enforces the workflow rules.
- Each update carries the stage the user saw (**optimistic concurrency**). If someone else changed the vendor first, nothing is saved, and the user is told who moved it and to which stage.

### 3. Overall approach

I modelled the workflow and its history first and kept the UI plain.

- **The domain is a few small classes with no HTTP code** (`app/Domain`). They hold the five stages, the transition rules and the stuck calculation. `ChangeVendorStage` is the only code that changes a vendor's stage.
- **History is the source of truth.** `stage_transitions` holds every change. `vendors.stage` and `vendors.stage_entered_at` are a cache of the latest row. I kept the cache because it makes the list query trivial and gives the concurrency check something to compare against. It is written in the same transaction as the log row, so the two can't diverge.
- **There is one page, plus sign-in, as the brief allows.** It has the stage summary bar, the vendor table, and a panel with the selected vendor's history and the stage form. The server renders it; a small script only improves it.

The data model:

```
users(id, username, display_name, password, remember_token, created_at, updated_at)
vendors(id, name, region, coordinator_id, stage, stage_entered_at)
stage_transitions(id, vendor_id, from_stage, to_stage, changed_by, changed_at, note)   -- append-only
```

A vendor's first transition has `from_stage = NULL` because Sales added the vendor. `changed_by = NULL` means the change came from outside the app (Sales, or the imported sheet).

Where the code lives:

```
app/Domain/          Stage, Workflow (rules), StuckPolicy, ChangeVendorStage (the write),
                     VendorSummary / VendorList / VendorFilters (the list and its sort order)
app/Models/          User, Vendor, StageTransition (append-only)
app/Http/            LoginController, VendorController (the page), StageTransitionController,
                     ChangeStageRequest (input validation)
database/            migrations (including the append-only triggers), seeder (the example sheet)
resources/views/     Blade: sign-in, tracker page, vendor panel
public/              css/app.css, js/app.js (progressive enhancement; the app works without it)
tests/               Unit (domain rules) and Feature (HTTP flows, audit log)
```

### 4. Important technical decisions

- **Laravel with server-rendered Blade, not an SPA.** For one page this is the simplest option: no build step and no API layer. Filters live in the URL, so a filtered view can be bookmarked or shared.
- **Append-only history.** A mistake is fixed by adding a new, backward transition, never by editing the past. This is what makes "why does this look wrong?" answerable.
- **The workflow rules live in one pure class (`Workflow`).** The form uses it to offer only valid moves, and the server uses it to enforce them. A test checks that the form never offers a move the server would reject.
- **Optimistic concurrency instead of locking.** With three coordinators, conflicts are rare, so detecting them is simpler than locking rows.
  - The write is a compare-and-set, `UPDATE vendors … WHERE id = ? AND stage = <stage the user saw>`, inside a transaction.
  - This also catches a change that lands between reading the vendor and writing it.
- **A stale view and a broken rule get different responses.**
  - A stale view gets a warning that names who moved the vendor, plus a fresh form, because the user has to look again before deciding.
  - A broken rule keeps what the user typed and explains the rule.
- **Stuck status is computed on the server from one clock.** Everyone sees the same result, tests control time precisely with `travelTo()`, and `FIXED_NOW` makes demos repeatable. The threshold compares exact elapsed time (more than 7 × 24 hours), so the result doesn't depend on time zones.
- **SQLite by default.** It needs no setup and has real transactions and constraints. The append-only triggers are also written for MySQL and MariaDB.

### 5. Assumptions

- **Forward moves go one stage at a time.** Skipping one, e.g. *Contract Signed → KYC Verified*, would hide that a step never happened.
- **Backward moves to any earlier stage are allowed, with a reason.**
  - They are for corrections, e.g. a vendor marked verified before Compliance actually approved it (Company B in the seed data).
  - A normal KYC rejection is **not** a stage change. As the brief says, the vendor simply stays at *KYC Docs Received*.
- **Stuck means more than 7 days in the current stage**, with the same threshold for every stage.
  - *Active* is the final stage and is never stuck.
  - Any stage change, including a backward one, restarts the clock, because the requirement is time in the *current* stage.
- **Any coordinator can update any vendor**, as with the shared sheet today. Each vendor still has an assigned coordinator, shown in the list and filterable with "My vendors".
- **Sales adds vendors outside the app**, so a vendor's first *Contract Sent* entry has no app user.
- **"Today" for the example sheet is 10 Aug 2026**, a few days after its last update. The seed data and tests use this date.
- Timestamps are stored in UTC and shown in the browser's local time.

### 6. Shortcuts and trade-offs

These were made to stay within the time limit:

- **Minimal auth.** Seeded users and Laravel's session login. Sessions end after 12 hours of inactivity, and login attempts are throttled. This is enough to know who is acting, and nothing more.
- **No real-time updates.** A page shows the state at the time it was loaded, and there is a Refresh button. The concurrency check keeps the data correct; live updates would only be a convenience.
- **No pagination.** Filtering and sorting happen in PHP after loading every vendor. That is fine for tens or hundreds of vendors.
- **Notes exist only on stage changes.** There is no free-form vendor note, so a status like "waiting on re-upload" can't be recorded without moving the vendor.
- **No browser tests.** I checked the UI by hand:
  - login and stuck highlighting;
  - the history panel;
  - a backward move with a blank reason, which the server rejects;
  - filters;
  - a real conflict between two users.
- **The MySQL and MariaDB triggers are untested.** The test suite runs on SQLite only.

### 7. What I'd improve with more time

1. **Record KYC rejections without a stage change.** Today a rejection leaves no trace, and the stuck timer keeps running from when the documents first arrived. Rejections should be logged, and the team should decide whether one restarts the clock.
2. **Per-stage thresholds.** Waiting for a signature and waiting on Compliance probably have different normal durations.
3. **Reassigning vendors, with an audit trail**, and a team-wide activity feed ("what changed today").
4. **Roles.** Sales creates vendors, Compliance records KYC outcomes, and coordinators do the rest. Laravel policies would fit this.
5. **At larger scale:**
   - Postgres, with filtering and pagination done in the database;
   - single sign-on (SSO);
   - stuck-vendor notifications from a scheduled command;
   - browser tests for the main flows.

### 8. How I used AI

I used Claude Code (Anthropic's AI coding agent) as a pair programmer to move fast within the 3-hour timebox. I owned the technical direction, architectural trade-offs, and acceptance criteria, while the AI handled most of the code generation. Nothing counted as done until I verified it against the brief.

- **Analysis and design.** Starting from the brief, I used the AI to brainstorm the problem analysis and converged on three core design decisions to solve the spreadsheet's bottlenecks:
  - an append-only audit log of stage changes;
  - optimistic concurrency control for multiple coordinators editing at the same time;
  - server-side calculation for "stuck" vendor detection.

  I critically reviewed each choice and its trade-offs against the brief's scope before locking in the design.
- **Stack change.** The initial prototype was in TypeScript (React and Express). After reviewing it, I decided to pivot to PHP/Laravel. Because this is a workflow app centered around data integrity and an audit trail, Laravel's Eloquent ORM, migrations, and built-in testing tools produced a much smaller, more maintainable codebase. I directed Claude Code to carry out the rewrite while preserving the exact same business rules and test scenarios.
- **Implementation and edge cases.** While the AI generated the application code and UI, I focused on validating the workflow rules and edge cases. In addition to automated tests, I manually verified the riskiest scenarios in a real browser—specifically blocking backward stage transitions without a reason, and handling race conditions when two coordinators update the same vendor simultaneously.
- **Testing.** I defined the test strategy around the highest business risks and had the AI generate the test implementation:
  - two coordinators updating the same vendor at once (concurrency conflict);
  - stage transition history being recorded accurately (who, when, from/to stage);
  - the "stuck" threshold being computed deterministically under a frozen clock.
- **Verification checklist.** Before submitting, I audited the test coverage and ran the project through a strict release checklist: verifying that a fresh clone installs, seeds, and passes all tests cleanly by following this README across both PHP 8.3 and 8.5, with no secrets committed.

---

## Tests: what I chose to test and why

There are 38 tests (`composer test`). I focused on the places where a mistake would be costly or easy to make.

**The workflow rules** (`tests/Unit/WorkflowTest.php`)

- Tested exhaustively, because they are the core business logic.
- Includes a check that every move the form offers (`allowedTargets()`) is one the server accepts (`check()`).

**The stuck rule** (`tests/Unit/StuckPolicyTest.php`)

- Exactly 7 days is *not* stuck; 7 days and 1 ms is.
- *Active* is never stuck.
- A timestamp slightly in the future counts as 0 days.
- This requirement is easy to get off by one, so the boundary is tested precisely.

**Stage changes through HTTP** (`tests/Feature/ChangeStageTest.php`), against a real SQLite database with a controlled clock:

- a change records who made it, when, the previous and new stage, and the trimmed note;
- moving a stuck vendor resets its clock;
- a **stale update is refused** and writes nothing, including a change that lands between the read and the write;
- skipping a stage is rejected and **leaves the history untouched**;
- a backward move needs a reason that isn't blank;
- bad input and unknown vendors are rejected;
- the list filters survive the redirect.

**The tracker page** (`tests/Feature/TrackerPageTest.php`)

- The derived time in stage reproduces the example sheet's "Last Updated" column.
- Stuck status changes as time passes, without anyone editing anything.
- Stuck vendors are listed first, and filters work.
- The history panel shows every change, newest first.

**The audit log** (`tests/Feature/AuditLogTest.php`) rejects UPDATE and DELETE, both through the model and in raw SQL.

**Login** (`tests/Feature/AuthTest.php`)

- Guests are redirected to the login page.
- Wrong passwords are rejected.
- Usernames are case-insensitive.
- Logging out ends the session.

**List order and filters** (`tests/Unit/VendorListTest.php`)

- Stuck vendors come first, longest-waiting on top, and finished vendors come last.
- Filters combine correctly and are read from the query string.

## Appendix: routes

| Method | Path | Notes |
| --- | --- | --- |
| GET, POST | `/login` | `username`, `password`. Throttled to 10 attempts a minute. |
| POST | `/logout` | |
| GET | `/` | The tracker. Filters go in the query string: `stage`, `stuck=1`, `mine=1`, `q`. |
| GET | `/vendors/{vendor}` | The tracker with that vendor's panel open, keeping the filters. |
| POST | `/vendors/{vendor}/transitions` | Takes `to_stage`, `expected_from_stage` and `note`. Redirects back to the panel with a success message, a stale-data warning, or errors. |
