<?php
/* =====================================================================
   IN-APP NOTIFICATIONS (decided 2026-10-28, migration 12, DB-DECISIONS #27)

   The inbox is the `notifications` table: one row per person per event,
   unread until that person clicks it. Rows are WRITTEN by:
     - trg_booking_timeline_notify   every booking status change
     - notify_new_request()          a new online request (booking-submit)
     - notify_admins()               staff/admin account events
   and READ by the bell (includes/notification-bell.php) through
   notifications.php. The words are made HERE, from kind + booking, when the
   bell is shown — nothing stored is phrasing, so it can change freely.

   "About to expire" reminders are not stored: they are worked out live
   (an unapproved request within 24 hours of its date) and vanish once the
   request is handled.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bookings.php';     /* booking_reference() */

const NOTIF_LIST_SIZE  = 30;
const NOTIF_KEEP_DAYS  = 90;               /* read notifications older than this are deleted */

/* A new online request: staff of that venue + every admin. Counter bookings
   do not call this — staff made those themselves. */
function notify_new_request(PDO $pdo, int $bookingId, ?int $actorUserId): void
{
    $s = $pdo->prepare('CALL sp_notify_venue_staff(:b, :k, :a, NULL)');
    $s->execute([':b' => $bookingId, ':k' => 'new_request', ':a' => $actorUserId]);
    $s->closeCursor();
}

/* A staff/admin account event, for every admin but the one who did it.
   $detail: email, role, venue — whatever the wording needs. */
function notify_admins(PDO $pdo, string $kind, int $actorUserId, array $detail): void
{
    $s = $pdo->prepare('CALL sp_notify_admins(:k, :a, :d)');
    $s->execute([':k' => $kind, ':a' => $actorUserId, ':d' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    $s->closeCursor();
}

function notif_unread_count(PDO $pdo, int $userId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL');
    $s->execute([':u' => $userId]);
    return (int) $s->fetchColumn();
}

/* $id = null marks them all read. Only ever the viewer's own rows. */
function notif_mark_read(PDO $pdo, int $userId, ?int $id): void
{
    if ($id === null) {
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL')
            ->execute([':u' => $userId]);
        return;
    }
    $pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :i AND user_id = :u')
        ->execute([':i' => $id, ':u' => $userId]);
}

function notif_cleanup(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM notifications WHERE user_id = :u AND read_at IS NOT NULL
                    AND read_at < NOW() - INTERVAL ' . NOTIF_KEEP_DAYS . ' DAY')
        ->execute([':u' => $userId]);
}

function notif_ago(string $when): string
{
    $s = time() - strtotime($when);
    if ($s < 60)    return 'just now';
    if ($s < 3600)  return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 172800) return 'yesterday';
    return date('M j', strtotime($when));
}

/* The newest notifications for one person, worded for them. */
function notif_items(PDO $pdo, int $userId, string $accountType): array
{
    $s = $pdo->prepare(
        "SELECT n.id, n.kind, n.booking_id, n.detail, n.created_at, n.read_at,
                b.submitted_at, b.booking_type, b.payment_status, b.approved_at, b.current_deadline_at,
                r.name AS room_name, v.name AS venue_name, c.full_name AS customer_name,
                COALESCE(sa.full_name, ua.email) AS actor_name
           FROM notifications n
           LEFT JOIN bookings b  ON b.id = n.booking_id
           LEFT JOIN rooms r     ON r.id = b.room_id
           LEFT JOIN venues v    ON v.id = r.venue_id
           LEFT JOIN customers c ON c.id = b.customer_id
           LEFT JOIN users ua    ON ua.id = n.actor_user_id
           LEFT JOIN staff sa    ON sa.user_id = n.actor_user_id
          WHERE n.user_id = :u
          ORDER BY n.id DESC LIMIT " . NOTIF_LIST_SIZE
    );
    $s->execute([':u' => $userId]);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        [$text, $link] = notif_words($row, $accountType);
        $out[] = ['id' => (int) $row['id'], 'kind' => $row['kind'], 'text' => $text, 'link' => $link,
                  'unread' => $row['read_at'] === null, 'ago' => notif_ago($row['created_at'])];
    }
    return $out;
}

/* [text, link] for one row. Links are relative to the app root. */
function notif_words(array $r, string $accountType): array
{
    $d   = json_decode((string) $r['detail'], true) ?: [];
    $ref = $r['booking_id'] ? booking_reference($r['booking_id'], $r['submitted_at']) : '';
    $due = $r['current_deadline_at'] ? date('M j, Y', strtotime($r['current_deadline_at'])) : '';
    $where = trim(($r['room_name'] ?? '') . ($r['venue_name'] ? ', ' . $r['venue_name'] : ''));
    $isHostel = ($r['booking_type'] ?? '') === 'hostel';
    $staffLink = 'admin/booking-request.php?id=' . rawurlencode($ref);
    $viewLink  = 'customer/booking-history.php?view=' . rawurlencode($ref);
    $payLink   = 'customer/pay.php?booking=' . rawurlencode($ref);
    $payOpen   = in_array($r['payment_status'] ?? '', ['await_gcash', 'await_cash', 'overdue'], true);

    switch ($r['kind']) {
        /* ---- the customer's own bookings ---- */
        case 'approved':
            return ['Your booking ' . $ref . ' was approved.' . ($payOpen && $due ? ' Pay by ' . $due . '.' : ''),
                    $payOpen ? $payLink : $viewLink];
        case 'rejected':
            return ['Your booking ' . $ref . ' was not approved.', $viewLink];
        case 'expired':
            return [$r['approved_at'] === null
                        ? 'Your request ' . $ref . ' expired before it was approved. Nothing was charged.'
                        : 'Your booking ' . $ref . ' was released because it was not paid in time.', $viewLink];
        case 'payment_open':
            return ['Payment is open for ' . $ref . ($due ? ' — pay by ' . $due : '') . '.', $payOpen ? $payLink : $viewLink];
        case 'receipt_rejected':
            $why = rtrim(trim(preg_replace('/^Payment rejected:\s*/', '', (string) ($d['note'] ?? ''))), '.');
            return ['Your GCash receipt for ' . $ref . ' was not accepted' . ($why !== '' ? ': ' . $why : '') . '. Please upload another.',
                    $payOpen ? $payLink : $viewLink];
        case 'payment_confirmed':
            return ['Payment received for ' . $ref . '. Your receipt is ready.', $viewLink];
        case 'refund_update':
            $words = ['refunded' => 'was completed', 'refund_denied' => 'was denied',
                      'refund_correction' => 'needs a correction from you', 'refund_processing' => 'was approved and is being processed',
                      'refund_await_or' => 'was approved — the Official Receipt is still needed'];
            return ['Your refund request for ' . $ref . ' ' . ($words[$d['pay'] ?? ''] ?? 'was updated') . '.', $viewLink];

        /* ---- staff of the venue + admins ---- */
        case 'new_request':
            return ['New booking request ' . $ref . ($where !== '' ? ' · ' . $where : '') . '.', $staffLink];
        case 'receipt_uploaded':
            return ['GCash receipt uploaded for ' . $ref . ' — needs review.', $staffLink];
        case 'cash_chosen':
            return [($r['customer_name'] ?: 'The customer') . ' will pay cash ' . ($isHostel ? 'at the front desk' : 'at the cashier') . ' · ' . $ref . '.', $staffLink];
        case 'cash_cancelled':
            return [($r['customer_name'] ?: 'The customer') . ' cancelled paying cash · ' . $ref . '. GCash is open again.', $staffLink];
        case 'refund_requested':
            return ['Refund requested for ' . $ref . '.', $staffLink];

        /* ---- admins: staff/admin accounts ---- */
        case 'account_created':
            return [($r['actor_name'] ?: 'An administrator') . ' created ' . (($d['role'] ?? '') === 'admin' ? 'an admin' : 'a staff')
                    . ' account: ' . ($d['email'] ?? '') . (!empty($d['venue']) ? ' (' . $d['venue'] . ')' : '') . '.',
                    'admin/venusep_staffManagement.php'];
        case 'account_suspended':
        case 'account_reactivated':
            return [($r['actor_name'] ?: 'An administrator') . ($r['kind'] === 'account_suspended' ? ' suspended ' : ' reactivated ')
                    . (($d['role'] ?? '') === 'admin' ? 'admin' : 'staff') . ' account ' . ($d['email'] ?? '') . '.',
                    'admin/venusep_staffManagement.php'];
        case 'account_password_reset':
            return [($r['actor_name'] ?: 'An administrator') . ' reset the password of ' . ($d['email'] ?? 'a staff member') . '.',
                    'admin/venusep_staffManagement.php'];
        case 'staff_moved':
            return [($r['actor_name'] ?: 'An administrator') . ' moved ' . ($d['email'] ?? 'a staff member') . ' to ' . ($d['venue'] ?? 'another venue') . '.',
                    'admin/venusep_staffManagement.php'];
    }
    return ['Something changed' . ($ref !== '' ? ' on ' . $ref : '') . '.', $accountType === 'customer' ? $viewLink : $staffLink];
}

/* Unapproved requests within 24 hours of their date — worked out live, for
   staff (their venue) and admins (all). $venueScope: null = every venue. */
function notif_reminders(PDO $pdo, ?int $venueScope): array
{
    $sql = "SELECT b.id, b.submitted_at, r.name AS room_name, fn_request_expiry(b.id, b.booking_type) AS expires_at
              FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE b.reservation_status = 'pending'
               AND fn_request_expiry(b.id, b.booking_type) BETWEEN NOW() AND NOW() + INTERVAL 24 HOUR"
         . ($venueScope !== null ? ' AND r.venue_id = :v' : '')
         . ' ORDER BY expires_at LIMIT 10';
    $s = $pdo->prepare($sql);
    $s->execute($venueScope !== null ? [':v' => $venueScope] : []);
    $out = [];
    foreach ($s->fetchAll() as $row) {
        $ref  = booking_reference($row['id'], $row['submitted_at']);
        $left = max(0, strtotime($row['expires_at']) - time());
        $in   = $left < 3600 ? max(1, floor($left / 60)) . ' min' : floor($left / 3600) . ' h';
        $out[] = ['text' => 'Request ' . $ref . ' (' . $row['room_name'] . ') expires in ' . $in . ' unless it is approved.',
                  'link' => 'admin/booking-request.php?id=' . rawurlencode($ref)];
    }
    return $out;
}
