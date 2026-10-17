<?php
/* =====================================================================
   BACKFILL — issue the missing VENUSeP System Receipts.

   Run:  php tests/backfill_system_receipts.php

   WHY THIS IS NEEDED. A System Receipt (system_receipts) is normally
   issued by receipt_issue() (includes/system-receipt.php), called from
   admin/booking-action.php the moment staff confirm a payment through the
   live app. venusep_demo_seed.sql's sp_seed_demo() does not go through
   that path — it inserts `payments` rows directly with
   payment_record_status = 'confirmed' for speed — so every demo booking
   that LOOKS paid in the UI has no receipt behind it, and the "Receipt"
   button in Transaction History / Booking History stays disabled for all
   of them. This script closes that gap after a reseed, using the exact
   same receipt_issue() the live app uses, so the snapshot it writes is
   never a second, hand-rolled copy of what a receipt contains.

   SAFE TO RE-RUN. receipt_issue() is idempotent per payment (it looks for
   an existing system_receipts row by payment_id before inserting), so
   running this twice, or against a database that already has some real
   receipts, only fills in what is actually missing.

   Run this once after any `CALL sp_seed_demo();`.
   ===================================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/system-receipt.php';

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Could not reach the database.\n");
    exit(1);
}

/* Every booking with at least one CONFIRMED payment — receipt_issue()
   itself re-checks for an existing receipt and no-ops if one is already
   there, so this list does not need to pre-filter that. */
$ids = $pdo->query(
    "SELECT DISTINCT booking_id FROM payments WHERE payment_record_status = 'confirmed' ORDER BY booking_id"
)->fetchAll(PDO::FETCH_COLUMN);

$issued = 0;
$already = 0;
$failed = 0;
foreach ($ids as $bookingId) {
    try {
        $before = $pdo->query('SELECT COUNT(*) FROM system_receipts WHERE booking_id = ' . (int) $bookingId)->fetchColumn();
        $receiptId = receipt_issue($pdo, (int) $bookingId, 0);
        if ($receiptId === null) {
            continue;   // no confirmed payment after all (shouldn't happen given the query above)
        }
        if ((int) $before > 0) {
            $already++;
        } else {
            $issued++;
            echo "issued receipt {$receiptId} for booking {$bookingId}\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAILED booking {$bookingId}: " . $e->getMessage() . "\n");
    }
}

echo "\n{$issued} receipt(s) issued, {$already} already had one, {$failed} failed, " . count($ids) . " booking(s) checked.\n";
exit($failed > 0 ? 1 : 0);
