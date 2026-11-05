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
| The old plain-PHP site | Deleted by cleanup-1 (2026-11-01); still in git at tag `before-cleanup` |
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

## 6. Bugs reported by Aron (2026-10-30) — FIXED on night 2 (see §10)

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
   Clients, or ask Francis), copy `laravel/legacy/includes/google-config.example.php` to the path above, fill
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
  `google-config.example.php` next to it; the copy is git-ignored) with
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

## 10. Night 2 (2026-10-30) — DONE: bug fixing + bug hunting (Laravel only)

Everything below is merged into `laravel` (not pushed). PHPUnit: **463 passed** (was 296).
Browser flows: NOT re-run after round 2 (Aron tests them himself); the 4 flows the new rules
broke were updated (commit 6d66941) but only syntax-checked.

- **All §6 bugs fixed** (1-10, except Google, which Aron set up himself — it works on
  `http://localhost:8000`, settings in the git-ignored `laravel/legacy/includes/google-config.php`).
- **§7 fake actions** (Release slot, Extend deadline, Override, Undo rejection) really save now.
- **~45 more bugs found and fixed** by 7 hunting agents + a second round of 8 agents. Per-agent
  reports (what, file:line, test name, choices made): `C:\xamp5\htdocs\VENUSeP-laravel-helpers\_tools\night2-reports\*.md`
  and `...\_tools\round2-reports\*.md`. Highlights: staff could edit other venues' rooms; a
  customer name could run script on admin pages and the counter search; backup restore could
  wipe another database; venue double booking under concurrency (**migration 14**); refund
  withdraw/resubmit never appeared; quarterly report miscounted Events Held and broke on Mar 31.
- **Aron's decisions built tonight:** upload limit 7 MB everywhere; Add Venue admin-only and no
  second hostel venue; hostel bookable at most `hostel_advance_booking_max_days` (7) ahead;
  admin login has 3 Department cards and staff must pick their own venue; wrong-password limit
  on both sign-ins and on the counter identity check; current password needed to change email;
  suspended staff told they're suspended; revenue counts until the refund is paid out;
  post-pay: due the day after the event, Overdue after the grace days, no Release on post-pay
  (debt stays), Extend allowed after the event, and **a customer with an Overdue booking can't
  book online until they pay** (counter staff get a warning but may still book).
- **Migration 14** (`venusep_migration_14.sql`, repo root of this branch) is applied to every
  Laravel DB copy, the test snapshot, AND Aron's real `venusep` DB (he ran it 2026-10-30;
  backup `C:\xamp5\venusep_backup_before_m14.sql`). The next migration is **15**.
- Demo customers with an Overdue booking (customer@gmail.com, rafael.lim, jmdelacruz, msantos,
  areyes) now see the "unpaid booking" notice and can't book online — that's the new rule. To
  test booking use aronerfe0830 / mesionaalexa / nikkaarena.
- The site also runs through XAMPP Apache (no `artisan serve`):
  `http://localhost/VENUSeP-laravel/laravel/public/customer/venues` — but Google sign-in only
  works on `http://localhost:8000` (its redirect URI).

## 11. TO DO — waiting for Aron's go-ahead (nothing here is started)

1. **Privacy Policy page** (Aron asked 2026-10-30, "don't do it yet").
   - Why: Google only lets *anyone* sign in once the OAuth app is published ("In
     production"); showing the VENUSeP name/logo on Google's screen needs a home page + privacy
     policy link on a real domain (not localhost). Also the Data Privacy Act (RA 10173): the site
     collects ID photos, names, emails, phone numbers and payment receipts, so a privacy notice is
     expected anyway.
   - Build: a public page (e.g. `/privacy`) in the customer layout — what is collected (account,
     ID image, GCash receipt, booking/payment records, Google profile if used), why, who sees it
     (USeP venue staff/admins), how long it's kept, how to ask for access/correction/deletion,
     contact (USeP Data Protection Officer — get the real contact; follow/link USeP's official
     privacy notice if one exists). Link it from the footer, the sign-up page and the Google
     button area. Content needs Aron's / USeP's approval before go-live.
   - Meanwhile (localhost): keep the Google app in **Testing** and add teammates/teacher/panel as
     test users (Google Auth Platform → Audience → Test users).
2. **DONE (round 4, merged 2026-10-30):** customers are told "pay by the day after your event"
   everywhere; staff keep the "Overdue after" date. Dashboard "Payment Overdue" card says "Needs
   follow-up". Report: `helpers/_tools/round4-reports/cbook.md`.
   **LEFT FOR THE FINAL PREP DAYS (Aron will do it himself in Admin → FAQ Management, no migration):**
   two FAQ answers stored in the DB still give the old "due within {grace_days} days" rule:
   - Booking → "When do I have to pay?" — suggested: "**After your event, not before.** Staff
     approve your ID and reservation first; that holds your slot. Payment opens the day after your
     last booked day — **please pay by then**, by GCash or cash at the venue office. A booking still
     unpaid {grace_days} days later is marked **overdue**: it can still be paid, but you can't book
     again until it is settled."
   - Cash → "Can I pay in cash instead of GCash?" — end with "…you do this after the event, by the
     day after your last booked day."
   - Keep `{grace_days}` as the live value (FAQ editor → "Insert a current value"), don't type "3".
3. **DONE (round 4):** FAQ answer links in counter mode — customer-only targets become plain text,
   booking-page links keep `?counter=1`. Phone check: only fix was the overdue notice's tap area.
4. **DONE (round 3, merged 2026-10-30):** staff no longer see Edit Details / Reopen on other venues;
   Add Venue page sends staff back; a deadlock between two submits is a friendly 409; **migration 15**
   (`venusep_migration_15.sql`: sp_assign_bed leaves the transaction to the caller, maintenance
   re-check is a locking read, deadline expiry runs before the booking transaction). Applied to the
   Laravel DB copies + snapshot. Aron ran migration 15 on the real `venusep` DB too (2026-10-30, cmd,
   backup `C:/xamp5/venusep_backup_before_m15.sql`). Next migration: 16. Report: `helpers/_tools/round3-reports/abook.md`.
5. **DONE 2026-10-30 23:41, all green after round 4:** PHPUnit 478 passed; page checker 0 failing
   (the FAQ-editor "broken link" was a checker false alarm); flows 121/121. Fixed on the way: the
   loop script closed itself after run 1; admin-settings step 9 and the refund-withdraw step raced
   the page's own redirect/reload; VenueSlotRaceTest hit leftover far-future bookings (other tests
   leave ~175 in `venusep_laravel_test`, so tests needing a free date must check, not guess).
6. **Old site:** none of tonight's fixes are in the plain-PHP site (branch `Aron`) — Laravel only,
   by Aron's decision. The first night's old-site fixes sit on branch `bugfixes`, unmerged.
7. **Push / merge:** Aron decided 2026-10-31: fast-forward `laravel` into his own `Aron` branch and push
   that; he merges to `main` himself once everything is done.
8. **DONE 2026-10-31:** helper worktrees, `lv-*` branches and spare DB copies are gone. KEEP
   `VENUSeP-laravel-helpers\_tools`: the flow tester's snapshot and all reports live there, and it is
   NOT in git. Still to do: loosen `C:\xamp5\htdocs\VENUSeP\.claude\settings.local.json` when the
   overnight rules aren't wanted any more.
9. **Later (decided, not now):** the shrinking phase (legacy includes -> Laravel classes).
   Code-size notes: `legacy/includes/bookings.php` ~1,100 lines and
   `admin/booking-request.blade.php` ~1,380 lines — split when touched next.

## 12. 2026-10-31 — staff separation, clean-up, passwords

**Staff see only their own venue, everywhere** (Aron). One rule in `legacy/includes/auth.php`:
`staff_list_venue()`, `staff_sees_venue()`, `staff_venue_sql()`, `staff_may_handle_booking()`. Applied
where lists are built (`bookings_all()`, reports, `venues.php`, `venue-rooms.php`, `hostel-rooms.php`,
`payment-settings.php`) plus Venue Management, Dashboard and Payment Settings. Another venue's booking
pages, documents and receipts answer 404. Admins and customers are not narrowed. Hostel rates are worked
out before the narrowing so the FAQ's values stay whole. Known rough edge: a staff member opening another
venue's room form by URL gets the blank Add form (saving is refused by the server).

**Customer Management is admin-only** (page, save, sidebar). The counter's customer search stays for staff.

**Passwords.** Staff with `users.password_changed_at` NULL are held on Profile until they set their own
(`EnforceTwoFactor`; JSON gets 403). Every existing staff account is NULL, so each is asked once, on the
real site too. Admin Profile gained a Change password card; keeping the same password is refused. Staff
Management has Reset password (staff only, never another admin): temporary password, NULL again.
**Gap:** reset links are customer-only, so a locked-out ADMIN still has no way back in through the site.

**Staff sign-in:** "I am signing in as an administrator" checkbox switches the Department cards off; admins may
still pick a card. Staff who tick it are refused after the password check.
**Undecided (Aron: later):** "Forgot Password?" on the staff sign-in page is a dead link (`href="#"` in
`auth/admin-login.blade.php`). Staff get a new password from an admin (Reset password); admins have no way back.

**Lockout:** `reauth_verify()` in `auth.php` is the one copy of the 5-wrong-then-lock ladder.

**Clean-up (about 2,450 lines removed, no visible change):** one room form (`$isHostel`), `partials/booking-*`,
`partials/calendar-css`, `partials/people-css-shell`, register page uses `customer-auth.css`, 3 dead legacy
files deleted. Skipped: the admin sign-in pages' CSS copy (~140 lines; the shared file adds rules they lack).

**Traps found:**
- Legacy lists load once per PHP process, so one test must not sign in as staff and then as admin.
- Other tests leave far-future bookings in `venusep_laravel_test`: pick a date that is really free.
- A killed flow run can leave a backup in `storage/app/venusep-private/backups`.
- Do not edit code while flows run: they read the working copy.
- MySQL or a server started by a Claude session stops when that session ends. Start MySQL from XAMPP.

**Tests:** full run at deb65b1: page checker 0 failing, flows 121 of 122 steps passed. The failure was a
real bug (Suspend clipped in Staff Management), fixed; admin-settings then passed 12 of 12.

**Speed:** PHP's OPcache was off in `C:\xamp5\php\php.ini`, so Laravel recompiled on every click. Measured
signed in as staff (built-in server, flow DB): about 500 ms per page off, 50-85 ms on. The go-live server needs
it on too, along with `APP_DEBUG=false`, `config:cache` and `route:cache`. Do NOT run those caches on this
laptop: with a cached config, tests can hit the dev database instead of `venusep_laravel_test`.

**No migration needed for any of this.** The real `venusep` DB stays at migration 15.

## 13. 2026-11-01 — offices fully separate, walk-in refunds

**Decided by Aron:**
- Each office (one `venues` row: Bahay Alumni, USeP Venues, USeP Hostel) is separate. Only admins see everything.
- An Overdue booking blocks new online bookings at that office only, and only once officially Overdue (grace days passed). The counter warns but may still book.
- Walk-in refunds follow the refund switch: with refunds off, there are none.
- Staff file a walk-in refund with the receipt number (printed or emailed). Money goes back by GCash or cash, the staff's call, with optional proof.
- A missing receipt needs an admin's approval, online and walk-in alike. Staff look in Gmail or attach what they found.
- POS/OR never on a closed booking; OR only after payment.
- Disable room asks for confirmation.
- IDs are checked by staff by eye: no scanner, no automatic check.
- No migration needed: `refunds.refund_status = 'under_review'` holds a lost walk-in receipt, `official_receipt_pending` an online one.

**Refund policy (Aron):** the school usually does not refund. The teacher asked for a refund system that can be switched on if that policy changes. So the switch alone decides: with refunds off, nothing is refundable, a room closed by the office included. Staff handle such a closure outside the system.

**Accounts stay shared:** one person has one login, and the counter's search lists every customer. Only bookings and everything built from them are separated.

**Later, not now (Aron):**
- **Reports vs what each office submits:** make the Reports page and its printout match each office's real format as closely as possible. Get the actual forms from the offices first.

**Not a concern (Aron):** entering old paper bookings, accessibility work, hosting and account ownership. Training and support: the group's IT.

**Known gap:** `refunds.correction_due_at` is written but nothing enforces it, so a request returned for correction stays open after its 48 hours.

### Aron's answers so far (2026-11-01): compare with the teammate's answers to the 39 questions

A teammate answered the 39 logic questions separately. **Check each point below against those answers and raise any conflict with Aron before changing anything.**

| Topic | Aron's answer | Related question |
|---|---|---|
| Refunds off | Nothing is refundable, including an office's own closure. The switch is the only control. | 6 |
| Refund cut-off | Later: only matters if refunds are turned on. | 7 |
| Walk-in refunds | Follow the refund switch. | |
| Overdue block | Starts only once officially Overdue, and only at that booking's office. | |
| Missing receipt | Needs an admin's approval, online and walk-in. | |
| Discount a customer gets | The rate shown when they booked. | |
| Discount rate | Separate per office. | 29 |
| Grace days | Separate per office. | |
| Opening hours | Per office, changed by admins only. | |
| Regular closed days | Per office, changed by admins only. | 24 |
| One-off closures | Per office, admins only. Staff can still close single rooms. | |
| Booking across a closed day | An event skips that day; a hostel stay is refused. | 26 |
| Completed | Automatic once paid and finished, including a paid hostel no-show. | |
| Late refund correction | Stays open with a label; staff close it by denying; a denial is final, no second chance. | |
| Never-paid bookings | Staff request a write-off with a reason; an admin approves. | |
| Not a concern | Old paper bookings, accessibility, hosting and accounts. | |
| Later | Reports matching each office's real format. | |

Correction to question 23: a 60-minute cleaning gap already exists; it is one setting for all offices. Planned: per office.

**Build status:** planned as migration 16, starts once the current 3 test runs finish.

### Instructions to give the offices (outside the system)

**Hostel closed after a guest has paid.** USeP does not refund, so the system returns no money.
1. Staff contact the guest.
2. Staff offer other dates. (No move-date button exists yet: question 16.)
3. If the school decides to return the money anyway, it goes through the Cashier, outside the system.
4. Staff record what happened in the office's own records.

### The teammate's answers to the 39 questions (received 2026-11-01)

1 limit of 5 unpaid per office · 2 counter stays with staff, logged · 3 extensions limit 5 · 4 refunds off + cancel = no refund, offer rebooking · 5 no-show: no refund · 6 **yes, an office's own closure is refundable (conflicts with Aron)** · 7 no cut-off, always full · 8 report by event quarter · 9 walk-ins not linked to accounts · 10 disable allowed with a warning · 11 moved staff lose access · 12 same person may double-book across offices · 13 maximum length 6 months · 14 staff approve own counter bookings · 15 cancel: free before approval, then through staff · 16 staff move a date once · 17 overtime and damage fees are outside the system · 18 email on approval, rejection, payment due, overdue · 19 automatic nightly backups · 20 alert admin after 3 days · 21 venues 1 year ahead · 22 12-hour rule for venues; hostel usable the moment it is paid · 24 **no weekends; no payment deadline on a cashier-less day (see conflict)** · 25 rejected requests can be fixed · 26 closed day of a multi-day event: offer rebooking · 27 departments pay; no ₱0; no bumping · 28 receipt name: to discuss · 29 discount: anyone related to the university · 30 exact amount only, anything else sent back · 31 staff confirm in the GCash app · 32 price changes need an admin · 33 ~~restrict mixed genders~~ **overruled by Aron 2026-11-01: mixed genders are allowed in the hostel; nothing to build** · 34 add check-out · 35 no guest swaps; no unaccompanied minors · 36 delete ID files after a set time · 37 two admins and a history page · 38 remove demo mode entirely · 39 paper form at the counter, entered later.

Conflicts are being settled with Aron; nothing above is built yet.

### Conflicts settled by Aron (2026-11-01): these override the answers above

- **6 / refunds off:** no refund through the system; the routine above stands. But a refund the school makes outside the system must be recorded on the booking, with the exact reason, so the system reflects it. Design under discussion.
- **24:** weekends closed by default for the event offices; the hostel stays open on weekends; admins can change it.
- **22:** the hostel keeps the rule that check-in cannot come before the stay's first night. The venue lead time (12-hour rule) becomes a per-office setting.
- **30:** block the staff override when the amount is wrong.
- **4:** an unpaid customer who cancels before the event owes nothing.
- **3:** staff can extend a deadline 5 times, then only an admin can.
- **29:** the discount includes alumni and applies to the hostel.
- **32:** price editing is admin-only, with no approval step.

- **22 / lead time:** per-office setting, starting at 12 hours for Bahay Alumni and USeP Venues, online and at the counter. The hostel has none: guests can check in immediately.
- **36:** an uploaded ID is kept only until the booking is Completed (event done and paid), then deleted.
- **Outside refunds:** staff record a refund the school made outside the system: reason, Cashier reference and date, required proof. Full amounts only, every office, works with the switch off. No admin approval; admins are notified. The booking becomes Cancelled and Refunded, its date is freed, Reports stop counting it, and the record cannot be edited.

- **28:** an optional "Booking for (organization)" field, shown on the System Receipt.

- **36, closed bookings:** an ID is also deleted when a booking closes without being Completed (cancelled, expired, refunded). A rejected request keeps its ID until the chance to fix it has passed.

**Privacy before real IDs are collected (Aron is concerned; Data Privacy Act):** get the USeP DPO to sign off; add the privacy notice and a consent tick at upload. Until then, test only with fake IDs. Proposed, not yet approved: encrypt stored IDs, log who opens them, and leave IDs out of backups.

### IDs and force cancel (Aron, 2026-11-01)

- **IDs:** deleted per booking once it is finished. Encrypted when stored. Log who opens one. Left out of backups. The booking page says the ID was removed and when, and keeps who verified it. There is no DPO: this is a school project.
- **Force cancel (staff, own office, no admin needed):** only when not paid, no receipt waiting for review, and the event has not started. Requires a reason and a note. Customer and admins are notified. Also covers answer 15 (cancel after approval goes through staff). Repeat offenders: an admin disables the account.
- **Suspected fake booking:** the ID is deleted like any other; the history keeps who cancelled and why.
- **Reverse payment (admin only):** requires the admin's password and a written reason. Payment marked rejected, System Receipt VOID, booking back to unpaid with a new 48-hour deadline; customer and other admins notified. Details under discussion.

### Built on the laravel branch (2026-11-01), migration 16

Office Settings and per-office rules; the rate shown at booking is kept; Completed by itself; force cancel; write-offs; Reverse payment; recording outside refunds; move date; customer cancel and "send a new ID"; hostel check-out; extension limit of 5; exact amount only; prices admin-only on existing rooms; status emails; nightly backup command; IDs encrypted, logged, removed and out of backups; organization on the receipt; Activity page; admin alert after 3 days; demo mode removed.

Decisions made while building, for Aron to review:
- Staff can still set the price when creating a new room; only changing an existing price is admin-only.
- A prepaid booking reversed after its event is released when its 48 hours pass, since only post-pay bookings become debts.
- Move date applies the same hours to every day of a multi-day event.
- Rejected requests keep their ID until the booking's first day passes.
- Office closures do not count against a booking's one date move.

Known gaps: walk-in refund proof is stored but not emailed to the customer; a restore brings no IDs back; the date pickers do not grey out dates past the 1-year limit, though the server refuses them.

Layout fixes F1 to F11 from the responsive audit are done (approved by Aron, 2026-11-01). The hostel room form's 360° buttons stay on the photo on phones; they work but look crowded.

### First cleanup (branch `cleanup-1`, 2026-11-01)

**Safety tag:** `before-cleanup` (35a0b10) is everything as it was, the old site included.

What it did:
- New flow `settlement-panels`: Reverse payment, Write-off and Record outside refund, clicked in a real browser. All three passed as built.
- Deleted the old plain-PHP site from the repo root. Nothing under `laravel/` read it. `backup.php` reads the root `venusep_migration_*.sql`, so those stay.
- Moved the two config templates to `laravel/legacy/includes/`. The root `.gitignore` still ignores the old `includes/mail-config.php` and `google-config.php`, because a checkout may still hold them with real secrets.
- Added a short tracked root `CLAUDE.md`.

**Where errors go now:**
- `storage/logs/laravel.log` is the place for SQL errors: `SQL failed at file:line: message`, written by `sql_failed()` in `legacy/includes/db.php`, after the catch block's own rollback.
- With `APP_DEBUG=true` the real error is also shown: Laravel's error page, or JSON (`ok`, `error`, `message`, `file`, `line`) for the pages' `fetch()` calls.
- With `APP_DEBUG=false` the page behaves as before: the friendly message or the empty list, and nothing about the SQL.
- Never rethrown: a procedure's SIGNAL 45000, a deadlock (40001) and a duplicate key (1062) are answers, not failures. Work that must not cost the action around it (notifications, emails, remembered devices) passes `optional: true`, and is only logged.
- A transaction still open at the end of a request is logged by `DetectOpenTransaction`, and shown with debug on.
- PHPUnit runs with `APP_DEBUG=true` (`phpunit.xml`), so a hidden SQL error fails a test.
- Still in the PHP error log, not `laravel.log`: non-SQL failures such as SMTP errors, Google token exchange and ID decryption.

**Before go-live:** set `APP_DEBUG=false`. `.env.example` has it on, and with it on visitors now see SQL errors.

## 14. 2026-11-04 night — roles foundation (branch `roles`, migration 17)

Built from DB-DECISIONS section 28. **Migration 17 is NOT on the real `venusep` DB yet**: Aron runs
`C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_17.sql` in cmd, after a backup
(the `mysqldump.exe` line in the migration's header). **The database name must be the one the site uses
(`DB_DATABASE` in `laravel\.env`): Aron's site uses `venusep_laravel`, not `venusep`.** It ends by listing
every admin and its role, then "OK: exactly one super admin", then any office with no group. Applied to the test/flows snapshot
(`_tools\venusep_snapshot.sql`, appended like migration 16; the copy before it is `.before-m17`).

- **Schema:** `venues.office_group` (bahay_alumni | cedu); `users.is_super_admin` (one only:
  `uq_users_one_super_admin`; `chk_users_super_admin` keeps it an active admin with no group),
  `users.office_group` (a normal admin's group), `users.handed_over_at`; `staff.both_cedu_offices`.
  `sp_notify_venue_staff` sends booking news to the office's staff and the group's normal admins only.
- **Accounts:** admin@gmail.com super admin; francishubero@gmail.com Bahay Alumni admin; new
  cedu.admin@gmail.com "CEDU Admin" (temporary password `ceduadmin123`, sets up 2FA at first sign-in).
- **One place:** `legacy/includes/auth.php` (`staff_me`, `staff_venue_scope` now a list of office ids,
  `staff_venue_sql` takes `IN (%s)`, `staff_may_manage_account`). Route guard `staff:super`.
- **Super admin only:** Activity, Backup & Restore, refund switch, creating admins (with a group),
  adding or deleting an office. **Normal admin only, own offices:** GCash accounts. Office settings:
  own offices (super admin all). Staff Management: a normal admin sees and manages its own offices'
  staff only; the super admin any admin or staff; nobody resets the super admin; Make super admin =
  the handover (reason + password, emails both, old account suspended for good).
- **Not built yet (later phases):** customers page narrowed to the group, blocks, counter search
  rules, per-office refund switch, forgot password for staff/admins, account events
  (`sp_notify_admins`) still go to every admin.
- Tests: `tests/Feature/AdminPeople/RolesTest.php`; flow `roles-accounts`.

### 14b. Reports office filter + toggle (phase C, no migration)
- One page, one set of queries: `app/Queries/ReportQueries.php` (moved out of legacy
  `bookings.php`; the old `report_quarters()` / `report_by_venue()` are gone). Every query takes a
  list of office ids and filters with a plain `r.venue_id IN (...)`. The Dashboard revenue tile
  reads the same `quarters()`.
- `ReportsController::choices()` builds the toggle from `staff_venue_scope()`: each own office, each
  group with two or more of them ("USeP CEDU (both)"), and "All offices" for the super admin only.
  `?office=` outside the list falls back to the person's widest choice (clamped, no error).
  One choice = no toggle. Toggle: `resources/views/partials/report-office-toggle.blade.php`,
  one `@include` in `reports.blade.php` below the header (printout header untouched).
- Sections: shared tiles (revenue, growth, events held, average, bookings, customers, refunded,
  written off) and charts; Halls table (events, days, hours) for event offices; Hostel Rooms table
  (stays, bed-nights = nights x guests, occupancy, bathroom type line) for the hostel. Charts compare
  offices when several are chosen, else halls/rooms. Combined reports count a customer once and
  say so on the printout.
- Print: a single-office report may now run onto a second page (the tables move whole to page 2);
  Bahay Alumni on A4 still fits one page with the test data.
- Tests: `tests/Feature/AdminSettings/ReportsTest.php`; flow `admina-dashboard-reports-backup` step 3.

### 14c. Phase D (2026-11-05 night, no migration)
- **Counter mode by office:** the counter start page (`/customer/venues?counter=1`) draws only the
  viewer's offices: the room lists were already narrowed by `staff_sees_venue()`; the hostel card,
  section, hero button, footer and top-bar link now follow `office_hostel_shown()` (offices.php).
  Heading and counts adapt ("One/Two/Three places on campus", the hero line). No office (only a
  normal admin left without a group can be in that state: a trigger gives every staff member an
  office) -> "You have no office yet. Ask an admin to assign one." Another office's room or hostel
  page in counter mode is refused in `BookingAccess` (redirect back to the counter start page with
  "<office> is another office..."); booking-create still refuses at save. Public page unchanged.
- **Forgot password for staff, admins and the super admin:** `admin/forgot-password` +
  `admin/reset-password`, the same controller, views and `password-reset.php` rules as the customer
  pages; the side comes from the route name (`pr_*` take `$staff`). Each side's links work on its own
  page only. No link for a suspended account or a former super admin (`handed_over_at`). The link
  uses `config('app.url')` like other mail. After the reset: other sessions end (password_changed_at),
  remembered devices are forgotten, nobody is signed in, admins are still asked for their code.
  The staff sign-in's "Forgot Password?" link and its one-time "Your password was changed" notice.
- **Google safety net:** `GoogleController::start` (sign-in only) on a host/scheme other than the
  configured `redirect_uri`'s first redirects to the same path there, keeping `?remember=1`. Test seam:
  `config('venusep.google_config_test')` points `google_config()` at a fake file in testing only.
- **Account-event notifications:** `notify_account_event()` (notifications.php) replaces
  `notify_admins()`: a staff account's events (created, moved: old and new office, password reset,
  suspended, reactivated) go to that staff member's group's normal admins; an admin account's events
  and the handover go to the super admin (the new one, after a handover); the actor never.
  `sp_notify_admins` stays in the DB, unused. Restore and the refund switch add no notification:
  only the super admin can do them and nobody is told of their own action, so it would never fire.
- Tests: `CustomerBooking/CounterOfficeTest`, `Auth/StaffPasswordResetTest`, `Auth/GoogleHostTest`,
  `AdminPeople/AccountNotificationsTest` (+ the handover check in `RolesTest`). Flows:
  `auth-counter-and-disabled` (refused hostel page, CEDU admin's counter), new `staff-forgot-password`.

### 14d. Phase E (2026-11-05 night): office contact info, per-office blocks (migration 17 grew)
Migration 17 is still not on the real DB; the additions are in the same file, safe to re-run
(the test/flows snapshot is again `.before-m17` + the new migration 17).
- **Office contact info:** phone, email, location = the existing `venues.contact_phone`,
  `contact_email`, `address` columns, edited on Office Settings (same rules as the other settings,
  logged in its history; fields left out of a post keep their value). Hours come from opens/closes
  and closed days (`office_hours_label()`, offices.php; the hostel shows its open days only, it has
  no opening hours). Shown: public page footer (one block per office, replacing the hard-coded
  footer facts), the block notice, and the footer of every customer email about a booking
  (`re_office_line()`, receipt-emails.php). **Placeholders, confirm before go-live:** location
  "USeP Tagum–Mabini Campus, Apokon, Tagum City" (the old footer text), phone `0900 000 0000`,
  emails `bahay.alumni@` / `usep.venues@` / `usep.hostel@example.com`.
- **Blocks:** `customer_office_blocks` (one active block per customer and office; reason, who,
  when; lift: who, when, reason), `app/Queries/BlockQueries.php`. `fn_customer_blocked(customer,
  room)` is the one copy of the rule; `trg_bookings_block_ins` refuses a new booking (online and
  counter). The app refuses first: booking-submit (409 `blocked`, the notice + the office contact
  line, never the reason), booking-create at the counter ("Blocked at this office..."), and
  approving ("This customer is blocked at this office. Lift the block first, or reject the
  request."). Booking pages show the notice up front (in `customer/overdue-notice` partial); the
  counter's search and who-step show "Blocked at this office" and the step cannot go on; request
  list and detail show a Blocked badge. Approved bookings are untouched.
- **Auto-cancel:** the existing close of pending requests is `sp_expire_due_bookings()` step 2
  (pending past `fn_request_expiry`: a venue request at its first slot's start, a hostel request at
  the end of its check-in day -> released / expired, "Not approved before the booking date; the
  request expired."). It runs on page load (`venusep_expire_due_bookings()`, legacy db.php, before
  every booking read, once per request), no scheduler. A new step just before it cancels a request
  still blocked then (cancelled, History "Cancelled automatically: the customer was still blocked
  at this office when the booking was due to start."); the after-response `mail_status_sweep()`
  emails the customer once (kind `booking_block_cancelled`, contact line in the footer, no reason).
- **Customers page:** a normal admin sees customers who booked at its group's offices (booking
  counts there only) and blocks / lifts at its own offices with a reason; the super admin sees all.
  Suspending a whole account is the super admin's only now. Blocks and lifts appear on Activity.
- Tests: `CustomerBooking/OfficeBlockTest`, new cases in `AdminSettings/OfficeSettingsTest` and
  `CustomerBooking/CounterBookerTest`. Flow: new `office-block`.
- **Harness fix:** `FlowsDb::reset` (flows tester and `flows-tool reset`) now drops every table of
  the test/flows copy before loading the snapshot. The snapshot only drops the tables in its dump;
  tables a migration appends with `CREATE TABLE IF NOT EXISTS` (venue_closures, document_access_log,
  customer_office_blocks...) kept the previous run's rows, which broke a second run of `office-block`.
- Not done: walk-ins (no account) cannot be blocked (there is no record to block; a new customer
  row each visit). No notification bell entry for a block.

### 14e. Phase F (2026-11-05): refund switch per office (migration 17 grew)
Migration 17 is still not on the real DB; the addition is in the same file, safe to re-run (the
test/flows snapshot is again `.before-m17` + the new migration 17).
- **Schema:** `venues.refunds_enabled` (default off). The first run copies the old site-wide
  `system_settings.refunds_enabled` into every office, so nothing changes on day one; a re-run never
  overwrites a later change. The old row stays (its description says "No longer read").
  No booking backfill: bookings already store their rule since #16 / #26 (`refunds_allowed`,
  `pay_to_use`), so a backfill from today's value would have taken refunds away from bookings made
  while it was on.
- **Rule:** Bahay Alumni and USeP Venues: on = pay before + refunds, off = pay after + no refunds.
  USeP Hostel always pays before (`pay_to_use`, unchanged); its switch is refunds only.
- **One place:** `office_all()['refunds']` (offices.php). `$REFUNDS_ENABLED` (refund-policy.php) is now
  the switch of the page's office (`office_page()`, the booking pages); off on a page about no office.
  `office_payment_policy()` (same file) is the one copy of "hostel always before, a venue follows its
  switch" for the pages. `refunds_enabled()` and `refund_setting_details()` are gone. New bookings snapshot their office's
  switch: `BookingSubmitController` (online) and `BookingCreateController` (counter). Every read of an
  existing booking already used its snapshot (refund request, staff actions, emails, procedures).
- **Payment Settings:** one row per office (the ones the viewer sees), each with its pill, its rule
  ("Payment: always before the stay (fixed)." on the hostel row), last change, and for the super
  admin only a button. Same modal and re-type-password guard, per office. `RefundSwitchController`
  takes `venue_id`; the change is logged in `venue_settings_history` (field `refunds`), so Office
  Settings history and Activity show it.
- **Public page:** "How it works" step 4 has one line per office; the refund card says the one rule
  when the offices agree, else "Refunds depend on the office." and names them. **FAQ:** the
  "After you book" section opens with each office's rule; a refunds-ON/OFF question shows while an
  office it is about is in that state (`faq_row_for`, faqs.php: hostel section = the hostel, booking
  and cash = the event offices, the rest = all), tagged with the office names when the offices
  differ. Admin FAQ preview: "Live now" per office; starts on Everything while they differ.
- **Flows:** a flow's `settings: { refunds_enabled }` sets every office (FlowsDb). The
  refund-and-rejection flow turns Bahay Alumni on in the UI and checks the other offices stay off.
- Tests: `AdminSettings/RefundSwitchPerOfficeTest` (per-office timing, hostel fixed pay-before,
  super-admin only, snapshot kept after a flip, public page and FAQ per office), updated
  `PaymentSettingsTest` and `PostPayDayAfterTest`.
- Note: the Payment Settings text still says closures by USeP are "offered ... or their money back"
  (unchanged wording); memory venusep-refund-policy says with the switch off nothing is refundable,
  a closure included. Left as is: not part of this phase.

### 14f. Phase G (2026-11-05): the counter's customer handling (migration 17 grew)
Migration 17 is still not on the real DB; the additions are in the same file, safe to re-run
(checked by loading it twice on the test DB; the test/flows snapshot is again `.before-m17` + the
new migration 17).
- **Schema:** `customers.counter_venue_id` (the office whose counter made the account, FK venues,
  NULL = signed up themselves); `customer_email_fixes` (old, new, reason, who, when); email kind
  `counter_welcome`. "Never signed into" = `users.last_login_at IS NULL`, "Google" =
  `users.google_sub` set: both already stored, nothing added for them.
- **Search** (`CounterCustomerController::search`, SQL in `app/Queries/CustomerQueries.php`):
  every office's active customer accounts, by exact email (any case), exact mobile (any spacing,
  +63 too), or a name with 3+ letters; 10 at most; email and phone masked (`ms•••@usep.edu.ph`,
  `0917•••0188`); `usep` = a usep.edu.ph address; `noPassword`. Nothing about bookings. Not logged.
  Part of an email or a number now finds nothing (it used to match part of an email).
- **New account at the counter:** who-step mode "New account" (name, email, mobile) ->
  `admin/customer-create` with `check=1` (a taken email of ANY account: 409 "This person already
  has an account. Search for them instead."; a mobile another account uses: a warning) -> the
  confirm step shows the email large, "Read this email address back to the customer" -> made with
  no password and the office of the page's room; the welcome email (`queue_counter_welcome`,
  receipt-emails.php) says to choose a password through Forgot password. Domain typo suggestion:
  `emailSuggestion()` (edit distance <= 2 to gmail.com, yahoo.com, outlook.com, hotmail.com,
  usep.edu.ph), a click-to-fix link.
- **"ID checked":** an account with no password (Google, or just made at the counter) shows an
  "ID checked: I looked at a valid ID and it is this person" box instead of the password;
  booking-create accepts `id_identity=1` only for an account whose password is really NULL (a
  password account still needs the password proof) and writes History `counter_identity_id_checked`
  (who, when). `customer-verify` answers such an account 409 `noPassword`.
- **Fixing a wrong email** (`CustomerController` action `fix_email`, Customers page "Fix email"):
  a normal admin of the group whose counter made it (CEDU: either counter), never signed into, not
  Google, reason required; the super admin and staff cannot. It also clears any password chosen
  from the old address and spends its unused reset links, records the fix, and sends the welcome
  email to the new address. The Customers page now also lists the group's counter-made accounts
  that never booked.
- Tests: `AdminPeople/CounterAccountsTest` (new), updated `CounterCustomerTest`,
  `CustomerBooking/CounterBookerTest` (suggestion, confirm step, ID checked). Flows: new
  `counter-new-account`; `adminb-people` step 6 (part of an email finds nothing; masked email);
  `hostel-and-counter` and `abook-staff-actions` now search the counter customer by exact email.
- Not done: email fixes are not on the Activity page (they are in `customer_email_fixes`); a
  counter account has no "resend welcome email" button; walk-in email entry has no typo suggestion.

### 14g. Independent review (2026-11-05)
- **Migration 17 changed after Aron ran it on `venusep_laravel`; it is safe to run again** (checked on a copy that already had it, with rows in the new tables: nothing lost, nothing overwritten). Two collation fixes: `sp_notify_venue_staff` has no string variable, and `customer_office_blocks` / `customer_email_fixes` are always `utf8mb4_unicode_ci` (the file converts them if an earlier run made them with the server default; Activity failed otherwise). Only a database whose default is general_ci was affected.
- Staff moves: the tick for both CEDU offices now ends on every move out of CEDU (Staff Management edit, venue form). Office Settings: the history keeps the first 100 characters, so a long location no longer fails on a strict MySQL (XAMPP's is not strict).
- Tests: `RolesTest` (collation x2, the tick), `OfficeSettingsTest` (long location). `OfficeSettingsTest` counts history rows, so it needs a reset test DB: two of its tests fail on a second run without one.
- The snapshot in `_tools` is again `.before-m17` + the new migration 17.

## 15. 2026-11-05 — phase L fixes (branch `fixes-2`, migration 18)

Decisions: memory `venusep-teammate-bugs-2026-11-05` (ANSWERS). **Migration 18 is NOT on the real DB**: Aron runs
`C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_18.sql` in cmd, after the backup in its header.
It ends with "OK: every staff and admin account has an employee number". Applied to the test/flows snapshot
(`_tools\venusep_snapshot.sql`, appended; the copy before it is `.before-m18`).

- **Offices are fixed:** nobody adds or deletes an office, the super admin included (venue-save / catalog-delete
  refuse with 403, venue-form without an id goes back to the list; the buttons are gone).
- **Proxies:** `trustProxies` believes only `X-Forwarded-Proto` from 127.0.0.1/::1, never a forwarded host or port.
- **Admin-only requests** (lost-receipt refund, write-off, outside refund recorded): `notify_refund_needs_admin()`
  tells the group's active normal admins; with none active, the super admin.
- **Top-right chip:** the profile photo (`asset(photo_path)`), else the initials — admin/staff header, the customer
  header and the customer browse bar.
- **Profile:** Role = "Super admin" / "Admin · USeP CEDU" / "Staff · USeP Hostel" (both CEDU offices: both names);
  Position (if set) and the employee number, all read-only. **Employee numbers** (migration 18): `EMP-<year>-NNNN`
  (the format of Marites Robles's EMP-2021-0043), given by `trg_staff_employee_no_ins` from `employee_no_counters`
  (per year; its row lock keeps two accounts made at once apart), required, never changed
  (`trg_staff_employee_no_upd` refuses). Old rows backfilled (year of the staff row); the seeded super admin got a
  staff row. Staff Management's update no longer takes `employee_no`.
- **Booking Management:** "Awaiting payment" tab (await_gcash, await_cash, await_pos; never overdue) and the newest
  request first (the Refunds tab keeps soonest-event-first).
- **Overdue:** no Pay now, the pay page and payment-submit refuse ("Payment overdue. Please contact the office." +
  the office's contact line) until staff extend the deadline (that sets await_* again). `Status::PAY_OPEN`.
- **Counter phones:** walk-in and new-account mobiles take only 09XXXXXXXXX or +639XXXXXXXXX, spaces allowed
  (`ph_mobile_strict()`, bookings.php; `cleanPhone()` its JS twin; inputmode/pattern/message on screen). The other
  phone fields (profile, registration) keep the looser `cb_normalise_mobile()`.
- **Discount:** sp_approve_booking (migration 18) rounds a discounted total UP to the whole peso and stores
  discount = room price − total (every approval, counter included). Previews (`priceWithDiscount`,
  `usep_discounted`) use the same rule. A pending USeP claim shows the full price with "USeP discount applied once
  your ID is verified" (booking history, pay page, details modal). Amounts show centavos when they have any.
- Not done: the note is not in the narrow Transaction History tables; emails (payment_overdue still says
  "until it is paid") are the later email phase.
- Side effect: the seeded super admin now has a staff row (full name = its username `admin`), so History lines
  name it "admin" instead of its email (the header and profile already said "admin").
- Tests: PHPUnit 714 OK; `venusep:check --db=venusep_laravel_test` PASS (273 ok); `venusep:flows` PASS 161/161.
  New/changed: `SkeletonTest` (proxy), `AccountNotificationsTest` (super admin fallback), `HeaderPhotoTest`,
  `AdminProfileTest` (role, employee numbers), `BookingRequestsTest`, `PayTest`, `PaymentSubmitTest`,
  `BookingCreateTest` + `CounterAccountsTest` (phones), `BookingActionTest` (rounding), venue tests (fixed offices).
  Flows: `hostel-and-counter` (Booking Management step), `abook-staff-actions` (overdue: no Pay now until
  extended), `cacct-payment-guards` (extends first), `admina-dashboard-reports-backup` (overdue notification).

### 15b. Phase M (2026-11-05): deleted rooms, staff per hall, covers, moves (migration 18 grew)
Decisions: memory `venusep-teammate-bugs-2026-11-05` (items 12, 14, the ANSWERS); written up at the end of DB-DECISIONS 28.
Migration 18 is still not on the real DB; the additions are in the same file, safe to re-run (loaded twice on the flows copy).
The snapshot is again `.before-m18` + a blank line + the new migration 18.
- **Deleted rooms** (`RoomQueries`, `CatalogDeleteController`): `rooms.deleted_at`, `deleted_by_user_id`, always with `is_active = 0`,
  so every "active rooms" read already hides them; the two room loaders (`venue-rooms.php`, `hostel-rooms.php`) hide them from the
  admin lists too. `fn_room_live_bookings()` is the one copy of "cannot be deleted". Triggers refuse an undo, a delete with a live
  booking, and a new booking. Reports show "<name> (deleted)" for a room with records in the period. **The photo files are deleted**
  (not records), as before: a test or flow must never delete a seeded room, or it wipes tracked files under `public/assets`.
- **Staff per hall** (`StaffHallQueries`): `staff_halls`; the hostel is one unit, no rows. The DB gives a new or moved staff member
  every hall of the office, and drops the old office's (triggers), so the app only narrows. `lastStaffOf()` is the one copy of the
  last-staff rule (Staff Management halls, suspend, office change; venue form). A hall moved to another office goes to its staff.
- **My halls:** `fn_staff_on_room()` (halls + covers going on) feeds booking news (`sp_notify_venue_staff`) and
  `booking_my_hall_ids()` (auth.php, adds counter bookings made by the person). Booking Management only: the Dashboard, Calendar and
  Transaction History still show the whole office. "About to expire" reminders follow the hall too.
- **Covers and moves** (`StaffCoverController`, `CoverQueries`, `partials/staff-covers`): state is worked out from the times on
  every read, so nothing runs to end or expire them. `staff_me()` adds the office of a cover going on; the sign-in card accepts it too.
- **The tick:** nothing reads `staff.both_cedu_offices` now; the column stays as a record of who had it, and the migration lists them.
- Tests: `AdminPeople/StaffCoverTest`, `AdminPeople/StaffHallsTest` (new), `AdminVenues/CatalogDeleteTest` (rewritten), `RoomSaveTest`,
  `RolesTest`, `StaffManagementTest`. `AccountNotificationsTest` now removes its accounts (its own tearDown hid the trait's).
  Flows: new `staff-covers`; `venues-catalog` (a new hall names staff, the delete keeps the row), `venue-booking-to-receipt` (delete
  refused while pending), `roles-accounts` (tick step gone), `admin-settings` (new staff at Bahay Alumni: the only staff of a hall
  cannot be suspended).
- Tests: PHPUnit 753 OK (4404 assertions, run alone); `venusep:check --db=venusep_laravel_test` PASS (273 ok); `venusep:flows` PASS 168/168.
  Run PHPUnit and the flows one at a time: run together, a flow's database reset gave two random MariaDB 1615 errors in PHPUnit.
- Re-run of migration 18 checked on the flows copy: a hall an admin took away stays away; the tick list names who had it.
- Not done: My halls is on Booking Management only; a cover does not notify the staff member (admins tell them in person); overlapping
  covers are allowed; the last-staff check and the save are not one locked step, so two admins at the same second could empty a hall.
