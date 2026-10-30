<?php

namespace App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/* =====================================================================
   BOOKING DETAILS (was booking-details.php) — what the "View" modal shows:
   Transaction History in both portals, and the customer's Booking History.

   GET  ?booking=<VB-ref>   -> JSON { ok, details: {...} }

   WHO MAY SEE WHAT — the same rule as valid-id-view and document-view:
   staff and admins see any booking, a customer only their own (including
   bookings made while demo mode is on, which live in their session), anyone
   else a 404. Never 403, so the reply does not confirm that a booking exists.
   READ ONLY. No route middleware: the 404 JSON is the guard.
   ===================================================================== */
class BookingDetailsController extends Controller
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'bookings');

        $bdIsStaff = session()->has('user_id') && session()->has('account_type')
            && in_array(session('account_type'), ['admin', 'staff'], true);
        $bdIsCustomer = !$bdIsStaff && customer_logged_in();
        if (!$bdIsStaff && !$bdIsCustomer) {
            $this->notFound();
        }
        $ref = (string) ($request->query('booking') ?? '');

        /* ---- find it, through the same path each portal's lists use ---- */
        if ($bdIsCustomer) {
            $customerBookings = legacy('customer-bookings')['customerBookings'];   /* own bookings, demo ones included */
            $b = cb_find($customerBookings, $ref);
        } else {
            $b = booking_by_reference($ref);
        }
        if (!$b) {
            $this->notFound();
        }
        $isDemo = !empty($b['isDemo']);
        $isHostel = $b['type'] === 'hostel';

        /* ---- when: each venue day with its hours, or the hostel stay ---- */
        $when = [];
        if ($isHostel) {
            $when[] = ['label' => 'Check-in', 'value' => $b['eventDate']];
            if (!empty($b['endDateIso'])) {
                $when[] = ['label' => 'Check-out', 'value' => date('F j, Y', strtotime($b['endDateIso']))];
            }
            $when[] = ['label' => 'Stay', 'value' => $b['eventName']];
        } elseif (!$isDemo) {
            foreach (booking_slots($b['id']) as $s) {
                $when[] = ['label' => date('D, M j, Y', strtotime($s['slot_date'])),
                           'value' => date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time']))];
            }
        }
        if (!$when) {
            $when[] = ['label' => 'Date', 'value' => $b['eventDate']];
        }

        /* ---- the guests named on a hostel stay ---- */
        $guests = [];
        if ($isHostel && !$isDemo) {
            foreach (booking_occupants($b['id']) as $o) {
                $guests[] = trim(($o['full_name'] ?? $o['name'] ?? '') . (isset($o['bed_label']) && $o['bed_label'] ? ' · ' . $o['bed_label'] : ''));
            }
        }

        /* ---- the GCash receipt on file, if any ---- */
        $gcash = null;
        if (!$isDemo && !empty($b['gcashReceiptId'])) {
            $rec = booking_receipt($b['id']);
            $verdicts = ['accepted' => 'Passed the automatic check', 'manual_review' => 'Sent to staff to check', 'rejected' => 'Did not pass the check'];
            $gcash = [
                'reference' => $rec ? (string) $rec['reference_number'] : (string) ($b['receiptReference'] ?? ''),
                'amount'    => ($rec && $rec['amount_centavos'] !== null) ? '₱' . number_format(((int) $rec['amount_centavos']) / 100, 2) : '—',
                'check'     => $rec ? ($verdicts[$rec['verdict']] ?? $rec['verdict']) : '—',
                'imageId'   => (int) $b['gcashReceiptId'],
            ];
        }

        /* ---- the System Receipt, once one was issued ---- */
        $hasReceipt = false;
        if (!$isDemo && ($pdo = venusep_db()) !== null) {
            $r = $pdo->prepare('SELECT 1 FROM system_receipts WHERE booking_id = :b LIMIT 1');
            $r->execute([':b' => $b['id']]);
            $hasReceipt = $r->fetchColumn() !== false;
        }

        /* ---- the money ---- */
        $money = [
            'roomPrice'       => '₱' . number_format((float) $b['roomPrice'], 2),
            'discountPercent' => (float) $b['discountPercent'],
            'discount'        => (float) $b['discountAmount'] > 0 ? '−₱' . number_format((float) $b['discountAmount'], 2) : '',
            'total'           => '₱' . number_format((float) $b['amountValue'], 2),
            'method'          => $b['method'],
            'paymentStatus'   => $bdIsStaff ? $b['paymentStatusStaff'] : $b['paymentStatus'],
            'payBy'           => in_array($b['paymentCode'], ['await_gcash', 'await_cash', 'overdue', 'await_event', 'locked', 'await_pos'], true)
                                   ? customer_pay_by_label($b['payment']) : '',
            /* post-pay, staff only: the grace end that turns it Overdue (the customer is told the day after) */
            'overdueAfter'    => $bdIsStaff && $b['payment']['policy'] === 'postpay'
                                   && in_array($b['paymentCode'], ['await_gcash', 'await_cash', 'overdue', 'await_event'], true)
                                   ? ($b['payment']['graceEndsLabel'] ?? $b['payment']['payByLabel']) : '',
            'paidOn'          => !empty($b['paidAtIso']) ? date('F j, Y', strtotime($b['paidAtIso'])) : '',
        ];

        return $this->json(200, ['ok' => true, 'details' => [
            'reference'     => $b['bookingId'],
            'type'          => $isHostel ? 'Hostel stay' : 'Venue booking',
            'title'         => $isHostel ? $b['roomName'] : $b['eventName'],
            'status'        => $bdIsStaff ? $b['bookingStatusStaff'] : $b['bookingStatus'],
            'note'          => (string) ($b['expiryNote'] ?? ''),
            'isDemo'        => $isDemo,
            'room'          => $b['roomName'],
            'venue'         => $b['venueName'],
            'attendees'     => $isHostel ? null : (int) ($b['attendees'] ?? 0),
            'bookedOn'      => $b['bookingDate'],
            'customer'      => [
                'name'  => $b['customerName'],
                'email' => (string) (($b['customerEmail'] ?? '') !== '' ? $b['customerEmail'] : ($b['contactEmail'] ?? '')),
                'phone' => (string) ($b['customerPhone'] ?? ''),
                'type'  => !empty($b['isWalkIn']) ? 'Walk-in' : (!empty($b['isUsep']) ? 'USeP affiliated' : 'Non-USeP'),
            ],
            'when'          => $when,
            'guests'        => $guests,
            'money'         => $money,
            'gcash'         => $gcash,
            'hasReceipt'    => $hasReceipt,
            /* history: staff see who did what; a customer sees the same steps */
            'timeline'      => $isDemo ? [] : booking_history_events($b),
        ]]);
    }

    private function json(int $status, array $data)
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function notFound(): never
    {
        throw new HttpResponseException($this->json(404, ['ok' => false, 'message' => 'That booking was not found.']));
    }
}
