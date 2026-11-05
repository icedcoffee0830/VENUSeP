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
        extract(legacy('customer-bookings', 'offices'));

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
                sql_failed($e);
                // no Receipt buttons rather than a broken page
            }
        }
        $bookingHistoryVenues = array_values(array_unique(array_column($bookingHistory, 'venue')));
        sort($bookingHistoryVenues, SORT_NATURAL | SORT_FLAG_CASE);

        /* An overdue booking is not paid here unless staff extend its deadline (Aron
           2026-11-05): the row says to contact its office instead. booking id => contact line. */
        $bhOverdue = [];
        foreach ($customerBookings as $bhB) {
            if ($bhB['paymentCode'] === 'overdue' && ($bhOffice = office_of_booking($bhB['id'])) !== null) {
                $bhOverdue[$bhB['id']] = office_contact_line($bhOffice);
            }
        }

        return live_view('customer.booking-history', [
            'customerContact' => $customerContact,
            'bookingHistory' => $bookingHistory,
            'bhReceipts' => $bhReceipts,
            'bookingHistoryVenues' => $bookingHistoryVenues,
            'emptyBookingMessage' => 'No booking history found.',
            'bhOverdue' => $bhOverdue,
        ]);
    }
}
