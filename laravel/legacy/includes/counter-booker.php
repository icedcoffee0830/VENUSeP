<?php
/* Holds: the "who is this booking for" step staff fill in at the counter. */
/* =====================================================================
   COUNTER BOOKER (2026-10-27) — the "who is this booking for" step that
   staff fill in when booking for someone at the counter: find their
   account and have them confirm it with their password
   (CounterCustomerController), or take a walk-in's details; then confirm
   the ID they were shown.

   Moved here verbatim from RoomReservationController so the venue AND
   the hostel booking pages share ONE copy — two counters must not drift.
   Include it BEFORE the page's main script (state uses booker0). It
   calls back into what every booking page defines: state, render(), esc(),
   CSRF, COUNTER_STAFF, DISCOUNT_PERCENT, COUNTER_OVERDUE — and expects a 'detail' screen to
   go back to and a 'review' screen to go on to.
   ===================================================================== */
require_once __DIR__ . '/bookings.php';   /* PH_MOBILE_MESSAGE, the counter's phone rule */
?>
<script>
  /* Values from PHP for assets/js/counter-booker.js */
  const COUNTER_NO_PASSWORD = <?php echo json_encode(\App\Http\Controllers\Admin\CounterCustomerController::NO_PASSWORD, JSON_HEX_TAG); ?>;
  var counterBooker = {
    mobileMessage: <?php echo json_encode(PH_MOBILE_MESSAGE); ?>,
    dashboardUrl: <?php echo json_encode(route('admin.dashboard')); ?>,
    customerSearchUrl: <?php echo json_encode(route('admin.customer-search')); ?>,
    customerCreateUrl: <?php echo json_encode(route('admin.customer-create')); ?>,
    customerVerifyUrl: <?php echo json_encode(route('admin.customer-verify')); ?>,
  };
</script>
<script src="<?php echo asset_stamped('assets/js/counter-booker.js'); ?>"></script>
