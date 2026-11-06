<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* TRANSACTION HISTORY — admin view (was admin/transaction-history.php).
   transaction_rows() is the same builder the CUSTOMER ledger uses, for every
   customer of the bookings this person handles (bookings_all()). */
class TransactionHistoryController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');
        $transactionRows = transaction_rows();     // every customer — this is the staff view

        return view('admin.transaction-history', [
            'transactionRowsJson' => json_encode($transactionRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?: '[]',
            /* The filters match a row's label exactly, so they offer the labels the rows carry. */
            'paymentLabels' => self::labels($transactionRows, 'paymentStatus'),
            'bookingLabels' => self::labels($transactionRows, 'bookingStatus'),
        ]);
    }

    private static function labels(array $rows, string $field): array
    {
        $labels = array_values(array_unique(array_column($rows, $field)));
        sort($labels);
        return $labels;
    }
}
