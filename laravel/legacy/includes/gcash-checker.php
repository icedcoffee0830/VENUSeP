<?php
/* Holds: the browser check that reads a GCash receipt picture. */
/* =====================================================================
   SHARED GCASH RECEIPT CHECKER — the ONE engine, used by every page that
   takes a GCash payment. Port of the verified PHP engine at
   C:\xamp5\htdocs\gcash-checker (lib/parser.php + checker.php + util.php),
   running fully in the browser via Tesseract.js.

   WHY THIS IS AN INCLUDE AND NOT COPY-PASTED
   -------------------------------------------
   Every venue takes payment into a DIFFERENT GCash account (see
   payment-settings.php). A copy-pasted twin means N engines: fix
   a matcher in one and the others stay broken, and the failure is silent —
   legitimate receipts auto-reject as "receiver mismatch" and it looks like
   an OCR bug. One engine; the account is asked for, never hardcoded.

   HOW TO USE (from a page that takes GCash):
       <?php include __DIR__ . '/../includes/gcash-checker.php'; ?>
   The include takes NO PHP parameters. The page MUST define three things
   in its own script — the only things the engine cannot know:

       gcAccount()           -> {name, number}  the account THIS booking's
                                money must land in. A FUNCTION, not a
                                constant: one page can serve rooms from
                                several venues (room-reservation.php serves
                                both Bahay Alumni and USeP Venues), so the
                                right account depends on the room and can
                                only be resolved at verdict time.
       gcExpectedCentavos()  -> int   the EXACT amount owed, in centavos
       gcBookingRef()        -> string the booking reference (duplicate store)

   plus `state.ocr` (receipt state) and `render()` (redraw after a verdict).
   Everything else below is self-contained.

   HARD GATES (any one => rejected):  duplicate file · not a GCash receipt ·
   duplicate reference no. · receiver != gcAccount() · amount != EXACT
   amount owed.
   SOFT FLAGS (=> needs staff review): unreadable fields, odd ref format,
   stale/future date, Amount != Total, low OCR confidence.
   Absence of data never rejects — only positive mismatch does.
   ===================================================================== */
?>
<script src="<?php echo asset_stamped('assets/js/gcash-checker.js'); ?>"></script>
