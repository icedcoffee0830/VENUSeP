<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* TRANSACTION HISTORY — admin view (was admin/transaction-history.php).
   transaction_rows() is the same builder the CUSTOMER ledger uses, unscoped
   so staff see every customer. */
class TransactionHistoryController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');
        $transactionRows = transaction_rows();     // every customer — this is the staff view

        return view('admin.transaction-history', [
            'transactionRowsJson' => json_encode($transactionRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }
}
