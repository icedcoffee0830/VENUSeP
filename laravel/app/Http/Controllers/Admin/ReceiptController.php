<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/* RECEIPT (STAFF VIEW) (was admin/receipt.php) — the VENUSeP System Receipt
   for any booking, reached from Transaction History's "Receipt" button.
   Staff: their own venue's bookings only (2026-10-31), like receipt-pdf and
   document-view; admins: any. */
class ReceiptController extends Controller
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'system-receipt');

        $arRef = (string) ($request->query('booking') ?? '');
        $arBookingId = booking_id_from_reference($arRef);
        $arPdo = venusep_db();
        if ($arBookingId === null || $arPdo === null || !staff_may_handle_booking($arBookingId)) {
            return $this->notFound();
        }
        $arReceipt = receipt_for_booking($arPdo, $arBookingId);
        if ($arReceipt === null) {
            return $this->notFound();
        }

        return response()->view('admin.receipt', ['arRef' => $arRef, 'arView' => receipt_view($arReceipt)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function notFound()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
