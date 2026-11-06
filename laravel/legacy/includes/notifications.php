<?php
/* Holds: writing and reading in-app notifications. */
/* =====================================================================
   IN-APP NOTIFICATIONS (decided 2026-10-28, migration 12, DB-DECISIONS #27)

   The inbox is the `notifications` table: one row per person per event,
   unread until that person clicks it. Rows are WRITTEN by:
     - trg_booking_timeline_notify   every booking status change
     - notify_new_request()          a new online request (booking-submit)
     - notify_account_event()        staff/admin account events
   and READ by the bell (notification-bell.php) through
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
function notify_new_request(int $bookingId, ?int $actorUserId): void
{
    \App\Queries\NotificationQueries::tellVenueStaff($bookingId, 'new_request', $actorUserId);
}

/* Something only an admin decides (a refund with a missing receipt, a write-off) waits for
   the group's normal admin; a group with no active normal admin is covered by the super
   admin (DB-DECISIONS 28). The NOT EXISTS reads its own users u, not the outer one. */
function notify_refund_needs_admin(int $bookingId, int $actorUserId, string $kind = 'refund_needs_admin'): void
{
    \App\Queries\NotificationQueries::tellGroupAdmins(sprintf(NOTIF_GROUP_ADMINS_SQL, $bookingId), $bookingId, $kind, $actorUserId);
}

/* The customer and the group's normal admins, with the staff member's own words. */
function notify_booking_parties(int $bookingId, string $kind, int $actorUserId, array $detail): void
{
    \App\Queries\NotificationQueries::tellBookingParties(sprintf(NOTIF_GROUP_ADMINS_SQL, $bookingId), $bookingId, $kind, $actorUserId, json_encode($detail, JSON_UNESCAPED_UNICODE));
}

/* A staff/admin account event (DB-DECISIONS 28), never for the one who did it.
   $venueIds: the staff member's offices (a move: old and new) — that group's normal
   admins are told; null = a system-level event (an admin account, the handover) —
   the super admin is told. $detail: email, role, venue — whatever the wording needs.
   (Replaces sp_notify_admins, which told every admin; the procedure stays, unused.) */
function notify_account_event(string $kind, int $actorUserId, array $detail, ?array $venueIds): void
{
    $who = $venueIds === null
        ? 'u.is_super_admin = 1'
        : sprintf('u.is_super_admin = 0 AND u.office_group IN (SELECT v.office_group FROM venues v WHERE v.id IN (%s))',
                  implode(',', array_map('intval', $venueIds) ?: [0]));
    \App\Queries\NotificationQueries::tellAdmins($who, $kind, $actorUserId, json_encode($detail, JSON_UNESCAPED_UNICODE));
}

function notif_unread_count(int $userId): int
{
    return \App\Queries\NotificationQueries::unreadCount($userId);
}

/* Is something waiting for this customer? The red dot on the phone's Bookings tab. It reads
   the bell's rows but not their read marks: the dot stays until the thing is done, so
   "Mark all as read" does not hide a payment that is still due. Waiting = a payment due or
   a refund returned for correction, or a rejected request that can still take a new ID
   (the same test as Booking History's "Send a new ID"). */
function notif_needs_customer(int $userId): bool
{
    $waiting = "'" . implode("','", [...\App\Support\Status::PAYMENT_DUE, \App\Support\Status::REFUND_CORRECTION]) . "'";
    return \App\Queries\NotificationQueries::somethingWaiting($userId, $waiting);
}

/* $id = null marks them all read. Only ever the viewer's own rows. */
function notif_mark_read(int $userId, ?int $id): void
{
    if ($id === null) {
        \App\Queries\NotificationQueries::markAllRead($userId);
        return;
    }
    \App\Queries\NotificationQueries::markRead($id, $userId);
}

function notif_cleanup(int $userId): void
{
    \App\Queries\NotificationQueries::cleanUp($userId, NOTIF_KEEP_DAYS);
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
function notif_items(int $userId, string $accountType): array
{
    $out = [];
    foreach (\App\Queries\NotificationQueries::newest($userId, NOTIF_LIST_SIZE) as $row) {
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
function notif_reminders(?array $offices, bool $isAdmin, ?int $staffId = null): array
{
    if ($offices === null) {
        return [];
    }
    $officeIds = implode(',', array_map('intval', $offices) ?: [0]);
    $waiting = [];
    if ($isAdmin) {
        foreach (\App\Queries\NotificationQueries::longWaiting($officeIds) as $w) {
            $ref = booking_reference($w['id'], $w['submitted_at']);
            $waiting[] = ['text' => 'Request ' . $ref . ' has waited ' . (int) $w['days'] . ' days for an answer.',
                          'link' => 'admin/booking-request.php?id=' . rawurlencode($ref)];
        }
    }
    $out = $waiting;
    foreach (\App\Queries\NotificationQueries::expiringSoon($officeIds, $staffId) as $row) {
        $ref  = booking_reference($row['id'], $row['submitted_at']);
        $left = max(0, strtotime($row['expires_at']) - time());
        $in   = $left < 3600 ? max(1, floor($left / 60)) . ' min' : floor($left / 3600) . ' h';
        $out[] = ['text' => 'Request ' . $ref . ' (' . $row['room_name'] . ') expires in ' . $in . ' unless it is approved.',
                  'link' => 'admin/booking-request.php?id=' . rawurlencode($ref)];
    }
    return $out;
}
