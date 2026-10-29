<?php

namespace App\Http\Controllers\Customer;

/* TRANSACTION HISTORY (was customer/transaction-history.php) — the customer's
   own ledger: transaction_rows() from legacy bookings.php, the same builder
   the admin ledger uses, scoped to the session customer. */
class TransactionHistoryController
{
    public function __invoke()
    {
        extract(legacy('customer-bookings'));

        $transactionRows = transaction_rows($customerContact['id']);

        return view('customer.transaction-history', [
            'customerContact' => $customerContact,
            'transactionRowsJson' => json_encode($transactionRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }
}
