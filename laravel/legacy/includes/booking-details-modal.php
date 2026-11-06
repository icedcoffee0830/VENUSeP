<?php
/* Holds: the booking details popup behind "View" on the history pages. */
/* =====================================================================
   BOOKING DETAILS MODAL (2026-10-27) — "View" on Transaction History
   (both portals) and the customer's Booking History.

   Include once, anywhere in <body>, on a page ONE folder below the project
   root (customer/ or admin/). Then call:
       openBookingDetails('VB-2026-017')
   It overlays the page (the page behind it blurred), reads the booking from
   booking-details.php (which decides who may see what), and closes on ×,
   Escape or a click outside.

   The valid ID, the uploaded GCash receipt and the System Receipt are LINKS
   inside it: their own pages already serve them, with their own permission
   checks (valid-id-view.php, document-view.php, receipt.php).

   LARAVEL: the links are route() URLs. "System Receipt" goes to the staff
   receipt page on an admin page (route admin.*) and to the customer's own
   receipt page everywhere else — the old relative "receipt.php" did the same
   by resolving against admin/ or customer/.
   ===================================================================== */
$bdmReceiptUrl = request()->routeIs('admin.*') ? route('admin.receipt') : route('customer.receipt');
?>
<link rel="stylesheet" href="<?php echo asset_stamped('assets/css/booking-details-modal.css'); ?>" />
<script>
  /* Values from PHP for assets/js/booking-details-modal.js */
  var bookingDetailsModal = {
    validIdViewUrl: <?php echo json_encode(route('root.valid-id-view')); ?>,
    bdmReceiptUrl: <?php echo json_encode($bdmReceiptUrl); ?>,
    documentViewUrl: <?php echo json_encode(route('root.document-view')); ?>,
    bookingDetailsUrl: <?php echo json_encode(route('root.booking-details') . '?booking='); ?>,
  };
</script>
<script src="<?php echo asset_stamped('assets/js/booking-details-modal.js'); ?>"></script>
