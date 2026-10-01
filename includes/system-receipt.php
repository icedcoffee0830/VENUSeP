<?php
/* =====================================================================
   VENUSeP SYSTEM RECEIPT (DB-DECISIONS #22) — issue, read, and draw.

   One receipt per CONFIRMED payment, issued the moment staff confirm it
   (admin/booking-action.php). It is not the Official Receipt — that comes
   from the University Cashier and is out of scope.

   FROZEN CONTENTS. receipt_issue() copies what the receipt says into
   system_receipts.snapshot_json, and everything that draws a receipt — the
   receipt page, the emails, the PDF — reads that snapshot, never the live
   booking. Editing a booking later can therefore never change a receipt
   someone already holds; it is the same reason discounts are snapshotted.

   ONE PICTURE, THREE DRAWINGS. receipt_view() turns a snapshot into the
   lines a receipt shows (items, totals, who paid, how). The page, the email
   and the PDF each lay those lines out in their own medium, so they cannot
   disagree about a single number.

   THE NUMBER, 'VSR-<year issued>-<id>', is derived and never stored, like
   the booking reference 'VB-<year>-<id>'.
   ===================================================================== */

require_once __DIR__ . '/bookings.php';

const RECEIPT_LOGO = __DIR__ . '/../assets/img/receipt-logo.png';   /* flattened on white: FPDF cannot draw alpha PNGs without gd */

function receipt_number(int $id, string $issuedAt): string
{
    return 'VSR-' . date('Y', strtotime($issuedAt)) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function receipt_money(float $amount): string
{
    return ($amount < 0 ? '−' : '') . '₱' . number_format(abs($amount), 2);
}

/* Issue the receipt for a booking's confirmed payment, or return the one
   already issued for it. Runs inside the staff action's transaction: if it
   throws, the confirmation rolls back with it, so a paid booking never lacks
   its receipt. Returns null only when no confirmed payment exists. */
function receipt_issue(PDO $pdo, int $bookingId, int $actorUserId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id, amount, payment_method, confirmed_at FROM payments
          WHERE booking_id = :b AND payment_record_status = 'confirmed'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':b' => $bookingId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM system_receipts WHERE payment_id = :p');
    $stmt->execute([':p' => $payment['id']]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $rows = bookings_query(['booking_id' => $bookingId]);
    if (!$rows) {
        throw new RuntimeException('Booking ' . $bookingId . ' could not be read to issue its receipt.');
    }
    $stmt = $pdo->prepare('SELECT c.contact_email FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = :b');
    $stmt->execute([':b' => $bookingId]);
    $contactEmail = (string) $stmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO system_receipts (booking_id, payment_id, snapshot_json, issued_at, issued_by_user_id)
         VALUES (:b, :p, :s, NOW(), :u)'
    )->execute([
        ':b' => $bookingId,
        ':p' => $payment['id'],
        ':s' => json_encode(receipt_snapshot($rows[0], $payment, $contactEmail), JSON_UNESCAPED_UNICODE),
        ':u' => $actorUserId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/* What the receipt says, frozen. Deliberately NOT here: phone, university ID
   number, address, document paths — a receipt is sent and printed, so it
   carries nothing a stranger finding it could misuse. */
function receipt_snapshot(array $b, array $payment, string $contactEmail): array
{
    $isGcash = $payment['payment_method'] === 'gcash';
    return [
        'booking_ref'      => $b['bookingId'],
        'type'             => $b['type'],
        'customer_name'    => $b['customerName'],
        'customer_email'   => $b['customerEmail'] !== '' ? $b['customerEmail'] : $contactEmail,
        'venue_name'       => $b['venueName'],
        'room_name'        => $b['roomName'],
        'what'             => $b['eventName'],
        'dates'            => booking_date_label($b),
        'days'             => (int) $b['days'],
        'nights'           => (int) $b['nights'],
        'beds'             => (int) $b['beds'],
        'subtotal'         => (float) $b['roomPrice'],
        'discount_percent' => (float) $b['discountPercent'],
        'discount_amount'  => (float) $b['discountAmount'],
        'total'            => (float) $b['amountValue'],
        'amount_paid'      => (float) $payment['amount'],
        'method'           => $isGcash ? 'GCash' : 'Cash',
        'gcash_reference'  => $isGcash ? (string) $b['receiptReference'] : '',
        'confirmed_at'     => (string) $payment['confirmed_at'],
    ];
}

function receipt_load(PDO $pdo, int $receiptId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM system_receipts WHERE id = :id');
    $stmt->execute([':id' => $receiptId]);
    $row = $stmt->fetch();
    return $row ? receipt_shape($row) : null;
}

/* The newest receipt on a booking (a second one exists only after a second
   confirmed payment). */
function receipt_for_booking(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM system_receipts WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
    $stmt->execute([':b' => $bookingId]);
    $row = $stmt->fetch();
    return $row ? receipt_shape($row) : null;
}

function receipt_shape(array $row): array
{
    $row['snapshot'] = json_decode((string) $row['snapshot_json'], true) ?: [];
    $row['number']   = receipt_number((int) $row['id'], (string) $row['issued_at']);
    return $row;
}

/* The receipt as lines, shared by the page, the emails and the PDF.
   Money is formatted with ₱; the PDF swaps it for 'PHP ' (receipt_pdf_text). */
function receipt_view(array $receipt): array
{
    $s = $receipt['snapshot'];
    $isHostel = ($s['type'] ?? '') === 'hostel';
    $span = $isHostel
        ? $s['beds'] . ' bed' . ($s['beds'] == 1 ? '' : 's') . ' · ' . $s['nights'] . ' night' . ($s['nights'] == 1 ? '' : 's')
        : $s['days'] . ' day' . ($s['days'] == 1 ? '' : 's');

    $items = [[
        'label'  => $isHostel ? 'Hostel stay: ' . $span : 'Venue booking: ' . $s['what'],
        'sub'    => $s['room_name'] . ($isHostel ? ', ' . $s['venue_name'] : ' · ' . $span),
        'amount' => receipt_money((float) $s['subtotal']),
    ]];
    if ((float) $s['discount_amount'] > 0) {
        $items[] = [
            'label'  => 'USeP discount (' . rtrim(rtrim(number_format((float) $s['discount_percent'], 2), '0'), '.') . '%)',
            'sub'    => 'USeP ID verified by staff',
            'amount' => receipt_money(-(float) $s['discount_amount']),
        ];
    }

    $paidBy = [['Paid by', $s['method']]];
    if ($s['gcash_reference'] !== '') {
        $paidBy[] = ['GCash reference', $s['gcash_reference']];
    }
    $confirmed = $s['confirmed_at'] !== '' ? date('M j, Y, g:i A', strtotime($s['confirmed_at'])) : '';
    if ($confirmed !== '') {
        $paidBy[] = ['Confirmed', $confirmed];
    }

    return [
        'number'      => $receipt['number'],
        'issued'      => date('M j, Y, g:i A', strtotime($receipt['issued_at'])),
        'from'        => array_values(array_filter([$s['customer_name'], $s['customer_email']])),
        'booking'     => [$s['booking_ref'], $s['room_name'] . ', ' . $s['venue_name'], $s['dates'] . ($isHostel ? '' : ' · ' . $span)],
        'items'       => $items,
        'total'       => receipt_money((float) $s['total']),
        'paid'        => receipt_money((float) $s['amount_paid']),
        'paid_by'     => $paidBy,
        'booking_ref' => $s['booking_ref'],
        'confirmed'   => $confirmed,
        'footer'      => 'VENUSeP System Receipt, issued automatically when staff confirmed the payment. '
                       . 'University of Southeastern Philippines venue and hostel booking.',
    ];
}

/* FPDF's built-in Helvetica speaks Windows-1252, not UTF-8. iconv(...//TRANSLIT)
   returns an EMPTY string on this PHP the moment it meets ₱, so the conversion
   goes through mbstring, with the two characters 1252 lacks swapped first. */
function receipt_pdf_text($s): string
{
    $s = str_replace(['₱', '−'], ['PHP ', '-'], (string) $s);
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

/* The A4 PDF, laid out like the approved mockup (_preview/email/receipt-pdf.html). */
function receipt_pdf_bytes(array $receipt): string
{
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', __DIR__ . '/vendor/fpdf-1.8.6/font/');
    }
    require_once __DIR__ . '/vendor/fpdf-1.8.6/fpdf.php';

    $v = receipt_view($receipt);
    $t = 'receipt_pdf_text';
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle($t('VENUSeP System Receipt ' . $v['number']));
    $pdf->SetAuthor('VENUSeP');
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(20, 20, 20);
    $pdf->AddPage();

    $ink = [29, 18, 20];
    $muted = [110, 106, 100];
    $line = [239, 224, 219];
    $pdf->SetFillColor(161, 22, 38);                          // the crimson rule
    $pdf->Rect(0, 0, 210, 2.5, 'F');

    $pdf->Image(RECEIPT_LOGO, 20, 15, 42);
    $pdf->SetXY(110, 15);
    $pdf->SetTextColor(...$ink);
    $pdf->SetFont('Helvetica', 'B', 17);
    $pdf->Cell(80, 8, $t('System Receipt'), 0, 2, 'R');
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(...$muted);
    $pdf->Cell(80, 5.5, $t('No. ' . $v['number']), 0, 2, 'R');
    $pdf->Cell(80, 5.5, $t('Issued ' . $v['issued']), 0, 2, 'R');

    $pdf->SetDrawColor(...$line);
    $pdf->Line(20, 40, 190, 40);

    $col = function (float $x, string $heading, array $lines) use ($pdf, $t, $ink, $muted) {
        $pdf->SetXY($x, 45);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor(...$muted);
        $pdf->Cell(80, 5, $t(strtoupper($heading)), 0, 2);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...$ink);
        foreach ($lines as $l) {
            $pdf->SetX($x);
            $pdf->MultiCell(80, 5.2, $t($l), 0, 'L');
        }
        return $pdf->GetY();
    };
    $y = max($col(20, 'Received from', $v['from']), $col(110, 'Booking', $v['booking'])) + 8;

    /* the items table */
    $pdf->SetFillColor(250, 249, 247);
    $pdf->SetXY(20, $y);
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->SetTextColor(...$muted);
    $pdf->Cell(120, 8, $t('  DESCRIPTION'), 'TB', 0, 'L', true);
    $pdf->Cell(50, 8, $t('AMOUNT  '), 'TB', 1, 'R', true);
    foreach ($v['items'] as $item) {
        $top = $pdf->GetY() + 2.5;
        $pdf->SetXY(23, $top);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...$ink);
        $pdf->MultiCell(110, 5, $t($item['label']), 0, 'L');
        $pdf->SetX(23);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(...$muted);
        $pdf->MultiCell(110, 4.5, $t($item['sub']), 0, 'L');
        $bottom = $pdf->GetY() + 2.5;
        $pdf->SetXY(140, $top);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->SetTextColor(...$ink);
        $pdf->Cell(47, 5, $t($item['amount']), 0, 0, 'R');
        $pdf->Line(20, $bottom, 190, $bottom);
        $pdf->SetY($bottom);
    }

    /* totals, right-aligned */
    $y = $pdf->GetY() + 5;
    $pdf->SetXY(115, $y);
    $pdf->Cell(40, 6, $t('Total due'), 0, 0);
    $pdf->Cell(32, 6, $t($v['total']), 0, 1, 'R');
    $pdf->SetDrawColor(...$ink);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(112, $y + 8, 190, $y + 8);
    $pdf->SetLineWidth(0.2);
    $pdf->SetXY(115, $y + 10);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(40, 7, $t('Amount paid'), 0, 0);
    $pdf->Cell(32, 7, $t($v['paid']), 0, 1, 'R');

    /* how it was paid, in a box */
    $y = $pdf->GetY() + 8;
    $h = 6 + count($v['paid_by']) * 6;
    $pdf->SetDrawColor(...$line);
    $pdf->Rect(20, $y, 170, $h);
    $pdf->SetFont('Helvetica', '', 9.5);
    foreach ($v['paid_by'] as $i => [$k, $val]) {
        $pdf->SetXY(25, $y + 3 + $i * 6);
        $pdf->SetTextColor(...$muted);
        $pdf->Cell(40, 6, $t($k), 0, 0);
        $pdf->SetTextColor(...$ink);
        $pdf->Cell(120, 6, $t($val), 0, 0);
    }

    /* footer */
    $pdf->Line(20, 272, 190, 272);
    $pdf->SetXY(20, 274);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(...$muted);
    $pdf->MultiCell(145, 4, $t($v['footer']), 0, 'L');
    $pdf->SetXY(165, 274);
    $pdf->Cell(25, 4, $t('Page 1 of 1'), 0, 0, 'R');

    return $pdf->Output('S');
}
