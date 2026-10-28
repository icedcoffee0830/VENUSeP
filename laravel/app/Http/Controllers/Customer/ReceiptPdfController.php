<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;

/* =====================================================================
   RECEIPT PDF (was receipt-pdf.php, route root.receipt-pdf) — download a
   booking's VENUSeP System Receipt (#22). GET ?booking=<VB-reference>

   Same rules as document-view:
     staff / admin   any booking's receipt — printing a walk-in's is the job
     customer        only receipts on their OWN bookings
     anyone else     404 (never 403: a 403 would confirm the booking exists)

   The PDF is drawn on request (FPDF, legacy system-receipt.php) from the
   receipt's frozen snapshot, so there is no stored file to protect.
   ===================================================================== */
class ReceiptPdfController
{
    private static function deny()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'system-receipt');

        $isStaff    = session()->has('user_id') && session()->has('account_type')
                        && in_array(session('account_type'), ['admin', 'staff'], true);
        $isCustomer = customer_logged_in();
        if (!$isStaff && !$isCustomer) {
            return self::deny();
        }

        $bookingId = booking_id_from_reference($request->query('booking', ''));
        $pdo = venusep_db();
        if ($bookingId === null || $pdo === null) {
            return self::deny();
        }

        /* THE PERMISSION CHECK, against the SESSION's customer id — never anything
           the request supplied. */
        $stmt = $pdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
        $stmt->execute([':b' => $bookingId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || (!$isStaff && (int) $owner !== (int) session('customer_id'))) {
            return self::deny();
        }

        $receipt = receipt_for_booking($pdo, $bookingId);
        if ($receipt === null) {
            return self::deny();
        }
        $bytes = receipt_pdf_bytes($receipt);

        return response($bytes, 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Length'         => strlen($bytes),
            'Content-Disposition'    => 'attachment; filename="' . $receipt['number'] . '.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Pragma'                 => 'no-cache',
        ]);
    }
}
