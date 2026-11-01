<?php

namespace App\Http\Controllers\Customer;

use PDO;
use PDOException;

/* BOOKING HISTORY (was customer/booking-history.php) — the session customer's
   own bookings, from legacy/includes/customer-bookings.php (the ONE source,
   shared with refund-request). Search / sort / paginate is page-local JS. */
class BookingHistoryController
{
    public function __invoke()
    {
        extract(legacy('customer-bookings'));

        $bookingHistory = $customerBookings;

        /* Which bookings have a VENUSeP System Receipt (DB-DECISIONS #22): ONE query
           for the whole list, so the "Receipt" button never costs a query per row. */
        $bhReceipts = [];
        $bhIds = array_filter(array_map(function ($b) { return (int) ($b['id'] ?? 0); }, $bookingHistory));
        if ($bhIds && ($bhPdo = venusep_db()) !== null) {
            try {
                $bhStmt = $bhPdo->query('SELECT DISTINCT booking_id FROM system_receipts WHERE booking_id IN (' . implode(',', $bhIds) . ')');
                $bhReceipts = array_flip(array_map('intval', $bhStmt->fetchAll(PDO::FETCH_COLUMN)));
            } catch (PDOException $e) {
                // no Receipt buttons rather than a broken page
            }
        }
        $bookingHistoryVenues = array_values(array_unique(array_column($bookingHistory, 'venue')));
        sort($bookingHistoryVenues, SORT_NATURAL | SORT_FLAG_CASE);

        /* The refund state of this customer's bookings, from the `refunds` table —
           all the browser needs to swap the row's refund ACTION. */
        $bhRefunds = [];
        foreach ($customerBookings as $bhB) {
            if ($bhB['refundStatus'] === null) { continue; }
            $bhRefunds[$bhB['bookingId']] = [
                'status' => $bhB['refundStatus'],
                'note'   => '',
            ];
        }

        return view('customer.booking-history', [
            'customerContact' => $customerContact,
            'bookingHistory' => $bookingHistory,
            'bhReceipts' => $bhReceipts,
            'bookingHistoryVenues' => $bookingHistoryVenues,
            'emptyBookingMessage' => 'No booking history found.',
            'bhRefunds' => $bhRefunds,
        ]);
    }
}
