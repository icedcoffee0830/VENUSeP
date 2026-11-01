# VENUSeP: run it on your computer

The system runs on Laravel, in the `laravel/` folder.

## What you need

- XAMPP with PHP 8.2 or newer and MySQL/MariaDB
- [Composer](https://getcomposer.org/download/)
- Git

These steps assume XAMPP is in `C:\xampp`. If yours is somewhere else, change the paths. Use **Command Prompt (cmd)**, not PowerShell, because `<` does not work in PowerShell.

## 1. Get the latest code

```
git checkout main
git pull origin main
```

If you have work on your own branch, bring the new code into it:

```
git checkout YOUR-BRANCH
git merge main
```

## 2. Set up the database

Start MySQL in XAMPP. From the project's main folder, do **one** of these.

**A. You already have a `venusep` database.** Back it up first:

```
C:\xampp\mysql\bin\mysqldump -u root --routines --triggers venusep > C:\venusep_backup.sql
```

**B. You don't have one yet.** Create it:

```
C:\xampp\mysql\bin\mysql -u root < venusep_schema.sql
```

**Then, in both cases,** bring it up to date. It's safe to run this more than once:

```
for %i in (01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16) do C:\xampp\mysql\bin\mysql -u root venusep < venusep_migration_%i.sql
```

Never run `php artisan migrate` on this database.

## 3. Set up the app

```
cd laravel
composer install --no-scripts
php artisan package:discover
copy .env.example .env
php artisan key:generate
php artisan venusep:encrypt-ids
```

`APP_KEY` in `.env` encrypts the stored IDs. If you lose it, those IDs can never be opened again. Never commit it. If you already have a `.env` with a key, keep that key and skip `key:generate`.

If `php` isn't recognized, use `C:\xampp\php\php.exe` in its place.

In `C:\xampp\php\php.ini`, make sure these lines have no `;` in front of them, then restart Apache if it's running: `extension=pdo_mysql`, `extension=fileinfo`, `extension=mbstring`, `extension=openssl`.

For faster pages, also set `zend_extension=opcache` and `opcache.enable=1`.

## 4. Start it

```
php artisan serve
```

Open **http://localhost:8000** and keep that window open while you use the system.

Use this instead of Apache: through Apache, other people on your network might be able to open project files such as the `.sql` files.

## 5. Sign in

| Page | Account | Password |
|---|---|---|
| `/admin/admin-login` | `admin@gmail.com` | `admin123` |
| `/customer/customer-login` | `customer@gmail.com` | `customer123` |

The admin sets up an authenticator app at first sign-in. Create staff accounts in **Staff Management**. Staff choose their own password at first sign-in.

These are development accounts only. Change them before any real use.

## Office settings

Admins set each office's hours, closed days, closures, USeP discount, grace days, minimum notice and cleanup gap on **Office Settings**. Bahay Alumni and USeP Venues start with weekends closed; the hostel stays open.

## Nightly backup (on the real server)

Add this in Windows Task Scheduler, every night, with `laravel` as the "Start in" folder:

```
C:\xampp\php\php.exe artisan venusep:backup
```

IDs are left out of backups on purpose. After a restore, customers with open bookings upload theirs again.

## If the system is down

The counter uses a paper form: guest, room, dates, ID checked and amount taken. Staff enter each one when the system is back.

## Good to know

- **Emails:** nothing is really sent. Each email is saved as a file instead, unless you add a mail settings file.
- **Google sign-in:** the button stays off until you add a settings file. The template is `laravel/legacy/includes/google-config.example.php`; copy it to `laravel/legacy/includes/google-config.php`. Never commit it.
- **IDs and receipts:** use fake ones only while testing, never a real person's.
- **Something went wrong?** SQL errors are written to `laravel/storage/logs/laravel.log`. With `APP_DEBUG=true` in `.env` you also see the real error on the page. A live server must use `APP_DEBUG=false`, so visitors never see it.
- **Automated tests:** `php artisan test` needs its own test database. The browser tester (`venusep:flows`) also needs a snapshot file that isn't in the repo, so ask Aron for it.
- **Project rules and decisions:** see `HANDOFF.md` section 13 and `DB-DECISIONS.md`.
