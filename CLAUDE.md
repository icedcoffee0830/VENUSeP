# VENUSeP

USeP's venue and hostel booking system: Laravel 12 on XAMPP (Windows), MySQL through raw PDO.

## Where things are
- `laravel/`: the whole app.
- `laravel/legacy/includes/*.php`: the shared code, loaded by the `legacy()` helper once per PHP process. So one test must not sign in as staff and then admin.
- `venusep_schema.sql`, `venusep_migration_NN.sql`: the database layout. `backup.php` reads these files, so they stay here.
- Queries are real SQL, written by hand. Match that style.

## Tests (from `laravel/`)
- `php vendor/bin/phpunit`: about 6 minutes, on `venusep_laravel_test`.
- `php artisan venusep:check --db=venusep_laravel_test`: every page as guest, customer, staff and admin.
- `php artisan venusep:flows`: a real browser through each process, on `venusep_laravel_flows`, port 8120. `--only=a,b` runs some of them. They read the working copy, so do not edit code while they run.
- `php artisan venusep:flows-tool reset --db=venusep_laravel_test --no-ansi`: resets the test database after a failed run.
- `run-tests.bat` runs the page checker and the flows; `run-tests-loop.bat` repeats them.

## Rules
- Never touch the real `venusep` database from a test or a script. Only the two test databases above.
- Never run `php artisan migrate`. Changes to the database are `venusep_migration_NN.sql` files, and Aron runs them on the real database himself.
- Do not run `config:cache` or `route:cache`.
- Debugging: SQL errors go to `storage/logs/laravel.log`. See `SETUP.md`.

## Read more
- `SETUP.md`: running it on a new computer.
- `HANDOFF.md` section 13: current state and Aron's decisions.
- `DB-DECISIONS.md`: settles policy questions (booking model, discounts, status). It wins over other docs.
