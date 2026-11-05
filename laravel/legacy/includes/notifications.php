<?php
/* =====================================================================
   IN-APP NOTIFICATIONS (decided 2026-10-28, migration 12, DB-DECISIONS #27)

   The inbox is the `notifications` table: one row per person per event,
   unread until that person clicks it. Rows are WRITTEN by:
     - trg_booking_timeline_notify   every booking status change
     - notify_new_request()          a new online request (booking-submit)
     - notify_account_event()        staff/admin account events
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

/* The normal admins of the booking's office group (DB-DECISIONS 28): booking news
   never goes to the super admin. SQL on users u; %d = the booking id. */
const NOTIF_GROUP_ADMINS_SQL = "u.account_type = 'admin' AND u.is_super_admin = 0
    AND u.office_group = (SELECT v.office_group FROM bookings b JOIN rooms r ON r.id = b.room_id
                           JOIN venues v ON v.id = r.venue_id WHERE b.id = %d)";

/* A new online request: staff of that hall + its group's normal admins. Counter bookings
   do not call this — staff made those themselves. */
function notify_new_request(PDO $pdo, int $bookingId, ?int $actorUserId): void
{
    $s = $pdo->prepare('CALL sp_notify_venue_staff(:b, :k, :a, NULL)');
    $s->execute([':b' => $bookingId, ':k' => 'new_request', ':a' => $actorUserId]);
    $s->closeCursor();
}

/* Something only an admin decides (a refund with a missing receipt, a write-off) waits for
   the group's normal admin; a group with no active normal admin is covered by the super
   admin (DB-DECISIONS 28). The NOT EXISTS reads its own users u, not the outer one. */
function notify_refund_needs_admin(PDO $pdo, int $bookingId, int $actorUserId, string $kind = 'refund_needs_admin'): void
{
    $group = sprintf(NOTIF_GROUP_ADMINS_SQL, $bookingId);
    $pdo->prepare(
        "INSERT INTO notifications (user_id, kind, booking_id, actor_user_id)
         SELECT u.id, :k, :b, :a FROM users u
          WHERE u.is_active = 1 AND u.id <> :a2
            AND (($group) OR (u.is_super_admin = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.is_active = 1 AND $group)))"
    )->execute([':k' => $kind, ':b' => $bookingId, ':a' => $actorUserId, ':a2' => $actorUserId]);
}

/* The customer and the group's normal admins, with the staff member's own words. */
function notify_booking_parties(PDO $pdo, int $bookingId, string $kind, int $actorUserId, array $detail): void
{
    $pdo->prepare(
        "INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
         SELECT u.id, :k, :b, :a, :d FROM users u
          WHERE u.is_active = 1 AND u.id <> :a2
            AND ((" . sprintf(NOTIF_GROUP_ADMINS_SQL, $bookingId) . ")
                 OR u.id = (SELECT c.user_id FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = :b2))"
    )->execute([':k' => $kind, ':b' => $bookingId, ':a' => $actorUserId, ':d' => json_encode($detail, JSON_UNESCAPED_UNICODE),
                ':a2' => $actorUserId, ':b2' => $bookingId]);
}

/* A staff/admin account event (DB-DECISIONS 28), never for the one who did it.
   $venueIds: the staff member's offices (a move: old and new) — that group's normal
   admins are told; null = a system-level event (an admin account, the handover) —
   the super admin is told. $detail: email, role, venue — whatever the wording needs.
   (Replaces sp_notify_admins, which told every admin; the procedure stays, unused.) */
function notify_account_event(PDO $pdo, string $kind, int $actorUserId, array $detail, ?array $venueIds): void
{
    $who = $venueIds === null
        ? 'u.is_super_admin = 1'
        : sprintf('u.is_super_admin = 0 AND u.office_group IN (SELECT v.office_group FROM venues v WHERE v.id IN (%s))',
                  implode(',', array_map('intval', $venueIds) ?: [0]));
    $pdo->prepare(
        "INSERT INTO notifications (user_id, kind, actor_user_id, detail)
         SELECT u.id, :k, :a, :d FROM users u
          WHERE u.account_type = 'admin' AND u.is_active = 1 AND u.id <> :a2 AND $who"
    )->execute([':k' => $kind, ':a' => $actorUserId, ':d' => json_encode($detail, JSON_UNESCAPED_UNICODE), ':a2' => $actorUserId]);
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
    $payOpen   = in_array($r['payment_status'] ?? '', \App\Support\Status::PAY_OPEN, true);

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
        case 'booking_cancelled':
            return ['Booking ' . $ref . ' was cancelled by the office. ' . ($d['note'] ?? ''), $accountType === 'customer' ? $viewLink : $staffLink];
        case 'payment_reversed':
            return ['The payment on ' . $ref . ' was reversed. ' . ($d['note'] ?? ''), $accountType === 'customer' ? $viewLink : $staffLink];
        case 'booking_moved':
            return ['Booking ' . $ref . ' was moved. ' . ($d['note'] ?? ''), $accountType === 'customer' ? $viewLink : $staffLink];
        case 'write_off_requested':
            return ['Staff asked to write off the debt on ' . $ref . '. Approve or decline it.', $staffLink];
        case 'refund_recorded':
            return ['A refund made outside the system was recorded on ' . $ref . '.', $staffLink];
        case 'refund_needs_admin':
            return ['The receipt for ' . $ref . ' is missing. Check the proof and approve the refund.', $staffLink];

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
        /* ---- admins: covers and moves between groups (StaffCoverController) ---- */
        case 'cover_request':
            return [($r['actor_name'] ?: 'An administrator') . ' asks for ' . ($d['staff'] ?? 'a staff member') . ' to cover ' . ($d['place'] ?? 'your office')
                    . ', ' . notif_span($d) . '. Approve or reject it.', 'admin/venusep_staffManagement.php'];
        case 'cover_set':
        case 'cover_approved':
        case 'cover_rejected':
        case 'cover_ended':
            $did = ['cover_set' => ' set a cover: ', 'cover_approved' => ' approved the cover: ', 'cover_rejected' => ' rejected the cover: ', 'cover_ended' => ' ended the cover early: '];
            return [($r['actor_name'] ?: 'An administrator') . $did[$r['kind']] . ($d['staff'] ?? 'a staff member') . ' at ' . ($d['place'] ?? 'an office') . ', ' . notif_span($d) . '.'
                    . (!empty($d['reason']) ? ' Reason: ' . $d['reason'] : ''), 'admin/venusep_staffManagement.php'];
        case 'move_request':
            return [($r['actor_name'] ?: 'An administrator') . ' asks to move ' . ($d['staff'] ?? 'a staff member') . ' from ' . ($d['from'] ?? 'another office')
                    . ' to ' . ($d['to'] ?? 'your office') . '. Approve or reject it.', 'admin/venusep_staffManagement.php'];
        case 'move_approved':
        case 'move_rejected':
            return [($r['actor_name'] ?: 'An administrator') . ($r['kind'] === 'move_approved' ? ' moved ' : ' rejected the move of ') . ($d['staff'] ?? 'a staff member')
                    . ' to ' . ($d['to'] ?? 'another office') . '.' . (!empty($d['reason']) ? ' Reason: ' . $d['reason'] : ''), 'admin/venusep_staffManagement.php'];
        case 'super_admin_handover':
            return [($r['actor_name'] ?: 'The previous super admin') . ' handed the super admin over to you.', 'admin/venusep_staffManagement.php'];
    }
    return ['Something changed' . ($ref !== '' ? ' on ' . $ref : '') . '.', $accountType === 'customer' ? $viewLink : $staffLink];
}

/* "Nov 6, 8:00 AM to Nov 8, 5:00 PM" for a cover's detail. */
function notif_span(array $d): string
{
    $at = fn (string $k) => date('M j, g:i A', strtotime((string) ($d[$k] ?? 'now')));
    return $at('from') . ' to ' . $at('to');
}

/* Unapproved requests within 24 hours of their date — worked out live, for staff
   and normal admins, in the offices they handle; a normal admin also hears of
   requests waiting 3 days. $offices: null = the super admin, who gets no booking
   reminders (DB-DECISIONS 28). $staffId: a staff member hears only of their own halls
   and covers, like the stored news (sp_notify_venue_staff). */
function notif_reminders(PDO $pdo, ?array $offices, bool $isAdmin, ?int $staffId = null): array
{
    if ($offices === null) {
        return [];
    }
    $officeIds = implode(',', array_map('intval', $offices) ?: [0]);
    $waiting = [];
    if ($isAdmin) {
        foreach ($pdo->query("SELECT b.id, b.submitted_at, DATEDIFF(NOW(), b.submitted_at) AS days FROM bookings b
                               JOIN rooms r ON r.id = b.room_id
                               WHERE b.reservation_status = 'pending' AND b.submitted_at <= NOW() - INTERVAL 3 DAY
                                 AND r.venue_id IN ($officeIds)
                               ORDER BY b.submitted_at LIMIT 5")->fetchAll() as $w) {
            $ref = booking_reference($w['id'], $w['submitted_at']);
            $waiting[] = ['text' => 'Request ' . $ref . ' has waited ' . (int) $w['days'] . ' days for an answer.',
                          'link' => 'admin/booking-request.php?id=' . rawurlencode($ref)];
        }
    }
    $sql = "SELECT b.id, b.submitted_at, r.name AS room_name, fn_request_expiry(b.id, b.booking_type) AS expires_at
              FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE b.reservation_status = 'pending'
               AND fn_request_expiry(b.id, b.booking_type) BETWEEN NOW() AND NOW() + INTERVAL 24 HOUR"
         . " AND r.venue_id IN ($officeIds)" . ($staffId === null ? '' : ' AND fn_staff_on_room(' . $staffId . ', r.id)') . ' ORDER BY expires_at LIMIT 10';
    $s = $pdo->prepare($sql);
    $s->execute();
    $out = $waiting;
    foreach ($s->fetchAll() as $row) {
        $ref  = booking_reference($row['id'], $row['submitted_at']);
        $left = max(0, strtotime($row['expires_at']) - time());
        $in   = $left < 3600 ? max(1, floor($left / 60)) . ' min' : floor($left / 3600) . ' h';
        $out[] = ['text' => 'Request ' . $ref . ' (' . $row['room_name'] . ') expires in ' . $in . ' unless it is approved.',
                  'link' => 'admin/booking-request.php?id=' . rawurlencode($ref)];
    }
    return $out;
}
