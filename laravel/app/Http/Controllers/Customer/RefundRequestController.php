<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;

/* ==================================================================
   REFUND REQUEST (was customer/refund-request.php) — the customer's only
   entry into the refund chain, reached from "Request Refund" in Booking
   History (offered only where cb_is_refundable() accepts).

   AGREED 2026-09-09 (unchanged): the booking is NOT cancelled by a request;
   denial is final, paperwork problems are "returned for correction"; the
   customer never types an amount; reason = category + free text; System
   Receipt + proof of payment required to FILE, the OR may follow; filing
   deadline = cb_is_refundable(); venue bookings only for now.

   ELIGIBILITY IS RE-CHECKED HERE, not trusted from the link, including the
   refund policy snapshot (legacy refund-policy.php). Submitting POSTs to
   customer.refund-submit.
   ================================================================== */
class RefundRequestController
{
    public function __invoke(Request $request)
    {
        extract(legacy('customer-bookings'));

        /* is_string matters: ?booking[]=x is a hostile shape, never cast it. */
        $rawRef = $request->query('booking');
        $requestedRef = is_string($rawRef) ? substr(trim($rawRef), 0, 64) : '';
        $booking      = $requestedRef === '' ? null : cb_find($customerBookings, $requestedRef);

        /* Why the form cannot be shown, in the customer's words. null = show it.
           Order matters: the payment axis carries the whole refund chain. */
        $refusal = null;
        if ($booking === null) {
            $refusal = $requestedRef === ''
                ? 'No booking was selected. Open a booking from your history and choose "Request Refund".'
                : 'We could not find booking ' . $requestedRef . ' on your account.';
        } elseif (!cb_is_refundable($booking) && $booking['refundStatus'] !== 'returned_for_correction') {
            /* (returned for correction = paperwork to fix, not a new request: the
               form opens to resubmit it, and refund-submit checks that state.) */
            $ref = $booking['bookingId'];
            if ($booking['paymentStatus'] === 'Refunded') {
                $refusal = 'Booking ' . $ref . ' has already been refunded, so there is nothing left to request. If the amount never reached you, contact the venue office with your reference.';
            } elseif ($booking['paymentStatus'] === 'Refund requested') {
                $refusal = 'Booking ' . $ref . ' already has a refund request with USeP staff. Withdraw the existing one from your Booking History if you need to change it.';
            } elseif ($booking['paymentStatus'] !== 'Paid') {
                $refusal = 'Booking ' . $ref . ' has not been paid, so there is nothing to refund. Its payment status is "' . $booking['paymentStatus'] . '".';
            } elseif ($booking['eventDateIso'] <= date('Y-m-d')) {
                $refusal = 'The event for booking ' . $ref . ' was held on ' . $booking['eventDate'] . '. Refunds can only be requested before the event takes place — please contact the venue office directly.';
            } elseif (empty($booking['refundsAllowed'])) {
                /* The policy snapshot (2026-09-16), checked on the SERVER. */
                $refusal = 'Booking ' . $ref . ' is non-refundable. It was made while USeP\'s no-refund policy was in effect, and a booking keeps the policy it was made under. If USeP closes or cancels your venue, the venue office will offer you a replacement room or a new date instead.';
            } else {
                $refusal = 'Booking ' . $ref . ' is not eligible for a refund request. Its booking status is "' . $booking['bookingStatus'] . '".';
            }
        }

        /* Documents: optional => false = required to FILE; true = required before
           the refund can be PAID. A cash payer's proof of payment IS the OR. */
        $requiredDocs = [];
        if ($booking !== null) {
            $requiredDocs[] = ['key' => 'txn', 'label' => 'VENUSeP System Receipt', 'optional' => false,
                'hint' => 'Issued by VENUSeP when your booking was confirmed.'];
            if ($booking['method'] === 'GCash') {
                $requiredDocs[] = ['key' => 'gcash', 'label' => 'GCash Payment Receipt', 'optional' => false,
                    'hint' => 'The GCash receipt screenshot you sent when you paid.'];
            }
            $requiredDocs[] = ['key' => 'or', 'label' => 'Official Receipt (OR)', 'optional' => true,
                'hint' => $booking['method'] === 'GCash'
                    ? 'Issued by the University Cashier. Needed before your refund can be paid out — send it later if you do not have it yet.'
                    : 'The official receipt you were given at the counter when you paid. Needed before your refund can be paid out — send it later if you do not have it to hand.'];
        }

        /* Keyed by the refunds.reason_category enum, so the form posts the stored value. */
        $refundReasons = [
            'event_cancelled'   => 'Event cancelled by the organiser',
            'schedule_conflict' => 'Schedule conflict — need a different date',
            'wrong_room'        => 'Booked the wrong room or venue',
            'venue_unavailable' => 'Room unavailable or closed by USeP',
            'payment_error'     => 'Paid twice / wrong amount sent',
            'other'             => 'Other',
        ];

        /* The refund's CURRENT state, from the `refunds` table. */
        $rrExisting = null;
        $rrResubmit = false;
        if ($booking) {
            $rrStmt = venusep_db()->prepare(
                'SELECT refund_status, notes, official_receipt_pending, DATE(requested_at) AS filed
                   FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1'
            );
            $rrStmt->execute([':b' => booking_id_from_reference($booking['bookingId'])]);
            if ($rrRow = $rrStmt->fetch()) {
                /* The page's four states, mapped from the seven the chain has.
                   'withdrawn' deliberately maps to nothing: free to file again. */
                $rrMap = [
                    'requested'               => 'open',
                    'under_review'            => 'open',
                    'returned_for_correction' => 'fix',
                    'approved'                => 'open',
                    'rejected'                => 'denied',
                    'completed'               => 'refunded',
                ];
                if (isset($rrMap[$rrRow['refund_status']])) {
                    $rrExisting = [
                        'status'    => $rrMap[$rrRow['refund_status']],
                        'staffNote' => (string) $rrRow['notes'],
                        'orPending' => (bool) $rrRow['official_receipt_pending'],
                        'filed'     => $rrRow['filed'],
                    ];
                    $rrResubmit = $rrRow['refund_status'] === 'returned_for_correction';
                }
            }
        }

        return view('customer.refund-request', compact(
            'customerContact', 'booking', 'refusal', 'requiredDocs', 'refundReasons', 'rrExisting', 'rrResubmit'
        ));
    }
}
