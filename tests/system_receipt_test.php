<?php
/* =====================================================================
   CLI test for includes/system-receipt.php + the receipt email — the
   number, the PDF text encoding, what a receipt may carry, and the PDF
   itself. No database, no network.
   Run:  php tests/system_receipt_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/receipt-emails.php';

$failures = 0;

function sr_check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        $gotStr = var_export($got, true);
        $wantStr = var_export($want, true);
        echo "FAIL {$name}: got {$gotStr}, want {$wantStr}\n";
    }
}

/* --- the number: derived from id + the year it was issued --- */
sr_check('receipt_number pads to 6 digits', receipt_number(45, '2026-10-02 10:00:00'), 'VSR-2026-000045');
sr_check('receipt_number uses the issue year', receipt_number(7, '2027-01-01 00:05:00'), 'VSR-2027-000007');

/* --- PDF text: Windows-1252 for FPDF, never an empty string --- */
$pdfText = receipt_pdf_text('Peña – ₱4,000.00 −₱10');
sr_check('pdf text keeps the amount, peso becomes PHP', strpos($pdfText, 'PHP 4,000.00') !== false, true);
sr_check('pdf text keeps ñ as one 1252 byte', strpos($pdfText, "\xF1") !== false, true);
sr_check('pdf text turns the minus sign into a hyphen', strpos($pdfText, '-PHP 10') !== false, true);

/* --- the snapshot keeps only what a receipt may show --- */
$booking = [
    'bookingId' => 'VB-2026-142', 'type' => 'venue', 'customerName' => 'Juan Dela Cruz',
    'customerEmail' => 'juan@example.com', 'customerPhone' => '09171234567', 'university_id_no' => '2021-00999',
    'venueName' => 'Bahay Alumni', 'roomName' => 'Bahay Alumni Hall', 'eventName' => 'Org Assembly',
    'eventDateIso' => '2026-10-14', 'endDateIso' => '2026-10-15', 'days' => 2, 'nights' => 0, 'beds' => 0,
    'roomPrice' => 5000.0, 'discountPercent' => 20.0, 'discountAmount' => 1000.0, 'amountValue' => 4000.0,
    'receiptReference' => '1234567890',
];
$payment = ['payment_method' => 'gcash', 'amount' => '4000.00', 'confirmed_at' => '2026-10-02 10:14:00'];
$snap = receipt_snapshot($booking, $payment, '');
$json = json_encode($snap);
sr_check('snapshot leaves out the phone number', strpos($json, '09171234567'), false);
sr_check('snapshot leaves out the university ID', strpos($json, '2021-00999'), false);
sr_check('snapshot keeps the GCash reference', $snap['gcash_reference'], '1234567890');
sr_check('snapshot: cash payments carry no reference',
    receipt_snapshot($booking, array_merge($payment, ['payment_method' => 'cash']), '')['gcash_reference'], '');
sr_check('snapshot: walk-in email used when the account email is empty',
    receipt_snapshot(array_merge($booking, ['customerEmail' => '']), $payment, 'maria@example.com')['customer_email'],
    'maria@example.com');

/* --- one view feeds the page, the email and the PDF --- */
$receipt = receipt_shape(['id' => 45, 'booking_id' => 1, 'issued_at' => '2026-10-02 10:14:00',
    'snapshot_json' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
$view = receipt_view($receipt);
sr_check('view: discount adds a second line', count($view['items']), 2);
sr_check('view: discount line reads 20%', $view['items'][1]['label'], 'USeP discount (20%)');
sr_check('view: amount paid', $view['paid'], '₱4,000.00');
$noDiscount = receipt_shape(['id' => 46, 'booking_id' => 1, 'issued_at' => '2026-10-02 10:14:00',
    'snapshot_json' => json_encode(array_merge($snap, ['discount_amount' => 0, 'discount_percent' => 0]))]);
sr_check('view: no discount, one line', count(receipt_view($noDiscount)['items']), 1);

/* --- the PDF --- */
$pdf = receipt_pdf_bytes($receipt);
sr_check('pdf starts with %PDF-', substr($pdf, 0, 5), '%PDF-');
sr_check('pdf is a real document (> 1000 bytes)', strlen($pdf) > 1000, true);

/* --- the receipt email --- */
$mail = re_receipt_email($receipt, 'Juan', true, 'juan@example.com');
sr_check('email subject names the receipt', strpos($mail['subject'], 'VSR-2026-000045') !== false, true);
sr_check('email embeds the logo inline', strpos($mail['html'], 'cid:venusep-logo') !== false, true);
sr_check('email never carries the phone', strpos($mail['html'] . $mail['text'], '09171234567'), false);
sr_check('email escapes names', strpos(re_receipt_email($receipt, '<b>x</b>', true, 'a@b.co')['html'], '<b>x</b>'), false);

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
