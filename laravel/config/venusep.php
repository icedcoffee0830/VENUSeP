<?php

/* Read through config, not getenv(): once config:cache is on, .env is not loaded. */
return [
    'doc_root'  => env('VENUSEP_DOC_ROOT'),    // private files: IDs, receipts, backups
    'mysql_bin' => env('VENUSEP_MYSQL_BIN'),   // mysqldump / mysql folder; empty = XAMPP's own
];
