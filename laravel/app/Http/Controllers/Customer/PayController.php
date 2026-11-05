<?php

namespace App\Http\Controllers\Customer;

use App\Support\Status;
use Illuminate\Http\Request;

/* PAY (was customer/pay.php) — one booking's payment step, reached from
   "Pay now" in Booking History.
     GET ?booking=<VB-ref>[&sent=accepted|manual_review|limit|cash|cash_cancelled]

   Everything comes from the database: the amount is the booking's own
   total_amount, the account is the venue's active gcash_accounts row
   (legacy payment-settings.php). The receipt check is the shared engine
   (legacy gcash-checker.php); its verdict in the browser is a preview —
   payment-submit recomputes it and has the final word.

   Someone else's booking: 404, like receipt and document-view. */
class PayController
{
    public function __invoke(Request $request)
    {
        extract(legacy('customer-bookings', 'payment-settings', 'offices'));

        $payRef = (string) $request->query('booking', '');
        /* Scoped to THIS customer's own bookings: typing another
           customer's reference finds nothing. */
        $payB = cb_find($customerBookings, $payRef);
        if ($payB === null) {
            return self::notFound();
        }

        $payCode     = (string) $payB['paymentCode'];
        $payOpen     = in_array($payCode, Status::PAY_OPEN, true);
        $payIsHostel = $payB['type'] === 'hostel';
        $payAccount  = gcAccountFor($gcAccounts, $payB['venueName']);   /* null = this venue cannot take GCash yet */
        /* The receipt staff are reviewing, if one is in. */
        $payReceipt = (in_array($payCode, ['under_review', 'confirmed'], true)) ? booking_receipt($payB['id']) : null;

        $sent = (string) $request->query('sent', '');
        $paySent = in_array($sent, ['accepted', 'manual_review', 'cash', 'cash_cancelled', 'limit'], true) ? $sent : '';
        /* THE CASH CHOICE (2026-10-28): a customer who chose to pay in cash has
           GCash closed until they cancel that choice (payment-submit). */
        $payCashLocked = $payCode === 'await_cash';

        /* The 5-attempt rule (DB-DECISIONS #6): uploads made, uploads allowed. */
        $payUsed    = (int) ($payB['receiptAttempts'] ?? 0);
        $payAllowed = (int) ($payB['receiptAttemptsAllowed'] ?? 5);
        /* This booking's own earlier uploads, so the page can say "you already
           uploaded this receipt" BEFORE it is sent. */
        $payPrior = $payOpen ? array_map(function ($a) {
            return ['no' => (int) $a['attempt_no'], 'sha' => $a['sha256_hash'], 'ref' => (string) $a['reference_number'],
                    'outcome' => $a['outcome']];
        }, booking_receipt_attempts($payB['id'])) : [];

        /* Where cash is handed over. [SIM] the same placeholders as the booking pages'
           CASH_PAY — swap all three together when the real payment location is decided. */
        $payCash = $payIsHostel
            ? ['where' => 'USeP Hostel front desk', 'address' => '', 'hours' => 'Mon–Sat · 8:00 AM – 5:00 PM']
            : ['where' => 'USeP Cashier — Venue Reservations Window',
               'address' => 'Ground Floor, Administration Building, USeP Obrero Campus, Davao City',
               'hours' => 'Mon–Fri · 8:00 AM – 5:00 PM'];

        $payStateNote = self::stateNote($payB, $payCode);

        /* (old header('Cache-Control: private, no-store') — the customer middleware sends no-store) */
        return live_view('customer.pay', compact(
            'customerContact', 'payB', 'payCode', 'payOpen', 'payIsHostel', 'payAccount',
            'payReceipt', 'paySent', 'payCashLocked', 'payUsed', 'payAllowed', 'payPrior', 'payCash', 'payStateNote'
        ));
    }

    /* Old pay_not_found(): a plain-text 404. */
    private static function notFound()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /* One sentence for every state that is NOT "pay now" (old pay_state_note()). */
    private static function stateNote(array $b, $code)
    {
        switch ($code) {
            case 'locked':      return ['Waiting for approval', 'Staff are reviewing your ID and reservation. Payment opens here once they approve it.'
                                    . (!empty($b['payToUse']) ? ' Then pay before you check in — here by GCash, or in cash at the front desk.' : '')];
            case 'await_event': return ['Payment opens after your event', 'This booking is paid after it happens. Payment opens the day after your event — pay by ' . customer_pay_by_label($b['payment']) . '.'];
            case 'await_pos':   return ['Waiting for your POS', 'Hostel staff are getting your POS (order of payment) from CEDU. Payment opens here as soon as it is recorded.'];
            case 'confirmed':
            case 'paid_cash':   return ['Paid', 'Your payment is confirmed. Nothing more to do.'];
            case 'expired':     return ['This booking expired', $b['expiryNote'] ?: 'It was not paid before its deadline, so the dates were released.'];
            case 'overdue':     $office = office_of_booking($b['id']);
                                return ['Payment overdue. Please contact the office.', $office ? office_contact_line($office) : ''];
            default:            return ['Nothing to pay here', 'This booking is not waiting on a payment.'];
        }
    }
}
