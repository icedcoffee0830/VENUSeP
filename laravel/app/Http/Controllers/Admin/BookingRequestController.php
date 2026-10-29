<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* THE REQUEST DETAIL (was admin/booking-request.php) — built from the same
   `bookings` rows the queue and the customer's own history read. Every
   request is a row keyed by its real reference; the page script picks ?id=. */
class BookingRequestController extends Controller
{
    public function __invoke()
    {
        /* pricing.php and gcash-checker.php print a <script> block when loaded;
           the old page included them in place, so their output goes to the view. */
        ob_start();
        legacy('pricing');
        $pricingJs = ob_get_clean();
        ob_start();
        legacy('gcash-checker');
        $gcashCheckerJs = ob_get_clean();
        $vars = legacy('bookings');
        /* payment_policy_js() reads $GLOBALS['REFUNDS_ENABLED']; legacy() keeps
           the old top-level variables in its own scope, not in $GLOBALS. */
        $GLOBALS['REFUNDS_ENABLED'] = $vars['REFUNDS_ENABLED'] ?? false;

        $brqData = [];
        foreach (bookings_all() as $b) {
            $brqData[$b['bookingId']] = $this->row($b);
        }

        return view('admin.booking-request', compact('brqData', 'pricingJs', 'gcashCheckerJs'));
    }

    /* Which ID document was attached, and whether staff have accepted it. The
       discount depends on this: a USeP ID that staff VERIFIED earns it, a claim
       alone never does (DB-DECISIONS #3). */
    private function idLabel(array $b): string
    {
        if ($b['isWalkIn'])  return 'Presented at the counter';
        if (!$b['isUsep'])   return 'Government-issued ID';
        return $b['usepVerified'] ? 'USeP ID (verified)' : 'USeP ID (submitted)';
    }

    private function idStatus(array $b): string
    {
        if ($b['reservationCode'] === 'pending')  return 'pending';
        if ($b['reservationCode'] === 'rejected') return 'rejected';
        return 'approved';
    }

    /* One booking -> the shape the page script reads. */
    private function row(array $b): array
    {
        $isHostel = $b['type'] === 'hostel';

        /* The per-day schedule. Days live in venue_booking_slots, so a booking
           that BOOKED AROUND a closed day simply has a gap here. */
        $sched = [];
        foreach (booking_slots($b['id']) as $s) {
            $sched[] = [
                'd' => date('D, M j', strtotime($s['slot_date'])),
                't' => date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time'])),
            ];
        }

        $occ = [];
        if ($isHostel) {
            foreach (booking_occupants($b['id']) as $o) {
                $occ[] = ['n' => $o['full_name'], 'g' => $o['gender'] === 'female' ? 'F' : ($o['gender'] === 'male' ? 'M' : 'O')];
            }
        }

        /* The refund claim, when there is one. */
        $refund = null;
        $rfStmt = venusep_db()->prepare(
            'SELECT refund_status, reason_category, reason, amount_requested, refund_to_number,
                    official_receipt_pending, correction_attempts, notes, requested_at, resubmitted_at
               FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1'
        );
        $rfStmt->execute([':b' => $b['id']]);
        if ($rf = $rfStmt->fetch()) {
            $stageMap = [
                'requested'               => 'Under verification',
                'under_review'            => 'Under verification',
                'returned_for_correction' => 'Returned for correction',
                'approved'                => $rf['official_receipt_pending'] ? 'Awaiting Official Receipt' : 'Approved · payout pending',
                'rejected'                => 'Denied',
                'completed'               => 'Refunded',
                'withdrawn'               => 'Withdrawn by the customer',
            ];
            $refund = [
                'stage'     => $stageMap[$rf['refund_status']] ?? $rf['refund_status'],
                'orPending' => (bool) $rf['official_receipt_pending'],
                'reason'    => str_replace('_', ' ', $rf['reason_category']),
                'details'   => (string) $rf['reason'],
                'refundTo'  => (string) $rf['refund_to_number'],
                'filed'     => date('M j, Y', strtotime($rf['requested_at'])),
                'docs'      => $rf['refund_status'] === 'returned_for_correction'
                                ? 'Returned to the customer: ' . ($rf['notes'] ?: 'correction requested')
                                : 'VENUSeP System Receipt + proof of payment submitted · '
                                  . ($rf['official_receipt_pending'] ? 'Official Receipt NOT yet provided' : 'Official Receipt provided'),
            ];
        }

        $rec = booking_receipt($b['id']);
        $receipt = $rec ? [
            'ref'    => $rec['reference_number'],
            'amount' => '₱' . number_format(((int) $rec['amount_centavos']) / 100, 2),
            'date'   => $rec['receipt_datetime'] ? date('M j, Y · g:i A', strtotime($rec['receipt_datetime'])) : '—',
            'to'     => (string) $rec['receiver_number'],
            'conf'   => $rec['ocr_confidence'] !== null ? round((float) $rec['ocr_confidence']) . '%' : '—',
            'flags'  => $rec['flags'],
        ] : null;

        $row = [
            'id'        => $b['bookingId'],
            'kind'      => $isHostel ? 'hostel' : 'venue',
            'name'      => $b['customerName'],
            'type'      => $b['isWalkIn'] ? 'Walk-in' : ($b['isUsep'] ? 'USeP affiliated' : 'Non-USeP'),
            /* account email; for a walk-in, the address they gave at the counter (#22) */
            'email'     => $b['customerEmail'] !== '' ? $b['customerEmail'] : (($b['contactEmail'] ?? '') !== '' ? $b['contactEmail'] : '—'),
            'phone'     => $b['customerPhone'],
            'event'     => $b['eventName'],
            'room'      => $b['roomName'],
            'venue'     => $b['venueName'],
            'capacity'  => $b['capacity'],
            'attendees' => $b['attendees'],
            'total'     => $b['amount'],
            'payBy'     => $b['payment']['payByLabel'],
            'method'    => strtolower($b['method']),
            'submitted' => date('M j, Y · g:i A', strtotime($b['submittedAt'])),
            'res'       => $b['reservationCode'],
            'pay'       => $b['paymentCode'],
            'neverApproved' => $b['neverApproved'],   /* expired before anyone approved it (2026-10-27) */
            'idStatus'  => $this->idStatus($b),
            'idLabel'   => $this->idLabel($b),
            'discountPercent' => $b['discountPercent'],
            'receipt'   => $receipt,
            /* Ids only — the bytes come from document-view, which runs its own permission check. */
            'docs'      => booking_document_ids($b['id']),
            'receiptId' => $rec ? (int) $rec['id'] : null,
            /* Every GCash upload, rejected ones included, and the 5-attempt rule (DB-DECISIONS #6). */
            'attempts'  => array_map(function ($a) {
                return [
                    'id'      => (int) $a['id'],
                    'no'      => (int) $a['attempt_no'],
                    'at'      => date('M j · g:i A', strtotime($a['created_at'])),
                    'verdict' => $a['verdict'],
                    'ref'     => (string) $a['reference_number'],
                    'amount'  => $a['amount_centavos'] !== null ? '₱' . number_format(((int) $a['amount_centavos']) / 100, 2) : '—',
                    'to'      => (string) $a['receiver_number'],
                    'flags'   => $a['flags'],
                    'staffNote' => in_array('rejected_by_staff', $a['flags'], true) ? (string) $a['override_note'] : '',
                ];
            }, booking_receipt_attempts($b['id'])),
            'attemptsAllowed' => $b['receiptAttemptsAllowed'],
            'refund'    => $refund,
            'refundTo'  => $refund ? $refund['refundTo'] : null,
            'tl'        => booking_history_events($b),
        ];

        if ($isHostel) {
            $row += [
                'crType'    => $b['crType'] === 'private' ? 'Private CR' : 'Communal CR',
                'checkIn'   => date('M j, Y', strtotime($b['eventDateIso'])),
                'checkOut'  => date('M j, Y', strtotime($b['endDateIso'])),
                'nights'    => $b['nights'],
                'rate'      => '₱' . number_format($b['roomPrice'] / max(1, $b['beds'] * max(1, $b['nights']))),
                'occupants' => $occ,
                'pos'       => $b['pos'] !== '' ? $b['pos'] : null,
                'or'        => $b['officialReceipt'] !== '' ? $b['officialReceipt'] : null,
                'checkedIn' => $b['checkedIn'],
                /* pay before using the bed (2026-10-27, DB-DECISIONS #26) */
                'payToUse'  => $b['payToUse'],
                'methodChosen' => $b['methodCode'] !== null,
            ];
        } else {
            $row += [
                'dates'   => $b['eventDateIso'] === $b['endDateIso']
                                ? date('M j, Y', strtotime($b['eventDateIso']))
                                : date('M j', strtotime($b['eventDateIso'])) . ' – ' . date('M j, Y', strtotime($b['endDateIso'])),
                'startIso'=> $b['eventDateIso'],
                'endIso'  => $b['endDateIso'],
                'feeDay'  => '₱' . number_format($b['days'] > 0 ? $b['roomPrice'] / $b['days'] : $b['roomPrice']),
                'days'    => $b['days'],
                'sched'   => $sched,
            ];
        }

        return $row;
    }
}
