<?php

/* Read through config, not getenv(): once config:cache is on, .env is not loaded. */
return [
    'doc_root'  => env('VENUSEP_DOC_ROOT'),    // private files: IDs, receipts, backups
    'max_days_ahead' => (int) env('VENUSEP_MAX_DAYS_AHEAD', 183),   // the booking window, every office; fixed at 183, the env is for tests only
    'max_unpaid' => (int) env('VENUSEP_MAX_UNPAID', 5),             // unpaid upcoming bookings per office, online
    'mail_config' => env('VENUSEP_MAIL_CONFIG'),   // tests only: fake mail settings instead of legacy/includes/mail-config.php
    'mysql_bin' => env('VENUSEP_MYSQL_BIN'),   // mysqldump / mysql folder; empty = XAMPP's own
    /* failover (SETUP.md): the website signs in as a plain account that read-only copies
       can stop; backups and restores need this fuller one. Empty = the website's own. */
    'db_admin_user' => env('DB_ADMIN_USERNAME'),
    'db_admin_pass' => env('DB_ADMIN_PASSWORD', ''),
    /* the account a copy signs in with to pull changes from the main (Server page, Make it a copy) */
    'db_repl_user' => env('DB_REPL_USERNAME', 'venusep_repl'),
    'db_repl_pass' => env('DB_REPL_PASSWORD', ''),
];
