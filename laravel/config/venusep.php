<?php

/* Read through config, not getenv(): once config:cache is on, .env is not loaded. */
return [
    'doc_root'  => env('VENUSEP_DOC_ROOT'),    // private files: IDs, receipts, backups
    'max_days_ahead' => (int) env('VENUSEP_MAX_DAYS_AHEAD', 365),   // how far ahead a venue can be booked
    'max_unpaid' => (int) env('VENUSEP_MAX_UNPAID', 5),             // unpaid upcoming bookings per office, online
    'mysql_bin' => env('VENUSEP_MYSQL_BIN'),   // mysqldump / mysql folder; empty = XAMPP's own
];
