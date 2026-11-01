# VENUSeP → Laravel port — the rules every porter follows

Started 2026-10-29 (overnight run). Branch `laravel`. The Laravel app lives in `laravel/`.
The old plain-PHP site (repo root: `admin/`, `customer/`, `includes/`, root `*.php`) stays
exactly where it is — it is the REFERENCE you port from, and the team's live code.
**Never edit anything outside `laravel/` except this file.**

## What "done" means for a page

1. Looks the same. Same HTML structure, same CSS, same text, same JS behaviour. This is a
   port, not a redesign. Do not "improve" the design, copy, or flow.
2. Behaves the same. Same validation, same error messages, same redirects (to the new URLs),
   same JSON shapes for endpoints the page's JS calls, same DB writes (same SQL / stored
   procedures — the database rules in `DB-DECISIONS.md` are law).
3. Shorter where Laravel genuinely removes code: the per-page shell (CSS block [0], header,
   sidebar) comes from the layout; `$_POST`/`$_GET` + manual checks become `$request`
   + validation where that is a straight swap; manual CSRF becomes `@csrf`; manual PDO
   prepare/execute/fetch MAY become `DB::select`/`DB::selectOne`/`DB::table` when it is a
   straight swap. Do not restructure logic to make it shorter.
4. A feature test proves it (see Testing). A page with no test is not done.

## Layout of the app

```
laravel/
  legacy/includes/       copies of ../includes/*.php — the shared business logic, run AS IS
  legacy/includes/db.php REPLACED by a shim: venusep_db() returns Laravel's PDO
  app/Support/helpers.php  legacy('bookings', 'pricing') = require_once those legacy files
  app/Http/Middleware/   staff / customer / booking-access guards (ported from includes/auth.php)
  app/Http/Controllers/<Area>/...   one folder per area (Auth, Customer, Admin, ...)
  resources/views/layouts/admin.blade.php     header + sidebar + admin theme (portal=admin)
  resources/views/layouts/customer.blade.php  header + sidebar (portal=customer)
  resources/views/<area>/...                  page views
  routes/web.php         only `require`s the area files below
  routes/areas/<area>.php  ONE file per area — you edit ONLY your area's file
  public/assets, public/logo  copies of ../assets and ../logo
```

### Legacy includes — call them, don't rewrite them

`legacy('bookings');` then call the functions exactly as the old page did
(`booking_queue_rows()`, `cb_is_refundable()`, ...). `venusep_db()` and `venusep_db_or_fail()`
work (they return Laravel's PDO, FETCH_ASSOC default, time_zone +08:00, same as before).

Some legacy files define top-level VARIABLES (`$venueRooms`, `$hostelRooms`, `$HOSTEL_VENUE`,
`$DISCOUNT_PERCENT`, `$REFUNDS_ENABLED`, `$customerContact`, `$customerBookings`, `$venues`,
`$gcAccounts`, `$AMENITY_GROUPS`...). `legacy()` returns all of them, so in a controller:
`extract(legacy('venue-rooms', 'pricing'));` — then `$venueRooms` exists exactly as it did after
the old `include`. Pass what the view needs into the view. **Never `include`/`require` a legacy
file yourself** — always `legacy()`, or the variables go missing (require_once runs once).
Legacy *templates* (customer-nav, notification-bell) are included by the Blade partials in
`resources/views/partials/` — use `@include('partials.customer-nav', ['navHere' => 'faq'])`.

Tests run each test METHOD in its own PHP process (`processIsolation` in phpunit.xml), because
legacy files compute per-request data at load time and keep `static` caches. Inside ONE test
method, a second request still sees the first request's legacy data — so for pages that read
`$customerContact`, `$venueRooms` etc., test one role per method.

A legacy include that reads/writes `$_SESSION`, calls `header()`/`exit`, `setcookie()` or
`session_*()` must NOT be called as-is from Laravel. Those few are owned and ported by the
**auth** area (see ownership table). If your page needs one of them, use the Laravel
replacement the auth area provides (`App\Support\Venusep` — see below), or if it is not
there yet, port the one function you need into your controller with a `// TODO(auth)` note.

Only the OWNER of a legacy include edits it (table at the bottom). Everyone else treats
`legacy/includes/` as read-only.

### Session contract (identical keys to the old site)

`session('user_id')`, `session('account_type')` ('admin' | 'staff' | 'customer'),
`session('customer_id')`, `session('customer_name')`, `session('customer_email')`,
`session('tfa_passed')`, `session('auth_at')`, `session('demo')` (demo-mode overlay).
Never touch `$_SESSION`, `session_start()`, `session_regenerate_id()` in Laravel code —
use `$request->session()->regenerate()` / `->invalidate()`.

`legacy/includes/auth.php` is a **Laravel rewrite with the same function names**, so the
old calls keep working: `staff_venue_scope()`, `staff_may_handle_venue($id)`,
`admin_is_admin()`, `counter_mode()`, `counter_staff_name()`, `customer_logged_in()`,
`venusep_logout()`, `csrf_valid($t)`. `csrf_token()` is Laravel's own helper.
`legacy/includes/demo-mode.php` keeps its overlay in `session('demo')`;
`notification-bell.php` already points at the Laravel URLs.

Legacy functions that cache in a `static` variable cache for the whole PHP process — one
request on Apache, but MANY requests in a test run. If a test needs fresh state across
requests, use `request()->attributes` caching in your port (see auth.php / demo-mode.php).
A test also keeps its session between requests: call `$this->flushSession()` to be a guest.

### Middleware (route middleware aliases, in bootstrap/app.php)

| alias | old code | use on |
|---|---|---|
| `staff` | `admin_require_login()` | every admin page/endpoint (admin + staff) |
| `staff:admin` | `admin_require_login(['admin'])` | admin-only pages |
| `customer` | `customer_require_login()` | customer account pages |
| `booking.access` | `booking_page_require_access()` | the booking pages (customer OR counter staff) |

JSON endpoints that the old code guarded with its own "401 JSON" response keep doing so:
check in the controller and return the same JSON — don't rely on the redirecting middleware.

### URLs

Clean URLs: folder + old file name without `.php`, a few renamed. **Always link with
`route('name')`**. Every route name below already exists (pointing at a "not ported yet"
placeholder) so cross-area links work before the other area is done. When you port a page,
replace its placeholder line in YOUR area file — keep the name and URL.

The full table is in `laravel/routes/areas/*.php`; names follow `admin.<old-basename>`,
`customer.<old-basename>`, `root.<old-basename>` with these renames:
`Admin_Dashboard` → `admin.dashboard` (/admin/dashboard),
`Quarterly_Reports` → `admin.reports` (/admin/reports),
`venusep_staffManagement` → `admin.staff` (/admin/staff),
`venusep_profile` → `admin.profile` (/admin/profile),
`venusep_venue_booking` → `customer.venues` (/customer/venues).
Old `?query=strings` stay query strings (`route('admin.booking-request', ['id' => 5])`).

POST endpoints keep their old name: `admin.booking-action` is POST /admin/booking-action.
Pages whose JS calls `fetch('booking-action.php')` get `fetch(@json(route('admin.booking-action')))`.

### Forms, CSRF, JS

- `@csrf` in every form. The old field was `csrf` (checked by `csrf_valid()`); Laravel's is
  `_token`. JS that posts with `fetch` sends header `X-CSRF-TOKEN` from
  `<meta name="csrf-token">` (both layouts print it). Remove the old `csrf_valid()` checks —
  Laravel's middleware does it — but keep the old *error message/JSON* for an expired page
  where the JS depends on it (map Laravel's 419 if needed).
- Keep inline `<script>` and `<style>` blocks that belong to the page; push them with
  `@push('styles')` / `@push('scripts')`. Shared shell CSS is in the layout already — delete
  the duplicated [0] SHELL CSS block, keep anything page-specific.
- Static files: `asset('assets/...')`, `asset('logo/...')`.
- Escape with `{{ }}`. Where the old code echoed pre-built trusted HTML (`demo_banner_html()`),
  use `{!! !!}` exactly where it did.

### Files, mail, Google — safe overnight settings (do not change)

- Private documents: `VENUSEP_DOC_ROOT` in `.env` points at `laravel/storage/app/venusep-private`
  — NOT the real `C:/xamp5/venusep-private`. Never write to the real one.
- Mail: `legacy/includes/mailer.php` queues to the `email_outbox` table; there is no
  `mail-config.php` in the Laravel copy, so nothing is sent. `MAIL_MAILER=log`. Keep it so.
- Google sign-in: there is no `google-config.php` in the Laravel copy; the button must show
  the same "not configured" behaviour the old code has when the config is missing. Do NOT
  copy real Google credentials. The old site's Google sign-in is untouched.

## Database

- Dev DB: `venusep_laravel` (a copy of the real one, migration 13). Laravel migrations are NOT
  used for the existing schema — never run `php artisan migrate` against it, never drop/alter
  tables. Schema changes are out of scope tonight.
- Your area's test DB: `venusep_laravel_test_<area>` — set in your worktree's `.env.testing`
  (git-ignored). Reset it any time:
  `/c/xamp5/mysql/bin/mysql.exe -u root venusep_laravel_test_<area> < /c/xamp5/htdocs/VENUSeP-laravel-helpers/_tools/venusep_snapshot.sql`
- The REAL `venusep` database is off-limits (blocked by permissions). Never run the old
  PHP pages either: they connect to the real database.

## Testing

- Feature tests in `laravel/tests/Feature/<Area>/`. Do NOT use `RefreshDatabase` (it would
  run migrations). Tests run against your area test DB, may write to it, and must not depend
  on rows other tests create. Use existing seeded accounts: `admin@gmail.com` (admin; 2FA
  off in the seed — check with `SELECT totp_secret FROM users`), `staff@gmail.com`,
  the demo customers (`SELECT email FROM users WHERE account_type='customer'`).
- Sign in a test as someone with `$this->withSession([...the session contract keys...])`
  (`tests/TestCase.php` has `actingAsAdmin()`, `actingAsStaff()`, `actingAsCustomer()`).
- Minimum per page: guest is redirected; the right role gets 200 and the page's key text;
  the wrong role is redirected. Per POST endpoint: the main success path writes what the old
  code wrote, and one validation failure returns what the old code returned.
- Run: `cd laravel && /c/xamp5/php/php.exe artisan test --filter=<Area>`
- Try it in a browser when it matters: `/c/xamp5/php/php.exe artisan serve --port=<your port>`
  (run in background) and the `playwright-cli` skill.

## Git

- Work only in your own worktree/branch. Commit after each page that passes its tests:
  `git add -A laravel && git commit -m "Laravel: <area> — <page>"` ending with the
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` trailer.
- Never push. Never touch `main`. Never rebase/reset others' commits.
- `vendor/`, `.env`, `.env.testing`, `storage/` contents are git-ignored. In a fresh worktree:
  `cd laravel && /c/xamp5/php/php.exe /c/xamp5/htdocs/VENUSeP-laravel-helpers/_tools/composer.phar install --no-scripts --no-interaction`
  then `/c/xamp5/php/php.exe artisan package:discover`, then copy `.env` and `.env.testing`
  from `C:/xamp5/htdocs/VENUSeP-laravel/laravel/` and set `DB_DATABASE` in `.env.testing`
  to your area's test DB. (`--no-scripts`: composer's post-scripts hang on this machine.)
- Composer: never add a package without a strong reason; say so in your report if you do.

## Ownership — who edits what

| area | branch | test DB | port | pages (old file → it is yours) | legacy includes you own |
|---|---|---|---|---|---|
| auth | lv-auth | _auth | 8101 | admin/admin-login, admin/logout, customer/customer-login, customer/customer-register, register-submit, customer/forgot-password, customer/reset-password, customer/google-start, customer/google-callback, customer/logout, admin/venusep_profile, customer/customer-profile, profile-save | auth, accounts, two-factor, two-factor-views, totp, remember-me, google-auth, password-reset, passwords, customer-auth-shell, demo-mode (session parts), notification-bell, customer-nav, header, sidebar |
| cbook | lv-cbook | _customer | 8102 | customer/venusep_venue_booking, customer/room-reservation, customer/hostel-reservation, customer/booking-submit, customer/calendar | venue-rooms, hostel-rooms, amenities, pricing, venues, counter-booker |
| cacct | lv-cacct | _cacct | 8103 | customer/booking-history, customer/pay, customer/payment-submit, customer/receipt, receipt-pdf, customer/refund-request, customer/refund-submit, customer/transaction-history, customer/faq, customer/index | customer-bookings, refund-policy, system-receipt, gcash-checker, documents |
| abook | lv-abook | _bookings | 8104 | admin/booking-requests, admin/booking-request, admin/booking-action, admin/booking-create, admin/booking-emails, admin/calendar, admin/receipt, admin/transaction-history, booking-details, document-view, valid-id-view | bookings, booking-details-modal, receipt-emails, mailer, notifications |
| venues | lv-venues | _venues | 8105 | admin/venue-management, admin/venue-form, admin/venue-save, admin/venue-photo-api, admin/room-form, admin/hostel-room-form, admin/room-save, admin/room-photos-api, admin/room-toggle-active, admin/catalog-delete | room-photos, venue-photos |
| admina | lv-admina | _admin | 8106 | admin/Admin_Dashboard, admin/Quarterly_Reports, notifications, admin/backup-restore, admin/backup-action, admin/demo-mode-switch, admin/refund-switch, admin/payment-settings, admin/discount-save, admin/gcash-account-save | backup, payment-settings, admin-theme |
| adminb | lv-adminb | _adminb | 8107 | admin/venusep_staffManagement, admin/staff-save, admin/admin-register, admin/customer-management, admin/customer-save, admin/customer-search, admin/customer-verify, admin/faq-management, admin/faq-save | faqs |
| tester | lv-tester | _tester | 8108 | the auto-tester (`php artisan venusep:check`) | — |

Layouts (`resources/views/layouts/*`), `routes/web.php`, `bootstrap/app.php`,
`app/Http/Middleware/*`, `app/Support/*`, `tests/TestCase.php` belong to the integrator
(the main session). If you truly need a change there, make the smallest change, and say so
in your final report so it can be reconciled at merge time.

## Auto-tester (`php artisan venusep:check`)

Requests every GET page (every route named `admin.*`, `customer.*`, `root.*`) as **guest,
customer** (customer@gmail.com), **staff** (staff@gmail.com) and **admin** (admin@gmail.com),
internally through Laravel's HTTP kernel — no server, no browser needed.

```
cd laravel
C:\xamp5\php\php.exe artisan venusep:check
```

Options:

| option | what it does |
|---|---|
| `--only=admin.booking` | only routes whose NAME starts with this |
| `--role=customer` | only one role (guest, customer, staff, admin) |
| `--db=venusep_laravel_test_abook` | run against another copy (default: the one in `.env`). It **refuses** the real `venusep`. |
| `--in-process` | everything in this one PHP process (default: one worker process per role, in parallel) |
| `--browser` | also load every page that answered 200 in Edge/Chrome via Playwright and record console errors, JS errors and failed requests. Needs a running `php artisan serve` (`--base-url=http://127.0.0.1:8000` is the default) and a Playwright package already installed (e.g. the global `@playwright/cli`); it downloads nothing. Other sites (CDNs, fonts) are blocked unless `--browser-external`. |

How to read it: a table (route, role, status, result, first problem) and a summary line,
then the paths of the full report: `laravel/storage/app/venusep-check/<timestamp>.html` (open it
in a browser; filter buttons per result) and `.json`. Results:

- **ok** — answered without problems (a redirect is ok: e.g. a guest sent to the sign-in page).
- **failing** — 5xx, an exception, PHP/Laravel error text in the page, a broken internal link or
  a missing asset (a `href`/`src` that matches no route and no file in `public/`), or a page that
  killed its worker process (`exit`, fatal error). Any failure → exit code 1.
- **warnings** — a page answering 4xx, a `<form method="post">` without `@csrf`, output echoed
  outside the response, browser-pass findings. Do not change the exit code.
- **not ported** — still the 501 placeholder. **needs params** — the page reads a `?query` value
  and nothing suitable was found in the database (values ARE filled in where they can be: the
  latest booking reference — the customer's own for the customer, one at staff's venue for staff —,
  the first room/venue, `?counter=1` for staff on the booking pages). **skipped** — the role could
  not sign in (e.g. "admin needs 2FA set up": on a database whose name does not start with
  `venusep_laravel_test` the checker does not give admin@gmail.com a 2FA key itself).

Each request gets a fresh session. Pages may still write while being viewed (e.g.
`sp_expire_due_bookings`) — another reason it only runs against the Laravel copies.
Exit codes: 0 no failures, 1 failures, 2 refused / bad option / database unreachable.

## Flow tester (`php artisan venusep:flows`)

`venusep:check` loads pages one by one; the flow tester walks whole **processes** in a real
browser (Edge, else Chrome) the way a person would: register, book, upload an ID, staff approve,
pay by GCash, staff confirm, open the receipt. Each step is screenshotted and checked against the
database.

```
cd laravel
C:\xamp5\php\php.exe artisan venusep:flows              # every flow
C:\xamp5\php\php.exe artisan venusep:flows --headed     # watch it: visible browser, slowed down
C:\xamp5\php\php.exe artisan venusep:flows --only=venue-booking-to-receipt
```

| option | what it does |
|---|---|
| `--only=a,b` | only these flows (flow name or file name) |
| `--headed` | visible browser, slow motion |
| `--keep` | leave the server running (its own console window) and the database as the last flow left it; sign in with password `FlowTest#2026` |
| `--reuse` | use a server already listening on `--port` (start it yourself with `DB_DATABASE=<the flows db>`) |
| `--db=venusep_laravel_flows` | the database. **Refused** unless the name starts with `venusep_laravel_flows` or `venusep_laravel_test` (never `venusep`, never `venusep_laravel`). It must exist with `utf8mb4_unicode_ci`. |
| `--port=8120` | where it starts `php artisan serve --no-reload` (with `DB_DATABASE` set for that process). 8000 is refused. |
| `--snapshot=` | the SQL loaded before each flow (default `FLOWS_SNAPSHOT`, else `C:/xamp5/htdocs/VENUSeP-laravel-helpers/_tools/venusep_snapshot.sql`) |

Before **every flow** the database is re-loaded from the snapshot and every account with a
password gets `FlowTest#2026` (`admin@gmail.com` and `staff@gmail.com` also have authenticator
keys; the harness types the current code). Flows never see each other's rows.

Output: a live progress list, then a table (flow → step → PASS/FAIL/SKIPPED, time, error) and
`laravel/storage/app/venusep-flows/<timestamp>/report.html` — one card per step with its
screenshot, JavaScript errors, console errors, failed network requests and notes —
plus `report.json`, the PNGs and the generated fixture images. Exit code 0 = all passed,
1 = a step failed (or no browser / no flow matched), 2 = refused or bad option.
Needs Node and a Playwright package already on the machine (the global `@playwright/cli` has
one); it installs and downloads nothing. The GCash OCR loads Tesseract from a CDN: offline, a
receipt still goes to manual review, which the flow follows.

### Adding a flow — one file, nothing else

Drop `laravel/tests/flows/flows/<name>.mjs`; it is discovered automatically (flows run in file-name
order, each on a freshly reset database). Shape:

```js
export default {
  name: 'hostel-walk-in',                 // used by --only (defaults to the file name)
  title: 'Hostel walk-in at the counter',
  settings: { refunds_enabled: '1' },     // optional: system_settings rows to set after the reset
  async run(ctx) {
    await ctx.step('Staff sign in', async () => {
      ctx.state.staff = await ctx.loginStaff('staff@gmail.com');
    });
    await ctx.step('…', async () => { /* drive the page, then check */ });
  },
};
```

`ctx` helpers (`tests/flows/lib/ctx.mjs`):

- `step(title, fn, {timeout})` — runs `fn`, screenshots the page it returns (or the page that was
  used last), records pass/fail. After a failure the remaining steps are recorded as skipped, so
  put all work INSIDE steps. Default timeout 90 s.
- `loginCustomer(email)`, `loginStaff(email)`, `loginAdmin(email)` — each opens a NEW browser
  context (a separate person), fills the real sign-in form, types the authenticator code when the
  account asks for one, and returns the signed-in `page`. `newPage(label)` = a fresh guest.
  A TOTP code is used once per 30 s per account; a second sign-in of the same account waits.
- `expect.ok / equal / match` (values), `await expect.visible(locator)`, `await expect.text(page, textOrRegExp)`,
  `await expect.url(page, regexp)` — failures read as sentences in the report. `fail(msg)` too.
- `await db(sql, params)` / `await dbOne(sql, params)` — READ-ONLY checks (one SELECT, run in a
  read-only transaction on the flows database; anything else is refused).
- `await fixtures.idImage(name)` → path of a PNG ID card; `await fixtures.gcashReceipt({ amount,
  name, number, ref })` → a "Sent via GCash" PNG the in-browser OCR reads (13-digit `ref`, unique
  per receipt). Both are drawn in the browser (this PHP has no GD).
- `url(path)`, `password`, `uniq` (run id, for unique emails/names), `state` (share pages and
  values between steps), `note(text)` (shown under the step), `sleep(ms)`.

Find selectors in the views (`resources/views/...`): prefer ids, roles and visible text. A real app
bug that blocks a step stays a failing step — report it, do not change app code for the flow.
Harness pieces: `app/Console/Commands/VenusepFlows.php` (the command),
`app/Console/Commands/VenusepFlowsTool.php` + `app/Console/VenusepFlows/FlowsDb.php` (reset / SELECT /
TOTP, flows databases only), `tests/flows/run.mjs` + `lib/` (runner, ctx, report).
Tests: `php artisan test --filter=FlowsHarness`.
