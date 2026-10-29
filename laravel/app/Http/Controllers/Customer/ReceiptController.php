<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;

/* ==================================================================
   RECEIPT (was customer/receipt.php) — one booking's VENUSeP System
   Receipt (DB-DECISIONS #22).

   GET   ?booking=<VB-ref>[&confirm=1|&sent=1|&recent=1|&failed=1]
   POST  _token, action=email            "Email me this receipt"

   EMAIL ME goes to the account's OWN address, read from the database, never
   from the request; at most one copy per receipt every 5 minutes. The POST
   redirects back to a state URL (Post/Redirect/Get). A stale token now gets
   Laravel's 419 page (it used to redirect to &failed=1).

   Someone else's booking, or a booking with no receipt yet: 404.
   ================================================================== */
class ReceiptController
{
    private static function notFound()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'receipt-emails');

        $rcRef = (string) $request->query('booking', '');
        $rcBookingId = booking_id_from_reference($rcRef);
        $rcPdo = venusep_db();
        if ($rcBookingId === null || $rcPdo === null) {
            return self::notFound();
        }

        /* Own booking only — checked against the SESSION's customer id. */
        $rcStmt = $rcPdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
        $rcStmt->execute([':b' => $rcBookingId]);
        if ((int) $rcStmt->fetchColumn() !== (int) session('customer_id')) {
            return self::notFound();
        }
        $rcReceipt = receipt_for_booking($rcPdo, $rcBookingId);
        if ($rcReceipt === null) {
            return self::notFound();
        }

        /* The account's own address and name — the only place "Email me" can send. */
        $rcStmt = $rcPdo->prepare('SELECT u.email, c.full_name FROM users u JOIN customers c ON c.user_id = u.id WHERE u.id = :u');
        $rcStmt->execute([':u' => (int) session('user_id')]);
        $rcMe = $rcStmt->fetch() ?: ['email' => '', 'full_name' => ''];

        $rcSelf = route('customer.receipt', ['booking' => $rcRef]);

        if ($request->isMethod('post') && $request->input('action') === 'email') {
            $rcState = 'failed';
            if (receipt_copy_sent_recently($rcPdo, (int) $rcReceipt['id'])) {
                $rcState = 'recent';
            } else {
                try {
                    $rcOutbox = queue_receipt_copy($rcPdo, (int) $rcReceipt['id'], (int) session('user_id'), (string) $rcMe['email'], (string) $rcMe['full_name']);
                    if ($rcOutbox !== null && mail_send($rcPdo, $rcOutbox)) {
                        $rcState = 'sent';
                    }
                } catch (\Throwable $e) {
                    error_log('VENUSeP receipt copy for booking ' . $rcBookingId . ': ' . $e->getMessage());
                }
            }
            return redirect($rcSelf . '&' . $rcState . '=1', 303);
        }

        $rcConfirm = !empty($request->query('confirm'));
        $rcFlash   = !empty($request->query('sent')) ? 'sent' : (!empty($request->query('recent')) ? 'recent' : (!empty($request->query('failed')) ? 'failed' : ''));
        $rcView    = receipt_view($rcReceipt);

        /* (old header('Cache-Control: private, no-store') — the customer middleware sends no-store) */
        return view('customer.receipt', compact('rcRef', 'rcMe', 'rcSelf', 'rcConfirm', 'rcFlash', 'rcView'));
    }
}
