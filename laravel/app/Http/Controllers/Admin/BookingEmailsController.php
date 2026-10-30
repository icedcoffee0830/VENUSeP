<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/* =====================================================================
   RECEIPT & EMAILS (was admin/booking-emails.php) — what the booking
   detail's panel reads, and its Resend button (DB-DECISIONS #22).
   Staff/admin only. Replies with JSON.

   GET   ?booking=<VB-ref>
         -> { ok, isWalkIn, to, receipt: null|{number}, emails: [...] }
   POST  _token, booking=<VB-ref>, action=resend, id=<email_outbox.id>
         -> { ok, email: {...} }

   Bodies are never returned. Resend only touches an email of THIS booking
   that failed (or has sat unsent for 5 minutes).
   CSRF: Laravel's own check; an expired token comes back as the old 400
   JSON (bootstrap/app.php).
   ===================================================================== */
class BookingEmailsController extends Controller
{
    use RepliesJson;

    public function __invoke(Request $request)
    {
        legacy('auth', 'receipt-emails');

        $beType = session('account_type');
        if (!session()->has('user_id') || !in_array($beType, ['admin', 'staff'], true)) {
            $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }
        $beIsPost = $request->isMethod('post');

        $beBookingId = booking_id_from_reference($beIsPost ? ($request->post('booking') ?? '') : ($request->query('booking') ?? ''));
        $pdo = venusep_db();
        if ($beBookingId === null) {
            $this->reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
        }
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable.']);
        }
        /* Own venue only for staff — reading the emails as well as resending them (2026-10-31). */
        if (!staff_may_handle_booking($beBookingId)) {
            $this->reply(403, ['ok' => false, 'message' => 'This booking belongs to another venue. Only that venue\'s staff or an administrator can see its emails.']);
        }

        if ($beIsPost) {
            if (($request->post('action') ?? '') !== 'resend') {
                $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
            }
            $stmt = $pdo->prepare(
                "SELECT id FROM email_outbox
                  WHERE id = :id AND booking_id = :b
                    AND (status = 'failed' OR (status = 'pending' AND created_at < NOW() - INTERVAL 5 MINUTE))"
            );
            $stmt->execute([':id' => (int) ($request->post('id') ?? 0), ':b' => $beBookingId]);
            $beId = $stmt->fetchColumn();
            if ($beId === false) {
                $this->reply(409, ['ok' => false, 'message' => 'That email was already sent, or is still on its way.']);
            }
            mail_send($pdo, (int) $beId);
            $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE id = :id');
            $stmt->execute([':id' => $beId]);
            $row = $stmt->fetch();
            $this->reply(200, ['ok' => $row['status'] === 'sent', 'email' => $this->email($row),
                'message' => $row['status'] === 'sent' ? 'Sent.' : 'Still couldn&rsquo;t send it. Check the mail settings and try again.']);
        }

        $stmt = $pdo->prepare(
            'SELECT c.user_id, c.contact_email, u.email AS account_email
               FROM bookings b JOIN customers c ON c.id = b.customer_id LEFT JOIN users u ON u.id = c.user_id
              WHERE b.id = :b'
        );
        $stmt->execute([':b' => $beBookingId]);
        $who = $stmt->fetch();
        if (!$who) {
            $this->reply(404, ['ok' => false, 'message' => 'That booking no longer exists.']);
        }
        $isWalkIn = $who['user_id'] === null;

        $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE booking_id = :b ORDER BY id');
        $stmt->execute([':b' => $beBookingId]);
        $receipt = receipt_for_booking($pdo, $beBookingId);

        $this->reply(200, [
            'ok'       => true,
            'isWalkIn' => $isWalkIn,
            'to'       => (string) ($isWalkIn ? $who['contact_email'] : $who['account_email']),
            'receipt'  => $receipt ? ['number' => $receipt['number']] : null,
            'emails'   => array_map([$this, 'email'], $stmt->fetchAll()),
        ]);
    }

    /* One outbox row as the panel shows it. */
    private function email(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'kind'      => $r['kind'],
            'status'    => $r['status'],
            'attempts'  => (int) $r['attempts'],
            'receiptId' => $r['receipt_id'] !== null ? (int) $r['receipt_id'] : null,
            'created'   => date('M j, Y, g:i A', strtotime($r['created_at'])),
            'sent'      => $r['sent_at'] ? date('M j, Y, g:i A', strtotime($r['sent_at'])) : null,
            'error'     => $r['last_error'] !== null ? mb_substr((string) $r['last_error'], 0, 120) : null,
        ];
    }
}
