<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\SchemaQueries;
use Illuminate\Http\Request;

/* ACTIVITY (Aron, 2026-11-01; everything since 2026-11-24): what everyone did, for the super
   admin to review. Newest first, PER_PAGE to a page: booking actions (staff, admins and
   customers), office settings, who opened an ID, customer blocks at an office (set and
   lifted, with the reasons), deleted rooms, every step of a cover or a move between groups,
   and the activity log (migration 21: sign-ins, passwords, suspensions, revokes, handovers,
   two-step verification, backups, bookings submitted). Before migration 21 that part is skipped. */
class ActivityController extends Controller
{
    private const PER_PAGE = 50;

    public function __invoke(Request $request)
    {
        legacy('bookings');
        $pdo = venusep_db_or_fail();
        $all = self::parts();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM ($all) everything")->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $pages);
        $rows = $pdo->query("$all ORDER BY at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE))->fetchAll();

        return view('admin.activity', ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total]);
    }

    /* Every recorded action, one UNION part per table: at, who, role, what, detail. A status
       change with no note says what changed (a receipt sent: "Payment: await gcash to under review"). */
    private static function parts(): string
    {
        return "(SELECT t.occurred_at AS at, COALESCE(u.username, u.email, 'System') AS who, u.account_type AS role,
                     CONCAT('Booking #', t.booking_id) AS what,
                     COALESCE(t.note, IF(t.old_payment_status <> t.new_payment_status,
                              CONCAT('Payment: ', REPLACE(t.old_payment_status, '_', ' '), ' to ', REPLACE(t.new_payment_status, '_', ' ')),
                              t.action_code)) AS detail
                FROM booking_timeline t JOIN users u ON u.id = t.performed_by_user_id)
             UNION ALL
             (SELECT h.changed_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Office: ', v.name),
                     CONCAT(h.field, ': ', COALESCE(h.old_value, ''), ' to ', COALESCE(h.new_value, ''), IF(h.note IS NULL, '', CONCAT(' (', h.note, ')')))
                FROM venue_settings_history h JOIN venues v ON v.id = h.venue_id LEFT JOIN users u ON u.id = h.changed_by_user_id)
             UNION ALL
             (SELECT l.opened_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Booking #', d.booking_id), 'Opened the customer ID'
                FROM document_access_log l JOIN booking_documents d ON d.id = l.document_id LEFT JOIN users u ON u.id = l.user_id)
             UNION ALL
             (SELECT k.blocked_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Customer: ', c.full_name),
                     CONCAT('Blocked at ', v.name, ': ', k.reason)
                FROM customer_office_blocks k JOIN customers c ON c.id = k.customer_id JOIN venues v ON v.id = k.venue_id
                LEFT JOIN users u ON u.id = k.blocked_by_user_id)
             UNION ALL
             (SELECT k.lifted_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Customer: ', c.full_name),
                     CONCAT('Block lifted at ', v.name, ': ', COALESCE(k.lift_reason, ''))
                FROM customer_office_blocks k JOIN customers c ON c.id = k.customer_id JOIN venues v ON v.id = k.venue_id
                LEFT JOIN users u ON u.id = k.lifted_by_user_id
               WHERE k.lifted_at IS NOT NULL)
             UNION ALL
             (SELECT r.deleted_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Office: ', v.name),
                     CONCAT('Deleted the room ', r.name)
                FROM rooms r JOIN venues v ON v.id = r.venue_id LEFT JOIN users u ON u.id = r.deleted_by_user_id
               WHERE r.deleted_at IS NOT NULL)
             UNION ALL
             " . self::coverSteps()
             . (SchemaQueries::has('activity_log') ? "
             UNION ALL
             (SELECT a.occurred_at, COALESCE(u.username, u.email, 'Unknown'), COALESCE(a.role, u.account_type), a.subject, a.detail
                FROM activity_log a LEFT JOIN users u ON u.id = a.user_id)" : '');
    }

    /* Every step of a cover or a move between groups, one UNION part per step. An unanswered
       one has no person behind it: its expiry shows as System, at the time it lapsed. */
    private static function coverSteps(): string
    {
        $cover = 'FROM staff_covers c JOIN staff s ON s.user_id = c.user_id JOIN venues v ON v.id = c.venue_id LEFT JOIN rooms r ON r.id = c.room_id';
        $on = "CONCAT('Cover: ', s.full_name)";
        $place = "CONCAT(IF(r.name IS NULL, '', CONCAT(r.name, ', ')), v.name, ', ', DATE_FORMAT(c.starts_at, '%b %e %l:%i %p'), ' to ', DATE_FORMAT(c.ends_at, '%b %e %l:%i %p'))";
        $who = 'COALESCE(u.username, u.email), u.account_type';
        $move = 'FROM staff_moves m JOIN staff s ON s.user_id = m.user_id JOIN venues f ON f.id = m.from_venue_id JOIN venues t ON t.id = m.to_venue_id';
        $moveOn = "CONCAT('Move: ', s.full_name)";
        $route = "CONCAT(f.name, ' to ', t.name)";
        return implode(' UNION ALL ', [
            "(SELECT c.created_at, $who, $on, CONCAT(IF(c.approved_at IS NULL OR c.approved_by_user_id <> c.created_by_user_id, 'Asked for a cover at ', 'Set a cover at '), $place, ': ', c.reason)
                $cover LEFT JOIN users u ON u.id = c.created_by_user_id)",
            "(SELECT c.approved_at, $who, $on, CONCAT('Approved the cover at ', $place)
                $cover LEFT JOIN users u ON u.id = c.approved_by_user_id
               WHERE c.approved_at IS NOT NULL AND c.approved_by_user_id <> c.created_by_user_id)",
            "(SELECT c.rejected_at, $who, $on, CONCAT('Rejected the cover at ', $place, ': ', c.reject_reason)
                $cover LEFT JOIN users u ON u.id = c.rejected_by_user_id WHERE c.rejected_at IS NOT NULL)",
            "(SELECT c.ended_at, $who, $on, CONCAT('Ended the cover at ', $place, ' early')
                $cover LEFT JOIN users u ON u.id = c.ended_by_user_id WHERE c.ended_at IS NOT NULL)",
            "(SELECT c.starts_at, 'System', NULL, $on, CONCAT('The cover request at ', $place, ' expired unanswered')
                $cover WHERE c.approved_at IS NULL AND c.rejected_at IS NULL AND c.ended_at IS NULL AND c.starts_at <= NOW())",
            "(SELECT m.requested_at, $who, $moveOn, CONCAT('Started the move ', $route, ': ', m.reason)
                $move LEFT JOIN users u ON u.id = m.requested_by_user_id)",
            "(SELECT m.decided_at, $who, $moveOn, CONCAT(IF(m.approved, 'Approved the move ', 'Rejected the move '), $route, IF(m.approved, '', CONCAT(': ', m.reject_reason)))
                $move LEFT JOIN users u ON u.id = m.decided_by_user_id WHERE m.decided_at IS NOT NULL)",
            "(SELECT m.requested_at + INTERVAL 3 DAY, 'System', NULL, $moveOn, CONCAT('The move ', $route, ' expired unanswered')
                $move WHERE m.approved IS NULL AND m.requested_at <= NOW() - INTERVAL 3 DAY)",
        ]);
    }
}
