<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* ACTIVITY (Aron, 2026-11-01): what staff and admins did, for the admins to review.
   Newest first: booking actions, office settings, and who opened an ID. */
class ActivityController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');
        $rows = venusep_db_or_fail()->query(
            "(SELECT t.occurred_at AS at, COALESCE(u.username, u.email, 'System') AS who, u.account_type AS role,
                     CONCAT('Booking #', t.booking_id) AS what, COALESCE(t.note, t.action_code) AS detail
                FROM booking_timeline t JOIN users u ON u.id = t.performed_by_user_id
               WHERE u.account_type IN ('admin', 'staff'))
             UNION ALL
             (SELECT h.changed_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Office: ', v.name),
                     CONCAT(h.field, ': ', COALESCE(h.old_value, ''), ' to ', COALESCE(h.new_value, ''), IF(h.note IS NULL, '', CONCAT(' (', h.note, ')')))
                FROM venue_settings_history h JOIN venues v ON v.id = h.venue_id LEFT JOIN users u ON u.id = h.changed_by_user_id)
             UNION ALL
             (SELECT l.opened_at, COALESCE(u.username, u.email), u.account_type, CONCAT('Booking #', d.booking_id), 'Opened the customer ID'
                FROM document_access_log l JOIN booking_documents d ON d.id = l.document_id LEFT JOIN users u ON u.id = l.user_id)
             ORDER BY at DESC LIMIT 300"
        )->fetchAll();

        return view('admin.activity', ['rows' => $rows]);
    }
}
