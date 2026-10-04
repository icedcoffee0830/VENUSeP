<?php
/* =====================================================================
   THE THREE EMAILS (DB-DECISIONS #22) — rendered here, queued in the
   outbox, sent by includes/mailer.php.

     walkin_booking   a walk-in's confirmation, right after the counter
                      booking is made (only if they gave an email)
     walkin_receipt   a walk-in's System Receipt, when staff confirm the
                      payment (only if they gave an email)
     receipt_copy     an account holder pressed "Email me this receipt"

   Account holders get nothing automatic. Every queue_* function returns the
   outbox id, or null when there is nobody to send to; none of them sends.

   Layouts follow the approved "Paper slip" mockups in _preview/email/.
   Email HTML is tables + inline styles in Arial: mail apps strip <style>,
   web fonts and flexbox. The logo travels inside the email (cid:), because
   a link to this server would not load for anyone outside it.
   ===================================================================== */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/system-receipt.php';

/* The walk-in contact for a booking: only for a customer with NO account who
   gave an email at the counter. Account holders are never auto-emailed. */
function receipt_walkin_contact(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT c.full_name, c.contact_email FROM bookings b JOIN customers c ON c.id = b.customer_id
          WHERE b.id = :b AND c.user_id IS NULL'
    );
    $stmt->execute([':b' => $bookingId]);
    $row = $stmt->fetch();
    if (!$row || trim((string) $row['contact_email']) === '') {
        return null;
    }
    return ['name' => (string) $row['full_name'], 'email' => (string) $row['contact_email']];
}

function re_first_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    return $parts[0] !== '' ? $parts[0] : 'there';
}

/* Every email is automatic, and once the demo machine sends through a real
   Gmail account a reply would land in an inbox nobody answers — so every
   email says so, in the HTML and in the plain-text version alike. */
const RE_NO_REPLY = 'This is an automated email from VENUSeP. Please do not reply to it. For questions about your booking, contact the venue office.';

/* ---- the shared frame: crimson rule, logo, title, greeting, intro, body, footer ---- */
function re_frame(string $preheader, string $title, string $firstName, string $introHtml, string $bodyHtml, string $footerHtml): string
{
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">'
        . '<title>' . bh_e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f1ef;">'
        . '<div style="display:none;max-height:0;overflow:hidden;">' . bh_e($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f1ef;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e9e1dd;">'
        . '<tr><td style="height:4px;background:#a11626;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 32px 6px 32px;"><img src="cid:venusep-logo" width="132" alt="VENUSeP" style="display:block;width:132px;height:auto;border:0;color:#a11626;font:bold 22px Arial,Helvetica,sans-serif;"></td></tr>'
        . '<tr><td style="padding:18px 32px 0 32px;' . $font . '">'
        . '<h1 style="margin:0;font-size:24px;line-height:1.25;font-weight:bold;color:#1d1214;">' . bh_e($title) . '</h1>'
        . '<p style="margin:14px 0 0 0;font-size:15px;line-height:1.6;color:#1d1214;font-weight:bold;">Good day, ' . bh_e($firstName) . '!</p>'
        . '<p style="margin:4px 0 0 0;font-size:15px;line-height:1.6;color:#4a4440;">' . $introHtml . '</p></td></tr>'
        . $bodyHtml
        . '<tr><td style="padding:16px 32px 22px 32px;border-top:1px solid #efe0db;' . $font . 'font-size:12px;line-height:1.6;color:#6e6a64;">'
        . '<strong style="color:#4a4440;">' . bh_e(RE_NO_REPLY) . '</strong><br>'
        . $footerHtml . '<br>Sent by VENUSeP, the University of Southeastern Philippines venue and hostel booking system.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/* Label/value rows with hairlines between them. $rows: [[label, valueHtml], ...] */
function re_rows(array $rows, bool $topRule = true): string
{
    $html = '';
    foreach ($rows as $i => [$label, $valueHtml]) {
        $rule = ($i > 0) ? 'border-top:1px solid #f3ebe8;' : '';
        $html .= '<tr><td width="38%" style="padding:9px 0;color:#6e6a64;vertical-align:top;' . $rule . '">' . bh_e($label) . '</td>'
               . '<td style="padding:9px 0;vertical-align:top;' . $rule . '">' . $valueHtml . '</td></tr>';
    }
    return '<tr><td style="padding:' . ($topRule ? '20px' : '6px') . ' 32px 0 32px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1d1214;'
        . ($topRule ? 'border-top:1px solid #efe0db;' : '') . '">' . $html . '</table></td></tr>';
}

/* The tinted amounts box: optional detail lines, then one bold total line. */
function re_amounts(array $lines, string $totalLabel, string $total): string
{
    $html = '';
    $last = count($lines) - 1;
    foreach ($lines as $i => [$label, $amount]) {
        $pad = ($i === 0 ? '12px' : '4px') . ' 16px ' . ($i === $last ? '12px' : '4px') . ' 16px';
        $html .= '<tr><td style="padding:' . $pad . ';color:#4a4440;">' . bh_e($label) . '</td>'
               . '<td align="right" style="padding:' . $pad . ';white-space:nowrap;">' . bh_e($amount) . '</td></tr>';
    }
    $rule = $lines ? 'border-top:1px solid #efe0db;' : '';
    $html .= '<tr><td style="padding:12px 16px;' . $rule . 'font-weight:bold;font-size:15px;">' . bh_e($totalLabel) . '</td>'
           . '<td align="right" style="padding:12px 16px;' . $rule . 'font-weight:bold;font-size:17px;white-space:nowrap;">' . bh_e($total) . '</td></tr>';
    return '<tr><td style="padding:14px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1d1214;background:#faf9f7;border:1px solid #efe0db;">'
        . $html . '</table></td></tr>';
}

function re_paragraph(string $html, string $pad = '20px 32px 0 32px', string $size = '14px', string $color = '#4a4440'): string
{
    return '<tr><td style="padding:' . $pad . ';font-family:Arial,Helvetica,sans-serif;font-size:' . $size . ';line-height:1.6;color:' . $color . ';">' . $html . '</td></tr>';
}

/* ---------------------------------------------------------------------
   1. walkin_booking — the counter booking, confirmed
   --------------------------------------------------------------------- */
function queue_walkin_booking(PDO $pdo, int $bookingId, int $actorUserId): ?int
{
    $who = receipt_walkin_contact($pdo, $bookingId);
    $rows = $who ? bookings_query(['booking_id' => $bookingId]) : [];
    if (!$who || !$rows) {
        return null;
    }
    $b = $rows[0];
    $due = receipt_money((float) $b['amountValue']);

    /* the day(s) and hours actually held */
    $days = [];
    foreach (booking_slots($bookingId) as $s) {
        if ($s['released_at'] !== null) {
            continue;
        }
        $days[] = date('M j, Y', strtotime($s['slot_date'])) . ' · <span style="white-space:nowrap;">'
                . date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time'])) . '</span>';
    }
    $when = $days ? implode('<br>', $days) : bh_e(booking_date_label($b));

    $details = re_rows([
        ['Room', bh_e($b['roomName']) . '<br><span style="color:#6e6a64;font-size:13px;">' . bh_e($b['venueName']) . '</span>'],
        ['Event', bh_e($b['eventName'])],
        [count($days) > 1 ? 'Dates' : 'Date', $when],
    ]);
    $lines = (float) $b['discountAmount'] > 0
        ? [['Subtotal', receipt_money((float) $b['roomPrice'])],
           ['USeP discount (' . (float) $b['discountPercent'] . '%)', receipt_money(-(float) $b['discountAmount'])]]
        : [];
    $amounts = re_amounts($lines, 'Amount due', $due);

    $ref = '<strong style="color:#1d1214;">' . bh_e($b['bookingId']) . '</strong>';
    if ($b['payment']['policy'] === 'postpay') {
        $heading = 'Pay after your event';
        $how = 'Payment opens ' . bh_e($b['payment']['opensLabel']) . ' and is due by ' . bh_e($b['payment']['payByLabel'])
             . '. Pay <strong style="color:#1d1214;">' . bh_e($due) . '</strong> in cash at the counter and bring this reference: ' . $ref . '.';
    } else {
        $heading = 'Pay by ' . $b['payment']['payByLabel'];
        $how = 'Pay <strong style="color:#1d1214;">' . bh_e($due) . '</strong> in cash at the counter by '
             . bh_e($b['payment']['payByLabel']) . '. Bring this reference: ' . $ref . '.';
    }
    $how .= ' We&rsquo;ll email your VENUSeP System Receipt once staff record the payment.';
    $next = '<tr><td style="padding:20px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;">'
          . '<h2 style="margin:0;font-size:16px;line-height:1.3;color:#1d1214;">' . bh_e($heading) . '</h2>'
          . '<p style="margin:6px 0 0 0;font-size:14px;line-height:1.6;color:#4a4440;">' . $how . '</p></td></tr>';

    $html = re_frame(
        $b['roomName'] . ', ' . booking_date_label($b) . '. Amount due ' . $due . '.',
        'Your booking is confirmed',
        re_first_name($who['name']),
        'Thank you for booking with VENUSeP. Here are the details of the booking we made for you at the counter. Your reference is ' . $ref . '.',
        $details . $amounts . $next
            . re_paragraph('Keep this email. You booked without an account, so this email and the counter are where your booking details live.', '22px 32px 28px 32px', '13px', '#6e6a64'),
        'You&rsquo;re getting this because you gave ' . bh_e($who['email']) . ' at the VENUSeP counter. Not you? Ignore this email.'
    );
    $text = "Your booking is confirmed\n\n"
          . 'Good day, ' . re_first_name($who['name']) . "!\nThank you for booking with VENUSeP. Here are the details of the booking we made for you at the counter.\n\n"
          . 'Reference: ' . $b['bookingId'] . "\nRoom: " . $b['roomName'] . ', ' . $b['venueName']
          . "\nEvent: " . $b['eventName'] . "\nDate: " . strip_tags(str_replace('<br>', '; ', $when)) . "\nAmount due: " . $due . "\n\n"
          . strip_tags(html_entity_decode($heading . '. ' . $how, ENT_QUOTES, 'UTF-8')) . "\n\nKeep this email.\n\n" . RE_NO_REPLY;

    return mail_enqueue($pdo, [
        'kind' => 'walkin_booking', 'booking_id' => $bookingId, 'requested_by_user_id' => $actorUserId ?: null,
        'to_email' => $who['email'], 'subject' => 'Your VENUSeP booking ' . $b['bookingId'],
        'body_html' => $html, 'body_text' => $text,
    ]);
}

/* ---------------------------------------------------------------------
   2 + 3. The receipt email — walk-in (automatic) or account copy (asked for)
   --------------------------------------------------------------------- */
function re_receipt_email(array $receipt, string $firstName, bool $isCopy, string $toEmail): array
{
    $v = receipt_view($receipt);
    $s = $receipt['snapshot'];
    $ref = '<strong style="color:#1d1214;">' . bh_e($v['booking_ref']) . '</strong>';

    $numberRow = '<tr><td style="padding:22px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="font-family:Arial,Helvetica,sans-serif;border-top:1px solid #efe0db;border-bottom:1px solid #efe0db;"><tr>'
        . '<td style="padding:12px 0;font-size:13px;color:#6e6a64;">System Receipt</td>'
        . '<td align="right" style="padding:12px 0;font-size:13px;color:#1d1214;font-weight:bold;">' . bh_e($v['number']) . '</td></tr></table></td></tr>';

    $paidBy = $s['method'] === 'GCash'
        ? 'GCash' . ($s['gcash_reference'] !== '' ? ' · reference ' . bh_e($s['gcash_reference']) : '')
        : 'Cash at the counter';
    $details = re_rows(array_filter([
        ['Booking', bh_e($v['booking_ref'])],
        ['Room', bh_e($s['room_name']) . '<br><span style="color:#6e6a64;font-size:13px;">' . bh_e($s['venue_name']) . '</span>'],
        [$s['type'] === 'hostel' ? 'Stay' : 'Event', bh_e($s['what'])],
        ['Dates', bh_e($s['dates'])],
        ['Paid by', $paidBy],
        $v['confirmed'] !== '' ? ['Confirmed', bh_e($v['confirmed'])] : null,
    ]), false);

    $lines = [];
    foreach ($v['items'] as $i => $item) {
        if (count($v['items']) > 1) {
            $lines[] = [$i === 0 ? 'Subtotal' : $item['label'], $item['amount']];
        }
    }
    $amounts = re_amounts($lines, 'Amount paid', $v['paid']);

    $keep = $isCopy
        ? '<strong style="color:#1d1214;">Keep this receipt.</strong> You can download it again anytime from My Bookings.'
        : '<strong style="color:#1d1214;">Keep this receipt.</strong> It&rsquo;s your record that the booking is paid. You booked without an account, so ask at the counter if you need another copy.';

    $title = $isCopy ? 'Your VENUSeP System Receipt' : 'Payment received';
    $intro = $isCopy
        ? 'Here is the copy of your VENUSeP System Receipt for booking ' . $ref . ' that you asked for from My Bookings.'
        : 'Thank you for your payment. Here is your VENUSeP System Receipt for booking ' . $ref . '.';
    $footer = $isCopy
        ? 'Sent because you asked for it from your VENUSeP account (' . bh_e($toEmail) . ').'
        : 'You&rsquo;re getting this because you gave ' . bh_e($toEmail) . ' at the VENUSeP counter.';

    /* The receipt itself is the PDF — say so plainly, before the summary, so
       nobody mistakes the email body for the thing to keep. */
    $attached = '<tr><td style="padding:18px 32px 0 32px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="font-family:Arial,Helvetica,sans-serif;background:#fcf6f4;border:1px solid #efe0db;"><tr>'
        . '<td width="52" style="padding:12px 0 12px 14px;vertical-align:middle;">'
        . '<span style="display:inline-block;padding:4px 7px;border-radius:4px;background:#a11626;color:#ffffff;font-size:11px;font-weight:bold;letter-spacing:.04em;">PDF</span></td>'
        . '<td style="padding:12px 14px 12px 0;font-size:14px;line-height:1.5;color:#1d1214;">'
        . '<strong>Your receipt is attached: ' . bh_e($v['number']) . '.pdf</strong><br>'
        . '<span style="color:#4a4440;font-size:13px;">Download it to keep or print. The summary below is for quick reading.</span></td></tr></table></td></tr>';

    $html = re_frame(
        'Payment of ' . $v['paid'] . ' for ' . $s['room_name'] . ', ' . $s['dates'] . '. Your receipt is attached as a PDF.',
        $title, $firstName, $intro,
        $attached . $numberRow . $details . $amounts . re_paragraph($keep, '20px 32px 28px 32px'),
        $footer
    );
    $text = $title . "\n\nGood day, " . $firstName . "!\n"
          . strip_tags(html_entity_decode($intro, ENT_QUOTES, 'UTF-8'))
          . "\n\nYour receipt is attached: " . $v['number'] . ".pdf. Download it to keep or print.\n\n"
          . 'System Receipt ' . $v['number'] . "\nBooking: " . $v['booking_ref']
          . "\nRoom: " . $s['room_name'] . ', ' . $s['venue_name'] . "\nDates: " . $s['dates']
          . "\nPaid by: " . strip_tags(html_entity_decode($paidBy, ENT_QUOTES, 'UTF-8'))
          . "\nAmount paid: " . $v['paid'] . "\n\n" . RE_NO_REPLY;

    return [
        'subject' => 'VENUSeP System Receipt ' . $v['number'] . ' — booking ' . $v['booking_ref'],
        'html'    => $html,
        'text'    => $text,
    ];
}

function queue_walkin_receipt(PDO $pdo, int $bookingId, int $receiptId, int $actorUserId): ?int
{
    $who = receipt_walkin_contact($pdo, $bookingId);
    $receipt = $who ? receipt_load($pdo, $receiptId) : null;
    if (!$who || !$receipt) {
        return null;
    }
    $m = re_receipt_email($receipt, re_first_name($who['name']), false, $who['email']);
    return mail_enqueue($pdo, [
        'kind' => 'walkin_receipt', 'booking_id' => $bookingId, 'receipt_id' => $receiptId,
        'requested_by_user_id' => $actorUserId ?: null, 'to_email' => $who['email'],
        'subject' => $m['subject'], 'body_html' => $m['html'], 'body_text' => $m['text'],
    ]);
}

/* "Email me this receipt". $toEmail is the account's own users.email, read by
   the caller from the database — never from the request. */
function queue_receipt_copy(PDO $pdo, int $receiptId, int $userId, string $toEmail, string $fullName): ?int
{
    $receipt = receipt_load($pdo, $receiptId);
    if (!$receipt) {
        return null;
    }
    $m = re_receipt_email($receipt, re_first_name($fullName), true, $toEmail);
    return mail_enqueue($pdo, [
        'kind' => 'receipt_copy', 'booking_id' => (int) $receipt['booking_id'], 'receipt_id' => $receiptId,
        'requested_by_user_id' => $userId, 'to_email' => $toEmail,
        'subject' => $m['subject'], 'body_html' => $m['html'], 'body_text' => $m['text'],
    ]);
}

/* The "Email me" throttle: one copy per receipt per 5 minutes. */
function receipt_copy_sent_recently(PDO $pdo, int $receiptId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM email_outbox WHERE kind = 'receipt_copy' AND receipt_id = :r
            AND created_at > NOW() - INTERVAL 5 MINUTE LIMIT 1"
    );
    $stmt->execute([':r' => $receiptId]);
    return $stmt->fetchColumn() !== false;
}
