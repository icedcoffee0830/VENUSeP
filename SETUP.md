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
for %i in (01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17) do C:\xampp\mysql\bin\mysql -u root venusep < venusep_migration_%i.sql
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
| `/admin/admin-login` | `admin@gmail.com` (super admin) | `admin123` |
| `/admin/admin-login` | `cedu.admin@gmail.com` (USeP CEDU admin) | `ceduadmin123` |
| `/customer/customer-login` | `customer@gmail.com` | `customer123` |

The admin sets up an authenticator app at first sign-in. Create staff accounts in **Staff Management**. Staff choose their own password at first sign-in.

These are development accounts only. Change them before any real use.

## Office settings

Admins set each office's hours, closed days, closures, USeP discount, grace days, minimum notice and cleanup gap on **Office Settings**. Bahay Alumni and USeP Venues start with weekends closed; the hostel stays open.

## Automatic daily backup

Run this once in **PowerShell** (change the two paths to your own XAMPP and project folders). It backs up every day at 12:00, or as soon as the laptop is on if it was off at 12:00. MySQL must be running.

```
$a = New-ScheduledTaskAction -Execute "C:\xampp\php\php.exe" -Argument "artisan venusep:backup" -WorkingDirectory "C:\xampp\htdocs\VENUSeP-laravel\laravel"
$t = New-ScheduledTaskTrigger -Daily -At 12:00
$s = New-ScheduledTaskSettingsSet -StartWhenAvailable
Register-ScheduledTask -TaskName "VENUSeP daily backup" -Action $a -Trigger $t -Settings $s
```

These show on Backup & Restore as "Automatic daily backup". The newest 7 are kept, apart from the newest 10 made by hand.

IDs are left out of backups on purpose. After a restore, customers with open bookings upload theirs again.

## Restore without signing in

When the site is broken and nobody can sign in, restore from **cmd** in the `laravel` folder:

```
C:\xampp\php\php.exe artisan venusep:restore
C:\xampp\php\php.exe artisan venusep:restore venusep-backup-20261116-120000-auto.tar.gz
```

The first line lists the backups; the second restores one (a path to a `.tar.gz`, for example on a USB stick, works too). It asks you to type the database name first. It makes a safety copy before it starts; if the database is too damaged for that, add `--no-safety-copy`.

## Two or more laptops (server transfer)

One laptop holds the main database. The others keep a live copy of it and a copy of the photos, IDs and receipts. If the main laptop breaks, the super admin switches to a copy on **Server** (sidebar, super admin only) and nothing is lost. Start with two laptops: A (main) and B (copy).

**The easy way: the setup scripts** in the `failover-setup` folder. Both laptops must be on the same phone hotspot, with MySQL running, and laptop A must have migrations 21 and 22.

1. On laptop A, double-click `failover-setup\setup-laptop-A.bat` (say Yes to administrator rights). It asks for three passwords of your choice, then sets up MySQL, the firewall, the accounts, `.env`, the 1-minute task and the website. When it says so, restart MySQL in the XAMPP panel. At the end it opens the emergency page: sign in and press **Unlock saving** on A's row. Copy `laravel\.env` to a USB stick.
2. On laptop B, double-click `failover-setup\setup-laptop-B.bat`. It asks for A's address (shown at the end of A's setup) and the `.env` on the USB stick. It keeps B's own database in your user folder, then loads A's and starts copying. Restart MySQL when it says so.
3. On A's Server page: **Find servers on this hotspot**, **Add to the list**, **Test**. B must say "Up to date".

**Every session after that, on every laptop: double-click `failover-setup\start-venusep.bat`.** It starts MySQL and the website, finds the other laptops on the hotspot (by their Windows names, so new addresses don't matter), works out which one is the main, points every website and copy at it, and opens the Server page. The main is unlocked by itself only when every laptop it knows has answered, none of them already takes saves, and it holds the newest data; otherwise the window says what it is waiting for. Start the main laptop last if you can. **Continue with Google works on every laptop at `http://localhost:8000`** (the start file runs it too): Google accepts `localhost`, never a hotspot address, so phones and other laptops use port 8100 without Google. Each laptop needs `laravel/legacy/includes/google-config.php`, copied like the `.env` (by USB, never by chat or git). **Phones over https:** on the laptop people use, double-click `failover-setup\start-tunnel.bat` (needs internet on the hotspot; it installs Cloudflare's free `cloudflared` the first time). It prints an address like `https://word-word-word.trycloudflare.com` that phones open; it changes every time. The setup scripts find the main by themselves too: a new copy only needs laptop A's `.env`.

`failover-setup\undo.bat` puts a laptop back (the old `my.ini` and `.env`, no task, no firewall rules). The manual steps below are what the scripts do.

Do steps A to F on **every** laptop. Use **cmd** as administrator. Stop MySQL in the XAMPP panel before step A.

**A. MySQL settings.** Add these lines under `[mysqld]` in `C:\xampp\mysql\bin\my.ini`. Only `server-id` differs: A=1, B=2, C=3, D=4.

```
server-id=1
bind-address=0.0.0.0
skip-name-resolve
log-bin=binlog
relay-log=relay-bin
binlog-format=ROW
binlog-do-db=venusep_laravel
log-slave-updates=ON
gtid-strict-mode=ON
expire_logs_days=7
log_bin_trust_function_creators=1
read-only=ON
rpl_semi_sync_master_enabled=ON
rpl_semi_sync_slave_enabled=ON
rpl_semi_sync_master_wait_point=AFTER_SYNC
rpl_semi_sync_master_timeout=10000
slave_net_timeout=10
```

`binlog-do-db` must be the database name in `.env` (`DB_DATABASE`). Every laptop starts **locked** (`read-only`), so a laptop that restarts never takes saves by mistake: the super admin unlocks the main on the emergency page (step G).

**B. Firewall.** Then set the hotspot to **Private** (Settings > Network > the hotspot).

```
netsh advfirewall firewall add rule name="VENUSeP MySQL" dir=in action=allow protocol=TCP localport=3306 profile=private
netsh advfirewall firewall add rule name="VENUSeP web" dir=in action=allow protocol=TCP localport=8100 profile=private
```

**C. Accounts.** Start MySQL, then run `C:\xampp\mysql\bin\mysql.exe -u root` and paste this. Pick three passwords and use the same ones on every laptop.

```sql
CREATE USER 'venusep_app'@'%' IDENTIFIED BY 'APP_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES ON venusep_laravel.* TO 'venusep_app'@'%';
CREATE USER 'venusep_admin'@'%' IDENTIFIED BY 'ADMIN_PASS';
GRANT ALL PRIVILEGES ON venusep_laravel.* TO 'venusep_admin'@'%';
GRANT SUPER, REPLICATION CLIENT, RELOAD ON *.* TO 'venusep_admin'@'%';
GRANT SELECT ON mysql.proc TO 'venusep_admin'@'%';
CREATE USER 'venusep_repl'@'%' IDENTIFIED BY 'REPL_PASS';
GRANT REPLICATION SLAVE ON *.* TO 'venusep_repl'@'%';
DROP USER IF EXISTS ''@'localhost';
FLUSH PRIVILEGES;
```

**D. `.env`.** Make it on laptop A, then copy the **same file** to every laptop by USB, never by chat or git. The same `APP_KEY` everywhere is required: without it, the IDs can't be opened after a switch. `DB_HOST` is laptop A's address (step G shows it), so every laptop's website starts on the main; after a switch the Server page keeps the new address in `storage/app/failover/server.json`.

```
DB_HOST=A_IP
DB_USERNAME=venusep_app
DB_PASSWORD=APP_PASS
DB_ADMIN_USERNAME=venusep_admin
DB_ADMIN_PASSWORD=ADMIN_PASS
DB_REPL_USERNAME=venusep_repl
DB_REPL_PASSWORD=REPL_PASS
FAILOVER_WEB_PORT=8100
```

**E. The website and the 1-minute task.** Start the site so the other laptops can reach it:

```
php artisan serve --host=0.0.0.0 --port=8100
```

Then, once, in **PowerShell** (change the two paths). Every minute it refreshes the super admin's emergency sign-in, copies the photos, IDs and receipts, and checks the copies.

```
$a = New-ScheduledTaskAction -Execute "C:\xampp\php\php-win.exe" -Argument "artisan venusep:failover-tick" -WorkingDirectory "C:\xampp\htdocs\VENUSeP-laravel\laravel"
$t = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "VENUSeP failover tick" -Action $a -Trigger $t
```

**F. The first copy (laptop B, C, D only).** This **replaces** the laptop's own `venusep_laravel` with A's: back it up first if it holds anything you need. Load A's database, then start copying. `A_IP` is A's address, shown on A's Server page under "This laptop".

```
C:\xampp\mysql\bin\mysqldump.exe -h A_IP -u venusep_admin -p --single-transaction --gtid --master-data=1 --routines --triggers --add-drop-database --databases venusep_laravel > %USERPROFILE%\seed.sql
C:\xampp\mysql\bin\mysql.exe -u root --init-command="SET sql_log_bin=0" < %USERPROFILE%\seed.sql
C:\xampp\mysql\bin\mysql.exe -u root -e "CHANGE MASTER TO MASTER_HOST='A_IP', MASTER_PORT=3306, MASTER_USER='venusep_repl', MASTER_PASSWORD='REPL_PASS', MASTER_USE_GTID=slave_pos; START SLAVE;"
```

**G. On laptop A.** Open `http://A_IP:8100/server-emergency` (A is still locked). Sign in as the super admin with the authenticator code, then press **Unlock saving** on A's row. Add B (and C, D) with **Find servers on this hotspot** or by typing the address, then **Test** each one: a copy must say "Up to date".

**Every save now waits until a copy has it.** If no copy is connected, the main keeps working: the first save waits at most 10 seconds, then saving goes on without a copy, and the Server page warns the super admin that saves are on one laptop only. Protection comes back on by itself when a copy returns.

**When the main breaks:** on any laptop, open its address, follow "Super admin? Sign in to switch servers", sign in, and press **Switch to this** on the copy that says it applied everything. Its website now uses the new main. When laptop A comes back it stays locked: press **Make it a copy** on A's row, wait for "Up to date", then switch back if you want.

A copy that says **Has changes of its own** was written to directly. The main always wins: press **Rebuild this copy**. Run migrations on the main only.

## If the system is down

The counter uses a paper form: guest, room, dates, ID checked and amount taken. Staff enter each one when the system is back.

## Good to know

- **Emails:** nothing is really sent. Each email is saved as a file instead, unless you add a mail settings file.
- **Google sign-in:** the button stays off until you add a settings file. The template is `laravel/legacy/includes/google-config.example.php`; copy it to `laravel/legacy/includes/google-config.php`. Never commit it.
- **IDs and receipts:** use fake ones only while testing, never a real person's.
- **Something went wrong?** SQL errors are written to `laravel/storage/logs/laravel.log`. With `APP_DEBUG=true` in `.env` you also see the real error on the page. A live server must use `APP_DEBUG=false`, so visitors never see it.
- **Automated tests:** `php artisan test` needs its own test database. The browser tester (`venusep:flows`) also needs a snapshot file that isn't in the repo, so ask Aron for it.
- **Project rules and decisions:** see `HANDOFF.md` section 13 and `DB-DECISIONS.md`.
