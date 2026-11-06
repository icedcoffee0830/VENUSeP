<?php

/* Read through config, not getenv(): once config:cache is on, .env is not loaded. */
return [
    'doc_root'  => env('VENUSEP_DOC_ROOT'),    // private files: IDs, receipts, backups
    'max_days_ahead' => (int) env('VENUSEP_MAX_DAYS_AHEAD', 183),   // the booking window, every office; fixed at 183, the env is for tests only
    'max_unpaid' => (int) env('VENUSEP_MAX_UNPAID', 5),             // unpaid upcoming bookings per office, online
    'mail_config' => env('VENUSEP_MAIL_CONFIG'),   // tests only: fake mail settings instead of legacy/includes/mail-config.php
    'mysql_bin' => env('VENUSEP_MYSQL_BIN'),   // mysqldump / mysql folder; empty = XAMPP's own
];
