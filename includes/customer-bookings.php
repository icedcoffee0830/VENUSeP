<?php
/* =====================================================================
   CUSTOMER BOOKINGS — the logged-in customer's own bookings and contact
   details. A thin wrapper over includes/bookings.php, which is the one
   read path both portals share.

   Included by:
     customer/booking-history.php     (the history table)
     customer/refund-request.php      (the refund form looks a booking up)
     customer/venusep_venue_booking.php, faq.php, the booking pages
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
    $pdo = venusep_db();
    if ($pdo === null) {
        return $contact;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.full_name, c.phone, c.university_id_no, c.photo_path, u.email
               FROM customers c LEFT JOIN users u ON u.id = c.user_id
              WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int) $_SESSION['customer_id']]);
        $row = $stmt->fetch();
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

/* DEMO MODE: bookings this browser has pretended to make, merged on top.
   Without this the customer submits, sees a reference, opens their history and
   finds nothing — which looks like the booking failed rather than like a demo.
   They exist only in this session, so nobody else ever sees them, and they are
   newest-first because a demo booking is the one just made. */
require_once __DIR__ . '/demo-mode.php';
if (demo_mode_on() && $customerContact['id']) {
    $cbDemo = array_filter(demo_bookings(), function ($b) use ($customerContact) {
        return (int) $b['customerId'] === (int) $customerContact['id'];
    });
    $customerBookings = array_merge(array_values($cbDemo), $customerBookings);
}

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
