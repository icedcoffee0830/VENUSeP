<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   CUSTOMER MANAGEMENT + CUSTOMER SAVE — ported from admin/customer-management.php
   and admin/customer-save.php.

   ADMIN AND STAFF. Unlike Staff Management (admin-only — staff managing
   their own side would be a conflict of interest), disabling a CUSTOMER is
   ordinary front-desk work: the same people who verify a customer's identity
   at the counter are the ones who need to shut off an abusive account.

   THE ROSTER is account holders only. Walk-ins (no user_id, no login) are
   excluded on purpose: there is no account there to disable.

   SAVE: POST action=set_active, user_id, active=0|1 -> JSON. The target must
   be a CUSTOMER account (staff-save owns the staff side and its stricter
   rules). Never deletes. Disabling signs them out: customer_session_heal()
   re-reads users.is_active on every request.
   ===================================================================== */
class CustomerController
{
    public function index()
    {
        legacy('db');
        $cmRows = [];
        try {
            $cmPdo = venusep_db_or_fail();
            $cmStmt = $cmPdo->query(
                "SELECT u.id AS user_id, u.email, u.is_active, u.last_login_at,
                        c.id AS customer_id, c.full_name, c.phone, c.university_id_no, c.created_at,
                        (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS booking_count
                   FROM customers c
                   JOIN users u ON u.id = c.user_id
                  WHERE u.account_type = 'customer'
                  ORDER BY c.full_name"
            );
            foreach ($cmStmt as $r) {
                $cmRows[] = [
                    'userId'    => (int) $r['user_id'],
                    'id'        => (int) $r['customer_id'],
                    'name'      => (string) $r['full_name'],
                    'email'     => (string) $r['email'],
                    'phone'     => (string) ($r['phone'] ?? ''),
                    'usep'      => $r['university_id_no'] !== null && $r['university_id_no'] !== '' ? 'USeP' : '—',
                    'status'    => $r['is_active'] ? 'Active' : 'Disabled',
                    'bookings'  => (int) $r['booking_count'],
                    'joined'    => $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '—',
                    'lastLogin' => $r['last_login_at'] ? date('M j, Y', strtotime($r['last_login_at'])) : 'never',
                ];
            }
        } catch (PDOException $e) {
            $cmRows = [];
        }

        return view('admin.customer-management', ['cmRows' => $cmRows]);
    }

    private function reply(int $status, array $data)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }

    /* The route skips Laravel's CSRF middleware so an expired page still gets
       the old 400 JSON; the token is checked here (old field `csrf`,
       Laravel's `_token`, or the X-CSRF-TOKEN header). */
    public function save(Request $request)
    {
        legacy('auth');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'POST');
        }
        if (!venusep_is_staff_session()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Only admin or staff can manage customer accounts.']);
        }
        $token = $request->input('csrf', $request->input('_token', $request->header('X-CSRF-TOKEN')));
        if (!csrf_valid($token)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $action = (string) $request->input('action', '');
        $rawId  = $request->input('user_id');
        $target = $rawId !== null && ctype_digit((string) $rawId) ? (int) $rawId : 0;
        if (!$target) {
            return $this->reply(400, ['ok' => false, 'message' => 'Invalid account.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was saved.']);
        }

        $row = $pdo->prepare('SELECT id, account_type, is_active FROM users WHERE id = :u');
        $row->execute([':u' => $target]);
        $user = $row->fetch();
        if (!$user) {
            return $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
        }
        /* Customer-side only — staff-save owns the staff side. */
        if ($user['account_type'] !== 'customer') {
            return $this->reply(403, ['ok' => false, 'message' => 'That account is not a customer account.']);
        }

        if ($action === 'set_active') {
            $active = !empty($request->input('active')) ? 1 : 0;

            try {
                $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :u')
                    ->execute([':a' => $active, ':u' => $target]);
            } catch (PDOException $e) {
                error_log('VENUSeP customer-save (set_active): ' . $e->getMessage());
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
            }
            return $this->reply(200, ['ok' => true, 'active' => $active,
                'message' => $active ? 'Account re-enabled.' : 'Account disabled. They have been signed out and can no longer sign in.']);
        }

        return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
}
