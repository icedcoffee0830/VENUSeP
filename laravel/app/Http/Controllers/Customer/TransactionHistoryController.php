<?php

namespace App\Http\Controllers\Customer;

use PDO;
use PDOException;

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
            /* The filters match a row's label exactly, so they offer the labels rows
               carry: the lookup tables' customer_label (+ "Expired", bookings.php's
               word for a request released before anyone approved it). */
            'paymentLabels' => self::labels('payment_statuses'),
            'bookingLabels' => array_values(array_unique(array_merge(self::labels('reservation_statuses'), ['Expired']))),
        ]);
    }

    /** @return string[] distinct customer labels in the table's sort order */
    private static function labels(string $table): array
    {
        $pdo = venusep_db();
        if ($pdo === null) {
            return [];
        }
        try {
            $labels = $pdo->query("SELECT customer_label FROM {$table} ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];   // a filter with only "All" rather than a broken page
        }
        return array_values(array_unique($labels));
    }
}
