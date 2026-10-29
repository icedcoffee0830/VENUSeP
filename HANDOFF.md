# VENUSeP — Laravel port: hand-off

Written 2026-10-30 by Claude for whoever picks this up next (a teammate or another Claude
session). It describes the state when the usage limit stopped the work. **Things may have
moved since** — the "Check the live state" commands below tell you what is true *now*.

All of this lives on **Aron's laptop only**. Nothing here is pushed to GitHub yet.

---

## 1. What this is, in one paragraph

The team agreed (2026-10-29) to move VENUSeP from plain PHP to **Laravel 12**. Every page of
the site has been ported and all 291 tests pass. The port is *faithful*: same look, same
behaviour, same SQL and stored procedures. The old plain-PHP site is untouched and still
works. Code is not much shorter yet (31.6k → 30.7k lines of page code) — shrinking it is the
next phase, deliberately not started.

## 2. Where everything is

| What | Where |
|---|---|
| The Laravel app | `C:\xamp5\htdocs\VENUSeP-laravel\laravel\` (git branch `laravel`) |
| The rules every porter followed | `C:\xamp5\htdocs\VENUSeP-laravel\LARAVEL-PORT.md` — **read this first** |
| The old plain-PHP site | `C:\xamp5\htdocs\VENUSeP\` (branch `Aron`) — untouched by the port |
| Fixes for 6 old-site bugs | branch `bugfixes` (commit `8164f6a`), worktree `C:\xamp5\htdocs\VENUSeP-laravel-helpers\fixes` — **not merged into `Aron` yet** |
| Flow tester (in progress) | branch `lv-flows`, worktree `C:\xamp5\htdocs\VENUSeP-laravel-helpers\flows` |
| Helper worktrees (finished, already merged) | `C:\xamp5\htdocs\VENUSeP-laravel-helpers\<area>` on branches `lv-auth`, `lv-cbook`, `lv-cacct`, `lv-abook`, `lv-venues`, `lv-admina`, `lv-adminb`, `lv-tester` — safe to delete |
| Composer + DB snapshot | `C:\xamp5\htdocs\VENUSeP-laravel-helpers\_tools\` (`composer.phar`, `venusep_snapshot.sql`) |

The `VENUSeP-laravel` folder and the helper folders are **git worktrees** of the same
repository as `VENUSeP` — one `.git`, several checked-out branches side by side.

### Databases (MySQL on XAMPP)

| Database | Used for |
|---|---|
| `venusep` | **the real one** (the old site). The Laravel work never touches it. |
| `venusep_laravel` | the Laravel app when you run it (a copy of the real one, migration 13) |
| `venusep_laravel_test` | `php artisan test` in the `laravel` branch |
| `venusep_laravel_test_<area>` | each helper's own test copy (can be dropped) |
| `venusep_laravel_flows`, `venusep_laravel_test_flows` | the flow tester (it resets `venusep_laravel_flows` itself every run) |

All copies are seeded from `_tools\venusep_snapshot.sql`.

## 3. How to run things

Start MySQL from the XAMPP Control Panel first. Then, in **cmd**:

```
cd C:\xamp5\htdocs\VENUSeP-laravel\laravel

C:\xamp5\php\php.exe artisan serve                  :: the site -> http://127.0.0.1:8000
C:\xamp5\php\php.exe artisan test                   :: all tests (about 291, a few minutes)
C:\xamp5\php\php.exe artisan venusep:check --db=venusep_laravel_test
                                                    :: opens every page as guest/customer/staff/admin
```

`venusep:check` writes an HTML report to `laravel\storage\app\venusep-check\`. On the last run:
248 OK, 0 not ported, 2 "failing" that are one known item (see §6), 15 "needs params" (pages
that need a receipt or uploaded file the test data doesn't have — expected).

Demo logins are the same as the old site. Admin accounts ask for an authenticator code.

**Easiest: double-click `C:
mp5\htdocs\VENUSeP-laravelun-tests.bat`.** It checks MySQL is up, runs the page checker, then the browser flow tester, prints a PASS/problems summary and opens both HTML reports. From cmd you can add flow-tester options: `run-tests.bat --headed` (watch the browser) or `run-tests.bat --only=venue-booking-to-receipt`. The page checker currently reports 2 "failing" = the known FAQ-editor link (see §7) — expected.

The browser flow tester on its own: `C:
mp5\php\php.exe artisan venusep:flows` (`--headed` to watch). It resets its own database (`venusep_laravel_flows`), serves the app on port 8120, sets every demo account's password to `FlowTest#2026` IN THAT COPY ONLY, and writes a report with a screenshot per step to `laravel\storage\appenusep-flows\`. How to add a flow: the "Flow tester" section of `LARAVEL-PORT.md`.

## 4. How the Laravel app is built (the parts that surprise people)

- **The old `includes/` files run unchanged** from `laravel/legacy/includes/`. Two of them are
  rewritten as Laravel shims with the *same function names*: `db.php` (gives back Laravel's
  database connection) and `auth.php` (uses Laravel's session).
- **Load them with `legacy()`**, never `include`: `extract(legacy('venue-rooms', 'pricing'));`
  gives you `$venueRooms` etc. exactly like the old `include` did. Details in
  `laravel/app/Support/helpers.php`.
- **Pages** are controllers in `laravel/app/Http/Controllers/` + Blade views in
  `laravel/resources/views/`, extending `layouts.admin` or `layouts.customer` (the shared
  header/sidebar). Routes: one file per area in `laravel/routes/areas/`.
- **Sign-in guards** are middleware: `staff`, `staff:admin`, `customer`, `booking.access`.
- **CSRF** is Laravel's own check on every POST. A script that posts with an expired token
  gets the old JSON reply (400 `{"ok":false,"error":"csrf",...}`) — set up once in
  `laravel/bootstrap/app.php`. Never exempt a route from the CSRF check.
- **Tests** run each test method in its own PHP process (needed because the old files cache
  things in `static` variables). Sign a test in with `actingAsAdmin()`, `actingAsStaff()`,
  `actingAsCustomer()` from `tests/TestCase.php`.

## 5. What was happening when the limit hit

1. **Flow tester — DONE (2026-10-30).** All four process flows are merged into `laravel` and pass: venue booking → receipt (12 steps), hostel + walk-in counter (22), refund + rejected receipt (12), admin settings (11) — 57 steps, about 3 minutes, via `run-tests.bat` or `artisan venusep:flows`. The admin flow found a real regression from the port (Staff/Customer Management sent the CSRF token under the old `csrf` name, so Suspend etc. failed) — fixed and verified. The branches `lv-flows*` and their worktrees can be deleted. What each flow does, for reference (original plan):
   Aron asked for a tester that walks through whole *processes* in a real browser, not just
   page loads. Planned command: `php artisan venusep:flows` (option `--headed` to watch).
   It resets `venusep_laravel_flows`, serves the app on port **8120**, drives Edge with
   Playwright (already on the laptop via the global `@playwright/cli` npm package — see
   `laravel/tests/browser/check-pages.mjs` for how it's loaded; install nothing), takes a
   screenshot per step, and writes a report to `laravel\storage\app\venusep-flows\`.
   - Part 1 (being built): the harness + the **venue booking → receipt** flow
     (customer registers, books with ID upload, staff approves, customer pays by GCash,
     staff confirms, receipt + PDF appear).
   - Parts 2–4 (not started, each one new file in `laravel/tests/flows/flows/` once the
     harness exists): **hostel + walk-in counter**, **refund + rejected receipt**,
     **admin settings** (sign in with code, save venue/hostel rooms, discount, GCash, staff, FAQ).
   - When part 1 is done: merge `lv-flows` into `laravel`, then build parts 2–4.

2. **Old-site bug fixes — DONE, waiting for Aron.** Branch `bugfixes` has 6 fixes (hostel room
   Save, editing a disabled room, discount admin-only, demo-mode room name, GCash note wiped,
   suspended admin managing staff). The same fixes are already in the `laravel` branch with
   tests. To bring them into the old site: in `C:\xamp5\htdocs\VENUSeP`, run
   `git merge bugfixes` (or push the branch and open a PR).

## 6. Bugs reported by Aron (2026-10-30) — NOT fixed yet

Aron said to record these, not change code. Each is in BOTH the old site and the Laravel copy
(the port copied behaviour exactly), so fix both, like the `bugfixes` branch did.

1. **Counter mode: the VENUSeP logo drops staff out of counter mode.**
   Counter mode is the face-to-face booking screen staff use at the counter (`?counter=1` on the
   booking pages, staff session). Clicking the logo in the top bar goes to the customer landing
   page **without** `?counter=1`, so the booking pages then ask for a customer login.
   Cause: the logo link in the customer nav is hard-coded — old `includes/customer-nav.php:131`
   (`href="venusep_venue_booking.php"`), Laravel `laravel/legacy/includes/customer-nav.php:131`
   (`route('customer.venues')`). The menu links already add `$navCounterQ` (`?counter=1`, line 47);
   the logo should too (e.g. use `$navHome`/the landing URL + `$navCounterQ`). Aron's intent:
   counter mode stays separate from customer sign-in and keeps staff in counter mode while
   navigating on site. Check every other link in that nav in counter mode as well.

2. **A disabled customer gets no message.**
   (a) Signing in with the CORRECT password on a disabled account shows the generic
   "Invalid email or password." (old `customer/customer-login.php:140-142`, same in Laravel
   `app/Http/Controllers/Auth/CustomerLoginController.php`). Aron wants a modal/notice saying the
   account is disabled. Keep the generic message for a wrong password (so the page doesn't reveal
   which emails exist) — show the disabled notice only after the password checked out.
   (b) A customer disabled WHILE signed in is logged out silently on their next click
   (`customer_session_heal()` in `auth.php`) — it should show the same notice on the login page
   (the staff side already does this with `?suspended=1`).

3. **Google sign-in "hasn't been added".**
   The code IS in both sites (Francis's PR #21; ported in the Laravel copy with tests). What's
   missing is the settings file with the Google client id/secret: `includes/google-config.php`
   (old site) / `laravel/legacy/includes/google-config.php` (Laravel). It is git-ignored on
   purpose (it holds the secret), so it exists only on the machine where it was made — it is NOT
   on Aron's laptop for either site. Without it the button stays hidden / says "not set up".
   To enable: get the id/secret (Google Cloud → project "VENUSeP" → Google Auth Platform →
   Clients, or ask Francis), copy `includes/google-config.example.php` to the path above, fill
   it in, and make `redirect_uri` match an Authorized redirect URI in that client exactly
   (old site: `http://localhost/VENUSeP/customer/google-callback.php`; Laravel:
   `http://localhost:8000/customer/google-callback`, browse via `localhost`). Aron is trying this.

### Smaller bugs the browser flow tester found (2026-10-30) — NOT fixed, none blocks a flow

All on the staff side, in both sites. File paths are the Laravel copy.

4. **Fake date on new timeline entries.** After "Approve", "Confirm payment" etc. on
   `admin/booking-request`, the entry the page adds says "Jul 14, 2026 — You (staff)" until a
   reload — a mockup leftover: `function now() { return 'Jul 14, 2026 — You (staff)'; }` in
   `resources/views/admin/booking-request.blade.php` (~line 1096). Same root as the still-open
   "client-only actions" item in §7.
5. **Staff are shown as "Administrator".** The header name/initials are placeholder text
   (`$hdName` in `resources/views/partials/header.blade.php`, from the old `includes/header.php`),
   and the sidebar lists admin-only pages (Payment Settings, Backup & Restore…) to staff. Not
   checked whether those pages actually let staff in.
6. **POS and OR leave no trace in the history.** Recording the POS or the OR on a pay-to-use
   (hostel) booking writes nothing to `booking_timeline`, so after a reload neither appears.
7. **"Submitted" time shows 12:00 AM.** `app/Http/Controllers/Admin/BookingRequestController.php`
   ~line 132 builds the time from a date-only field.
8. **Hostel cash label.** A hostel stay paid at the front desk is labelled "Paid at cashier".
9. **Check-in allowed days early.** "Check the guest in" is offered (and accepted by the server)
   as soon as a stay is paid, even days before the check-in date. Possibly intended — ask Aron.
10. **Rejecting a receipt asks no reason.** "Reject receipt" always sends the fixed text
    "reference not found in the Transaction History", which the customer then sees. Maybe fine;
    ask Aron if staff should type a reason.

## 7. Open items and decisions

- **Not pushed.** Push `laravel` (and `bugfixes`) only when Aron says so — teammates still
  build features in the plain-PHP site, and any feature they add there must be ported too.
- **Still-open old bug:** on `admin/booking-request`, *Release slot*, *Extend deadline*,
  *Override (confirm payment)* and *Undo rejection* only change the screen, never the
  database, and stamp a fake date. Needs real saving logic per action — ask Aron first.
- **Known item:** in the FAQ answer *editor*, a "Booking history" link keeps the old
  `../customer/booking-history.php` form on purpose (the editor's save logic depends on it).
  Live FAQ pages link correctly. This is the "2 failing" in `venusep:check`.
- **Google sign-in** is switched off in the Laravel copy (no settings file). To switch it on:
  put a `google-config.php` in `laravel/legacy/includes/` (template:
  `includes/google-config.example.php`; it is git-ignored) with
  `redirect_uri` = `http://localhost:8000/customer/google-callback`, add that exact address to
  the Google Cloud client's redirect URIs, and browse via `localhost`, not `127.0.0.1`.
  Aron was about to try this himself.
- **Next phase (agreed goal, not started): make the code shorter** — turn the legacy
  `includes/` into proper Laravel classes, use the query builder, move the large inline
  CSS/JS out of the views. Plan it with Aron before starting.
- **Before go-live:** mail `base_url` must point at the Laravel site (reset links use it);
  uploaded profile photos land in `laravel/public/assets/img/avatars` (not git-ignored yet).

## 8. Traps (each one cost time)

- **MySQL must be shut down cleanly.** Turning the laptop off with MySQL running corrupted the
  system tables once (2026-10-04). Use XAMPP's Stop button or
  `C:\xamp5\mysql\bin\mysqladmin.exe -u root shutdown`.
- **New database copies need the right collation.** `CREATE DATABASE` defaults to
  `utf8mb4_general_ci`, but the real one is `utf8mb4_unicode_ci`; the stored triggers then fail
  with "Illegal mix of collations". Create copies with
  `... CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` *before* importing the snapshot.
- **Composer:** use `C:\xamp5\php\php.exe ...\_tools\composer.phar install --no-scripts`, then
  `php artisan package:discover`. Composer's post-scripts hang on this machine.
- **Old pages connect to the real database.** Never run the plain-PHP pages to "compare"
  against the Laravel ones.
- **Claude permission rules** (`C:\xamp5\htdocs\VENUSeP\.claude\settings.local.json`, not in
  git) still block: `git push`, the real `venusep` database, edits inside the `VENUSeP` folder,
  and the Gmail/Drive/Slack connectors. That is why the old-site fixes went to a separate
  `bugfixes` worktree. Loosen them when the overnight work is over.
- **Windows view-compile race:** running several PHP processes at once can make one fail
  with `rename(...): Access is denied` while compiling a Blade view. The auto-tester retries
  once; it is not a bug in the page.

## 9. Check the live state (run these first)

```
cd C:\xamp5\htdocs\VENUSeP-laravel
git log --oneline -5 laravel           # what's merged
git log --oneline laravel..lv-flows    # flow-tester work not merged yet
git -C ..\VENUSeP-laravel-helpers\flows status --short   # uncommitted flow-tester work
git worktree list
```

If you are a Claude session continuing this: the flow-tester helper may be resumable from the
original session; otherwise start a new one in the `lv-flows` worktree with §5 as its brief,
and keep committing after every step so a usage limit never loses work.

## 10. Night 2 (2026-10-30) — IN PROGRESS: bug fixing + bug hunting (Laravel only)

Aron's decisions and the agents' rules: `C:\xamp5\htdocs\VENUSeP-laravel-helpers\_tools\NIGHT-BRIEF.md`.
Google sign-in now WORKS in the Laravel copy (git-ignored `laravel/legacy/includes/google-config.php`
filled in by Aron; redirect URI `http://localhost:8000/customer/google-callback` added in Google Cloud;
open the site as `localhost:8000`, not 127.0.0.1).

7 agents, each in its own helper worktree/branch (started from `laravel` at f498aba):
abook (booking-request bugs 4,6,7,8,9,10 + the §7 fake actions), auth (bugs 1,2,5),
and bug hunters cbook, cacct, venues, admina, adminb. Flow DBs `venusep_laravel_flows_<area>`,
ports 8121-8127.

**If the usage limit stopped them — how to resume:**
1. For each worktree in `C:\xamp5\htdocs\VENUSeP-laravel-helpers\<area>`: `git log --oneline f498aba..HEAD`
   (finished fixes) and `git status` (half-done work, not committed — finish it or discard it).
2. In each, run `C:/xamp5/php/php.exe artisan test` from `laravel/`; merge only green branches into
   `laravel` (`git merge lv-<area>` in `C:\xamp5\htdocs\VENUSeP-laravel`), one at a time, re-running
   the tests after each merge.
3. Restart unfinished areas with the brief (same prompt shape: area, worktree, DB, port, task list).
4. At the end: `run-tests.bat` + full `artisan test` green, then write the morning report here
   (fixed / choices made where docs were silent / needs Aron / cross-area bugs).
