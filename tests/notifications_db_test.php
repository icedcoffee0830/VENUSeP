<?php
/* =====================================================================
   CLI test for migration 12 — in-app notifications (DB-DECISIONS #27,
   2026-10-28), against the real local `venusep` database. Throwaway
   accounts (a customer, an admin, staff at two venues) and one booking,
   all removed afterwards, even on failure.
     - every booking status change reaches the right people, and only them:
       the customer for their booking's news; the venue's staff + admins
       for work to do; staff of OTHER venues never; nobody for their own
       action
     - a new request (notify_new_request) and account events (notify_admins)
     - the wording and where each one leads
     - unread until clicked; "mark all as read"; read ones over 90 days old
       are cleaned up, unread ones are kept
     - live "about to expire" reminders, scoped to the venue
     - deleting a booking removes its notifications
   Run:  php tests/notifications_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/notifications.php';

const NTF_DOMAIN = '@notif-selftest.invalid';

$failures = 0;
function ntf_check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database.\n");
    exit(1);
}
if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'")->fetchColumn() === 0) {
    fwrite(STDERR, "The notifications table is missing: run venusep_migration_12.sql first.\n");
    exit(1);
}

$cleanup = function () use ($pdo) {
    $like = "'%" . NTF_DOMAIN . "'";
    foreach ($pdo->query("SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id WHERE u.email LIKE $like")->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $pdo->exec('DELETE FROM bookings WHERE id = ' . (int) $bid);       // slots, timeline, notifications cascade
    }
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email LIKE $like");
    $pdo->exec("DELETE s FROM staff s JOIN users u ON u.id = s.user_id WHERE u.email LIKE $like");
    $pdo->exec("DELETE FROM users WHERE email LIKE $like");                // their notifications cascade
    /* account events reach the REAL admins too, with no booking to cascade from */
    $pdo->exec("DELETE FROM notifications WHERE detail LIKE '%" . NTF_DOMAIN . "%'");
};
$cleanup();

try {
    $venueRoom = $pdo->query("SELECT r.id, r.venue_id FROM rooms r WHERE r.room_type = 'event' AND r.is_active = 1 ORDER BY r.id LIMIT 1")->fetch();
    $otherVenue = (int) $pdo->query('SELECT id FROM venues WHERE id <> ' . (int) $venueRoom['venue_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
    $user = function (string $who, string $type) use ($pdo) {
        $pdo->prepare('INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, :u, :p, :t)')
            ->execute([':e' => $who . NTF_DOMAIN, ':u' => 'ntf_' . $who, ':p' => 'x', ':t' => $type]);
        return (int) $pdo->lastInsertId();
    };
    $cust   = $user('customer', 'customer');
    $pdo->prepare("INSERT INTO customers (user_id, full_name) VALUES (:u, 'Notif Selftest Guest')")->execute([':u' => $cust]);
    $custId = (int) $pdo->lastInsertId();
    $admin  = $user('admin', 'admin');
    $pdo->prepare("INSERT INTO staff (user_id, venue_id, full_name) VALUES (:u, NULL, 'Notif Admin')")->execute([':u' => $admin]);
    $staffA = $user('staff-a', 'staff');
    $pdo->prepare("INSERT INTO staff (user_id, venue_id, full_name) VALUES (:u, :v, 'Notif Staff A')")->execute([':u' => $staffA, ':v' => $venueRoom['venue_id']]);
    $staffB = $user('staff-b', 'staff');
    $pdo->prepare("INSERT INTO staff (user_id, venue_id, full_name) VALUES (:u, :v, 'Notif Staff B')")->execute([':u' => $staffB, ':v' => $otherVenue]);

    $kinds = function (int $userId) use ($pdo) {
        $s = $pdo->prepare('SELECT GROUP_CONCAT(kind ORDER BY id SEPARATOR ",") FROM notifications WHERE user_id = :u');
        $s->execute([':u' => $userId]);
        return (string) $s->fetchColumn();
    };
    $as = function (?int $actor, string $sql) use ($pdo) {
        $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = NULL')->execute([':a' => $actor]);
        $pdo->exec($sql);
        $pdo->exec('SET @venusep_actor_user_id = NULL');
    };

    /* a venue request whose only slot starts in 2 hours */
    $start = time() + 7200;
    $pdo->prepare("INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                                         room_price, total_amount, current_deadline_at)
                   VALUES (:c, :r, 'venue', 'pending', 'locked', 'gcash', 1000, 1000, NOW() + INTERVAL 1 DAY)")
        ->execute([':c' => $custId, ':r' => $venueRoom['id']]);
    $b = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count) VALUES (:b, 'Notif selftest', :d, :d2, 10)")
        ->execute([':b' => $b, ':d' => date('Y-m-d', $start), ':d2' => date('Y-m-d', $start)]);
    $pdo->prepare('INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time) VALUES (:b, :r, :d, :s, ADDTIME(:s2, "00:30:00"))')
        ->execute([':b' => $b, ':r' => $venueRoom['id'], ':d' => date('Y-m-d', $start), ':s' => date('H:i:00', $start), ':s2' => date('H:i:00', $start)]);
    $ref = booking_reference($b, $pdo->query("SELECT submitted_at FROM bookings WHERE id = $b")->fetchColumn());

    /* 1. a new request */
    notify_new_request($pdo, $b, $cust);
    ntf_check('1. new request: staff of the venue', $kinds($staffA), 'new_request');
    ntf_check('1. new request: admins', $kinds($admin), 'new_request');
    ntf_check('1. new request: NOT staff of another venue', $kinds($staffB), '');
    ntf_check('1. new request: NOT the customer who sent it', $kinds($cust), '');

    /* 2. reminders (live, not stored) */
    ntf_check('2. reminder for this venue', count(array_filter(notif_reminders($pdo, (int) $venueRoom['venue_id']), fn($r) => strpos($r['text'], $ref) !== false)), 1);
    ntf_check('2. no reminder for another venue', count(array_filter(notif_reminders($pdo, $otherVenue), fn($r) => strpos($r['text'], $ref) !== false)), 0);
    ntf_check('2. admins see it too', count(array_filter(notif_reminders($pdo, null), fn($r) => strpos($r['text'], $ref) !== false)), 1);

    /* 3. the life of a booking */
    $as($staffA, "UPDATE bookings SET reservation_status = 'approved', payment_status = 'await_gcash', approved_at = NOW() WHERE id = $b");
    $as($cust,   "UPDATE bookings SET payment_status = 'await_cash' WHERE id = $b");      // chose cash
    $as($cust,   "UPDATE bookings SET payment_status = 'await_gcash' WHERE id = $b");     // cancelled it
    $as($cust,   "UPDATE bookings SET payment_status = 'under_review' WHERE id = $b");    // uploaded a receipt
    $pdo->prepare("SET @venusep_actor_user_id = :a, @venusep_action_note = 'Payment rejected: wrong amount'")->execute([':a' => $staffA]);
    $pdo->exec("UPDATE bookings SET payment_status = 'await_gcash' WHERE id = $b");        // staff rejected it
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    $as($cust,   "UPDATE bookings SET payment_status = 'under_review' WHERE id = $b");
    $as($admin,  "UPDATE bookings SET payment_status = 'confirmed' WHERE id = $b");      // an admin confirmed it

    ntf_check('3. customer: their booking\'s news only', $kinds($cust), 'approved,receipt_rejected,payment_confirmed');
    ntf_check('3. staff A: work to do, not their own approval or rejection', $kinds($staffA),
              'new_request,cash_chosen,cash_cancelled,receipt_uploaded,receipt_uploaded');
    ntf_check('3. admin: the same work, not their own confirmation', $kinds($admin),
              'new_request,cash_chosen,cash_cancelled,receipt_uploaded,receipt_uploaded');
    ntf_check('3. staff of another venue: nothing', $kinds($staffB), '');

    /* 4. wording and links */
    $items = notif_items($pdo, $cust, 'customer');
    $byKind = [];
    foreach ($items as $it) { $byKind[$it['kind']] = $it; }
    ntf_check('4. approved wording', $byKind['approved']['text'], 'Your booking ' . $ref . ' was approved.');
    ntf_check('4. rejected receipt carries the reason', $byKind['receipt_rejected']['text'],
              'Your GCash receipt for ' . $ref . ' was not accepted: wrong amount. Please upload another.');
    ntf_check('4. paid -> opens Booking History with the details window', $byKind['payment_confirmed']['link'],
              'customer/booking-history.php?view=' . rawurlencode($ref));
    $staffItems = notif_items($pdo, $staffA, 'staff');
    ntf_check('4. staff items open the booking page', $staffItems[0]['link'], 'admin/booking-request.php?id=' . rawurlencode($ref));
    ntf_check('4. cash wording names the customer', $staffItems[3]['text'], 'Notif Selftest Guest will pay cash at the cashier · ' . $ref . '.');

    /* 5. read state */
    ntf_check('5. all unread to start', notif_unread_count($pdo, $staffA), 5);
    notif_mark_read($pdo, $staffA, $staffItems[0]['id']);
    ntf_check('5. clicking one marks only that one', notif_unread_count($pdo, $staffA), 4);
    notif_mark_read($pdo, $staffB, $staffItems[1]['id']);
    ntf_check('5. nobody can mark someone else\'s', notif_unread_count($pdo, $staffA), 4);
    notif_mark_read($pdo, $staffA, null);
    ntf_check('5. mark all as read', notif_unread_count($pdo, $staffA), 0);
    $pdo->exec("UPDATE notifications SET read_at = NOW() - INTERVAL 100 DAY WHERE user_id = $staffA ORDER BY id LIMIT 2");
    $pdo->exec("UPDATE notifications SET created_at = NOW() - INTERVAL 100 DAY WHERE user_id = $admin");   // old but unread
    notif_cleanup($pdo, $staffA);
    notif_cleanup($pdo, $admin);
    ntf_check('5. read ones over 90 days old are deleted', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $staffA")->fetchColumn(), 3);
    ntf_check('5. old UNREAD ones are kept', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $admin")->fetchColumn(), 5);

    /* 6. account events: every admin but the one who did it */
    $admin2 = $user('admin-2', 'admin');
    notify_admins($pdo, 'account_suspended', $admin2, ['email' => 'staff-b' . NTF_DOMAIN, 'role' => 'staff']);
    ntf_check('6. the other admin is told', substr($kinds($admin), -17), 'account_suspended');
    ntf_check('6. not the admin who did it', $kinds($admin2), '');
    ntf_check('6. not staff', substr($kinds($staffA), -17) === 'account_suspended', false);
    $adminItems = notif_items($pdo, $admin, 'admin');
    ntf_check('6. wording (an admin with no staff profile is named by email)', $adminItems[0]['text'],
              'admin-2' . NTF_DOMAIN . ' suspended staff account staff-b' . NTF_DOMAIN . '.');
    ntf_check('6. it opens Staff Management', $adminItems[0]['link'], 'admin/venusep_staffManagement.php');

    /* 7. deleting the booking removes its notifications */
    $pdo->exec("DELETE FROM bookings WHERE id = $b");
    ntf_check('7. booking deleted -> its notifications gone', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE booking_id = $b")->fetchColumn(), 0);
} finally {
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
