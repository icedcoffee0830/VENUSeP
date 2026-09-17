<?php
/* =====================================================================
   CUSTOMER BOOKINGS — [SIM] the logged-in customer's own bookings.
   THE ONE SOURCE, mirroring includes/venue-rooms.php.

   Included by:
     customer/booking-history.php   (the history table)
     customer/refund-request.php    (the refund form looks a booking up here)

   WHY AN INCLUDE: this array used to live inside booking-history.php. The
   moment a second page needed the same bookings we would have had two
   copies free to drift apart — the exact failure that produced four
   different room universes before includes/venue-rooms.php existed. One
   source ends that. At database time this becomes a SELECT over `bookings`
   for the session customer; nothing else on either page changes.

   Rooms + amounts are the real r1-r8 venue rooms (includes/venue-rooms.php)
   at their canonical per-day fee, so history stays coherent with what the
   booking pages show. Hostel bookings are deliberately NOT here yet — the
   refund flow is venue-only for now (agreed 2026-09-09).
   ===================================================================== */

/* seed row = [id, ROOM, eventDateIso, eventName, bookingStatus, paymentStatus, amount, method?]
   "Unpaid" (not "Failed") is the payment state of a rejected request — nothing
   failed, it was simply never paid. Methods are GCash/Cash only. */
$customerBookingSeeds = [
    ['201','USeP Gymnasium','2026-06-28','Intercollege Basketball Finals','Completed','Paid',8000],
    ['202','CIC Audio-Visual Room','2026-06-20','Research Documentary Screening','Completed','Paid',2000],
    ['203','Alumni Grand Ballroom','2026-06-12','Leadership Recognition Night','Approved','Paid',5000],
    ['204','Alumni Boardroom','2026-05-30','Undergraduate Thesis Defense','Completed','Paid',1500],
    ['205','Heritage Function Room','2026-05-22','Organization Planning Session','Cancelled','Refunded',2500],
    ['206','Admin Conference Hall','2026-05-15','Digital Literacy Workshop','Rejected','Unpaid',1800],
    ['207','Alumni Grand Ballroom','2026-04-26','Alumni Chapter Reunion','Completed','Paid',5000],
    ['208','Garden Pavilion','2026-04-18','Graduation Fellowship','Cancelled','Refunded',3500],
    ['209','USeP Gymnasium','2026-03-28','Academic Recognition Ceremony','Completed','Paid',8000],
    ['210','Obrero Function Hall','2026-03-14','Campus Wellness Fair','Rejected','Unpaid',3000],
    ['211','USeP Gymnasium','2026-02-21','Community Volleyball Clinic','Completed','Paid',8000],
    ['212','Admin Conference Hall','2026-02-08','Licensure Review Session','Approved','Paid',1800],
    /* [SIM] Future-dated rows. A refund can only be requested while the event has
       NOT yet happened, so every row above (all past events) correctly offers no
       refund — these three exist so the path is demonstrable. */
    ['213','Alumni Grand Ballroom','2026-10-03','College Awards Night','Approved','Paid',5000,'GCash'],
    /* [SIM] POST-PAY rows (DB-DECISIONS #18, 2026-09-17). A booking made while
       refunds were OFF cannot be paid before its event, so a future one is
       "Payment pending"; a finished one is "Payment due" until the grace days
       run out, then "Overdue" (still payable). The labels are the customer
       labels of payment_statuses await_event / await_gcash|await_cash / overdue. */
    ['214','CIC Audio-Visual Room','2026-09-26','Faculty Research Colloquium','Approved','Payment pending',2000,'Cash'],
    ['215','Obrero Function Hall','2026-11-14','Alumni Homecoming Dinner','Approved','Payment pending',3000,'GCash'],
    ['216','USeP Gymnasium','2026-09-15','Faculty Sportsfest','Completed','Payment due',8000,'GCash'],
    ['217','Heritage Function Room','2026-09-05','Department Orientation','Completed','Overdue',2500,'Cash'],
];

/* The refund switch (system_settings.refunds_enabled) — the one real,
   database-backed part of the refund flow. Sets $REFUNDS_ENABLED. */
require_once __DIR__ . '/refund-policy.php';

/* [SIM] REFUND POLICY SNAPSHOT (agreed 2026-09-16). Every booking records whether
   refunds were ON at the moment it was MADE (bookings.refunds_allowed), and that
   never changes. These are the demo bookings that were "made while refunds were
   ON"; every other booking was made under the default no-refund policy. #213 is
   here so the demo shows both cases side by side with the switch OFF: #213 still
   offers "Request Refund", #214 and #215 do not. At database time this list goes
   away — the column is read from the row. */
$cbMadeWhileRefundsOn = ['213'];

/* REFUND ELIGIBILITY (agreed 2026-09-09, policy snapshot added 2026-09-16) —
   the booking was made while refunds were allowed, it is PAID, and the event has
   not yet happened. A past event is a service already delivered; refunding it is
   a staff-side exception, not a self-service request. Both the history page
   (whether to offer the button) and the refund page (whether to accept the
   booking at all) ask THIS function, so they can never disagree.
   Deliberately NOT checked here: the live switch. Turning it OFF must not take a
   refund away from a booking that was sold as refundable. */
function cb_is_refundable(array $booking) {
    return !empty($booking['refundsAllowed'])
        && $booking['bookingStatus'] === 'Approved'
        && $booking['paymentStatus'] === 'Paid'
        && $booking['eventDateIso'] > date('Y-m-d');
}

/* Which venue each room belongs to, read from THE room source rather than
   repeated here. The admin side needs it (a queue row shows room + venue); the
   customer side already shows only the room. */
require_once __DIR__ . '/venue-rooms.php';
$cbVenueOfRoom = []; $cbCapOfRoom = [];
foreach ($venueRooms as $vr) { $cbVenueOfRoom[$vr['name']] = $vr['venue']; $cbCapOfRoom[$vr['name']] = $vr['capacity']; }

$customerBookings = [];
foreach ($customerBookingSeeds as $seed) {
    /* A booking was made 25 days before its event — but NEVER in the future.
       Unclamped, a far-off event (Nov 14) yields a "booked on" date weeks from
       now, which reads as nonsense on the history table and the refund summary. */
    $bookedTs = strtotime($seed[2] . ' -25 days');
    $latestTs = strtotime('-3 days');
    if ($bookedTs > $latestTs) { $bookedTs = $latestTs; }
    $row = [
        'bookingId'     => 'VB-2026-' . $seed[0],
        'venue'         => $seed[1],
        'roomName'      => $seed[1],
        'venueName'     => $cbVenueOfRoom[$seed[1]] ?? 'USeP Venues',
        'capacity'      => $cbCapOfRoom[$seed[1]] ?? 0,
        /* [SIM] attendee count was never seeded per booking; the admin detail
           view shows it, so derive a plausible one rather than print nothing.
           Real bookings carry venue_booking_details.attendee_count. */
        'attendees'     => (int) round(($cbCapOfRoom[$seed[1]] ?? 0) * 0.7),
        'eventName'     => $seed[3],
        'eventDate'     => date('F j, Y', strtotime($seed[2])),
        'eventDateIso'  => $seed[2],
        'bookingDate'   => date('F j, Y', $bookedTs),
        'bookingDateIso'=> date('Y-m-d', $bookedTs),
        'amount'        => '₱' . number_format($seed[6]),
        'amountValue'   => $seed[6],
        'paymentStatus' => $seed[5],
        'bookingStatus' => $seed[4],
        'method'        => $seed[7] ?? 'GCash',
        'refundsAllowed'=> in_array($seed[0], $cbMadeWhileRefundsOn, true),   /* bookings.refunds_allowed */
    ];
    /* WHEN this booking pays — from the same snapshot, via the one shared rule
       (includes/refund-policy.php). The history page prints the date. */
    $row['payment'] = payment_policy_for($row['refundsAllowed'], $seed[2]);
    $row['refundable'] = cb_is_refundable($row);
    $customerBookings[] = $row;
}

/* Look one booking up by its reference. Returns null when the reference is
   unknown — refund-request.php uses that to refuse politely instead of
   rendering a form for a booking that does not exist. */
function cb_find($bookings, $reference) {
    foreach ($bookings as $booking) {
        if ($booking['bookingId'] === $reference) return $booking;
    }
    return null;
}

/* Shared output helpers (both pages use them; guarded so a double include
   can never redeclare). */
if (!function_exists('bh_e')) {
    function bh_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('bh_badge')) {
    function bh_badge($status) { return 'badge-' . strtolower(preg_replace('/[^a-zA-Z]+/', '-', $status)); }
}

/* The session customer's own contact details. Kept HERE because three pages now
   need them and they had already drifted (customer-profile.php said
   0917 555 0123, the admin mockup said ...0142). The phone matters beyond
   display: a GCash account IS a mobile number in PH, so this is what the refund
   destination is prefilled from. At database time this is a row in `customers`. */
$customerContact = [
    'name'  => 'Juan Miguel Dela Cruz',   // PROJECT-HANDOFF 4.9 — one demo customer
    'email' => 'jmdelacruz@usep.edu.ph',
    'phone' => '0917 555 0123',
];

/* Normalise a PH mobile to 11 digits starting 09, or null if it cannot be one.
   Used on BOTH sides so the customer's entry and the staff's entry are compared
   as numbers, never as strings — "0917 555 0123" and "09175550123" are the same
   number and must never be reported as a mismatch. */
function cb_normalise_mobile($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if (strlen($d) === 12 && substr($d, 0, 2) === '63') $d = '0' . substr($d, 2);
    if (strlen($d) === 10 && substr($d, 0, 1) === '9')  $d = '0' . $d;
    return (strlen($d) === 11 && substr($d, 0, 2) === '09') ? $d : null;
}
