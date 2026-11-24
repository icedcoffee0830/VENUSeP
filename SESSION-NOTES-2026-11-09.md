# Session notes: 8–9 November 2026

Work done on Rafael's laptop, branch `raf-branch`. Nothing is committed yet.

## Summary

| # | Problem | Cause | Status |
|---|---|---|---|
| 1 | `php artisan serve` crashed: `vendor/autoload.php` not found | Laravel's packages (`vendor/`) were never installed | Fixed |
| 2 | Real email through Gmail | `mail-config.php` still pointed at Mailpit | Set up |
| 3 | Google sign-in | `google-config.php` was empty | Set up |
| 4 | Landing page nav links jumped with no animation | Windows "Animation effects" was off, and links from other pages always jumped | Fixed in code; setting turned on |
| 5 | Activity page: `Illegal mix of collations for operation 'UNION'` | This laptop's database was created with the wrong collation | Fixed (database rebuilt) |
| 6 | Backup & Restore not highlighted in the sidebar | The `&` in its name was stored as `&amp;` and never matched the menu | Fixed in code |
| 7 | The two `*.example.php` template files show as deleted in git | They were renamed instead of copied when the real config files were made | **Still to do** |

---

## 1. App would not start

**Error:** `Failed opening required '...\laravel/vendor/autoload.php'`.

**Cause:** the `laravel/vendor/` folder holds Laravel and its packages. It isn't stored in git, so every new computer has to install it. Composer, the tool that installs it, wasn't installed on this laptop.

**What was done** (step 3 of `SETUP.md`):
1. Downloaded Composer to a temporary folder and ran `composer install --no-scripts`. PHP's `zip` extension is off in `C:\xampp\php\php.ini`, so it was turned on for that one command only (`php -d extension=zip`). `php.ini` itself is unchanged.
2. `php artisan package:discover`
3. Copied `.env.example` to `laravel/.env` and ran `php artisan key:generate`.

**Keep in mind:**
- `APP_KEY` in `laravel/.env` encrypts the stored customer IDs. If it's lost, they can never be opened again. Never commit it.
- To run `composer` again later, install it from getcomposer.org and remove the `;` in front of `extension=zip` in `php.ini`.
- Database steps that touch the real `venusep` (the migrations, `php artisan venusep:encrypt-ids`) are run by hand, not by Claude, as the project rules require. `SETUP.md` lists migrations 01–17, but the repo now has up to `venusep_migration_20.sql`.

## 2. Email (Gmail)

File: `laravel/legacy/includes/mail-config.php`. Git ignores this file, so it is never committed.

- Settings: `smtp.gmail.com`, port 587, `tls`, the `venuseptagumunit@gmail.com` account and its App Password.
- The App Password is written **without spaces**, as the notes in the file ask.
- **`redirect_all_to` is commented out**, as requested. So the site sends real emails to whatever addresses are in the database, including the demo accounts' real-looking ones. To send every email to one test inbox instead, remove the `//` in front of that line.

## 3. Google sign-in

File: `laravel/legacy/includes/google-config.php`. Git ignores this file too.

- Filled in `client_id`, `client_secret` and `redirect_uri` = `http://localhost:8000/customer/google-callback`.
- The values were copied from a screenshot. If Google answers `invalid_client`, paste them again straight from Google Cloud Console.
- That redirect address must be listed exactly under the client's "Authorized redirect URIs", or Google answers `redirect_uri_mismatch`.

## 4. Landing page animations (`/customer/venues`)

**Problem:** clicking "Bahay Alumni" (or any nav link) jumped straight to the section.

**Causes:**
1. Windows "Animation effects" was **off** on this laptop. Chrome then tells every website to "reduce motion" and also turns off smooth scrolling everywhere. The page respects that, so it skipped its scroll glide, fade-ins and number count-up. It's now turned on (Settings → Accessibility → Visual effects). Chrome must be fully closed and reopened to notice the change.
2. A real gap in the code: links from other pages (FAQ, booking pages) to `/customer/venues#venue-bahay-alumni` always jumped, on every computer.

**Code changes:**
- `laravel/public/assets/js/customer/venues.js`: nav links now animate the scroll themselves (about 1 s, easing in and out) instead of relying on the browser's smooth scrolling. Scrolling, clicking or pressing a key mid-way stops it.
- `laravel/resources/views/customer/venues.blade.php`: when the landing page opens with a `#section`, it starts at the top, glides down, then puts the `#section` back in the address bar.
- `laravel/public/assets/css/customer-nav.css`: pages with the customer top bar fade into each other (browser view transitions) instead of cutting.
- People who have asked their computer for reduced motion still get the plain jump, on purpose.

**Checked:** in headless Chrome, the click glides from the top to the section over about 0.9 s with no JavaScript errors. Arriving from another page glides too. With reduced motion on, both jump as intended.

## 5. Activity page crash (`/admin/activity`)

**Error:** `SQLSTATE[HY000]: General error: 1271 Illegal mix of collations for operation 'UNION'`.

**Cause:** the project's schema (`venusep_schema.sql`) says every table uses the `utf8mb4_unicode_ci` collation. This laptop's `venusep` database had been created with the MySQL server's default, `utf8mb4_general_ci`, so 37 older tables plus all functions, procedures, triggers and views used `general_ci`. The 7 tables from recent migrations (`staff_covers`, `staff_moves`, `customer_office_blocks`, …) set `unicode_ci` themselves. The Activity page puts text from both groups into one list, and MariaDB refuses to mix them. The page's code was fine.

**Fix:** rebuild the database with the right collation. The data is copied exactly, so `APP_KEY` and the stored IDs keep working.
- First rehearsed on a structure-only copy in a test database: the Activity query worked after the conversion and gave the same error without it. The test database was then deleted.
- Rafael ran the fix script himself, because the project rules say no script may change the real `venusep`. The script:
  1. backs up `venusep` to `C:\venusep_collation_fix\before.sql`;
  2. replaces `utf8mb4_general_ci` with `utf8mb4_unicode_ci` in a copy (`after.sql`);
  3. drops and recreates `venusep` as `unicode_ci` and loads the copy;
  4. puts the original back automatically if loading fails.
- **Result:** all 44 tables are now `utf8mb4_unicode_ci`.

**Keep in mind:**
- `C:\venusep_collation_fix\before.sql` and `after.sql` contain real customer data. Delete them once the site is confirmed working, and don't share them.
- Teammates whose database was created from `venusep_schema.sql` shouldn't have this problem. A database created some other way (for example an empty database made in phpMyAdmin, then the schema imported) may have it.

## 6. Sidebar highlight on Backup & Restore

**Cause:** each page tells the sidebar its name with `@section('active', '...')`. Blade HTML-escapes that short form, so this page sent `Backup &amp; Restore`, which never matched the menu's `Backup & Restore`. It's the only menu name with an `&`.

**Fix:** `laravel/resources/views/layouts/shell.blade.php` now decodes the name (`html_entity_decode`) before passing it to the sidebar. This also covers any future names with `&` or quotes and changes nothing for the others. Laravel's cached views were cleared.

## 7. Still to do

1. **Restore the two template files**, which show as deleted in git. From `laravel/`:
   ```
   git restore legacy/includes/mail-config.example.php legacy/includes/google-config.example.php
   ```
   Your filled-in `mail-config.php` and `google-config.php` are separate files and aren't affected.
2. **Tests were not run.** The test database `venusep_laravel_test` doesn't exist on this laptop, so `venusep:check`, `phpunit` and `venusep:flows` couldn't run. Run them before merging.
3. Delete the backup files in `C:\venusep_collation_fix\` once the site works.
4. Commit the code changes when ready:
   - `laravel/public/assets/js/customer/venues.js`
   - `laravel/resources/views/customer/venues.blade.php`
   - `laravel/public/assets/css/customer-nav.css`
   - `laravel/resources/views/layouts/shell.blade.php`

   Don't commit `mail-config.php`, `google-config.php` or `.env`. Git ignores them already.
