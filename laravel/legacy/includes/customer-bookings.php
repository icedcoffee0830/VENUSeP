<?php
/* Holds: the signed-in customer's contact details and own bookings ($customerContact, $customerBookings). */
/* =====================================================================
   CUSTOMER BOOKINGS — the logged-in customer's own bookings and contact
   details. A thin wrapper over bookings.php, which is the one
   read path both portals share.

   Included by:
     BookingHistoryController     (the history table)
     RefundRequestController      (the refund form looks a booking up)
     VenuesController, faq.php, the booking pages
                                      ($customerContact — who is signed in)

   WHY IT IS STILL ITS OWN FILE: several pages want "the person using this
   page" without caring how bookings are queried. Keeping that here means
   bookings.php stays about bookings.

   WHO THIS IS. $customerContact used to be a hard-coded demo customer, and
   the header had already drifted away from it — the nav chip read the real
   session while every other line on the page said "Juan Miguel Dela Cruz",
   so one screen could show two different people. It is now the session's
   own row, and a GUEST gets empty strings rather than somebody else's name.

   ⚠️ Pages that require a login must call customer_require_login() FIRST.
   This file does not enforce anything: the landing page and the FAQ are
   public and include it purely to greet whoever happens to be signed in.
   ===================================================================== */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bookings.php';

/* The session customer's own contact details — or blanks for a guest.
   The phone matters beyond display: a GCash account IS a mobile number in
   PH, so this is what a refund destination is prefilled from. */
function customer_contact() {
    static $contact = null;
    if ($contact !== null) {
        return $contact;
    }
    $contact = ['id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'universityId' => ''];
    if (!customer_logged_in()) {
        return $contact;
    }
    if (venusep_db() === null) {
        return $contact;
    }
    try {
        $row = \App\Queries\CustomerQueries::findContact((int) session('customer_id'));
        if ($row) {
            $contact = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['full_name'],
                'email'        => (string) $row['email'],
                'phone'        => (string) $row['phone'],
                'universityId' => (string) $row['university_id_no'],
                'photo'        => (string) $row['photo_path'],
            ];
        }
    } catch (PDOException $e) {
        sql_failed($e);
        // keep the guest blanks
    }
    return $contact;
}

$customerContact = customer_contact();

/* This customer's bookings — empty for a guest, which is correct: a guest
   has no bookings, and showing someone else's would be a data leak. */
$customerBookings = $customerContact['id']
    ? bookings_for_customer($customerContact['id'])
    : [];

/* Look one booking up by its reference WITHIN this customer's own list.
   Scoped on purpose: refund-request.php takes the reference from the URL, and
   this is what stops one customer opening another customer's booking by typing
   its number. Returns null when the reference is not theirs, which the page
   already handles by refusing politely. */
function cb_find($bookings, $reference) {
    foreach ($bookings as $booking) {
        if ($booking['bookingId'] === $reference) return $booking;
    }
    return null;
}
