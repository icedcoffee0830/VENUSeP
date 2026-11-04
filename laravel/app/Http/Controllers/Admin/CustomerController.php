<?php

namespace App\Http\Controllers\Admin;

use App\Queries\BlockQueries;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   CUSTOMER MANAGEMENT + CUSTOMER SAVE — ported from admin/customer-management.php
   and admin/customer-save.php.

   ADMIN ONLY (Aron 2026-10-31). Staff keep the counter's own customer search and see
   the customer on their venue's bookings.

   WHO SEES WHOM (DB-DECISIONS 28): the super admin every customer; a normal admin only
   the customers who have booked at its group's offices, counting their bookings there.

   THE ROSTER is account holders only. Walk-ins (no user_id, no login) are
   excluded on purpose: there is no account there to disable.

   SAVE: POST action=..., JSON back. Never deletes.
     set_active, user_id, active=0|1: SUSPEND the whole account (no sign-in). The super
       admin only, for fake, spam or fraud accounts. The target must be a CUSTOMER account
       (staff-save owns the staff side). Disabling signs them out: customer_session_heal()
       re-reads users.is_active on every request.
     block | lift, customer_id, venue_id, reason: no new bookings at THAT office (BlockQueries).
       That office's group admin or the super admin; a reason is required and kept.
   ===================================================================== */
class CustomerController
{
    public function index()
    {
        legacy('auth', 'offices');
        $scope = staff_venue_scope();   /* null = the super admin: every customer, every office */
        $mine = $scope === null ? '' : ' AND r.venue_id IN (' . implode(',', array_map('intval', $scope) ?: [0]) . ')';
        $offices = array_values(array_filter(office_all(), fn ($o) => staff_may_handle_venue($o['id'])));
        $cmRows = [];
        try {
            $cmPdo = venusep_db_or_fail();
            $blocks = BlockQueries::activeByCustomer($scope);
            $cmStmt = $cmPdo->query(
                "SELECT u.id AS user_id, u.email, u.is_active, u.last_login_at,
                        c.id AS customer_id, c.full_name, c.phone, c.university_id_no, c.created_at,
                        (SELECT COUNT(*) FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.customer_id = c.id $mine) AS booking_count,
                        (SELECT COUNT(*) FROM bookings b JOIN rooms r ON r.id = b.room_id
                          WHERE b.customer_id = c.id $mine AND b.reservation_status IN ('pending', 'approved')
                            AND (b.payment_status = 'overdue' OR fn_booking_last_day(b.id) >= CURDATE())) AS open_count
                   FROM customers c
                   JOIN users u ON u.id = c.user_id
                  WHERE u.account_type = 'customer'"
                  . ($scope === null ? '' : " AND EXISTS (SELECT 1 FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.customer_id = c.id $mine)") . '
                  ORDER BY c.full_name'
            );
            foreach ($cmStmt as $r) {
                $on = $blocks[(int) $r['customer_id']] ?? [];
                $cmRows[] = [
                    'userId'    => (int) $r['user_id'],
                    'id'        => (int) $r['customer_id'],
                    'name'      => (string) $r['full_name'],
                    'email'     => (string) $r['email'],
                    'phone'     => (string) ($r['phone'] ?? ''),
                    'usep'      => $r['university_id_no'] !== null && $r['university_id_no'] !== '' ? 'USeP' : '—',
                    'status'    => $r['is_active'] ? 'Active' : 'Disabled',
                    'bookings'  => (int) $r['booking_count'],
                    'open'      => (int) $r['open_count'],
                    'joined'    => $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '—',
                    'lastLogin' => $r['last_login_at'] ? date('M j, Y', strtotime($r['last_login_at'])) : 'never',
                    /* one entry per office this admin may block at: on or off, and by whom */
                    'blocks'    => array_map(fn ($o) => ['venueId' => $o['id'], 'office' => $o['name'], 'on' => isset($on[$o['id']])]
                        + (isset($on[$o['id']]) ? ['reason' => $on[$o['id']]['reason'], 'by' => $on[$o['id']]['blocked_by'],
                                                   'at' => date('M j, Y', strtotime($on[$o['id']]['blocked_at']))] : []), $offices),
                ];
            }
        } catch (PDOException $e) {
            sql_failed($e);
            $cmRows = [];
        }

        return view('admin.customer-management', ['cmRows' => $cmRows, 'cmMaySuspend' => admin_is_super()]);
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
        legacy('auth', 'offices');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'POST');
        }
        if (session('account_type') !== 'admin' || !CounterCustomerController::staffStillActive()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can manage customer accounts.']);
        }
        $token = $request->input('csrf', $request->input('_token', $request->header('X-CSRF-TOKEN')));
        if (!csrf_valid($token)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $action = (string) $request->input('action', '');
        if ($action === 'block' || $action === 'lift') {
            return $this->blockOrLift($request, $action);
        }
        if (!admin_is_super()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Only the super admin can suspend a customer account. To stop bookings at your office, block the customer there.']);
        }
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
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
            }
            return $this->reply(200, ['ok' => true, 'active' => $active,
                'message' => $active ? 'Account re-enabled.' : 'Account disabled. They have been signed out and can no longer sign in.']);
        }

        return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    /* Block or lift a customer at one office: check, then save. */
    private function blockOrLift(Request $request, string $action)
    {
        // Check
        $customerId = (int) $request->input('customer_id', 0);
        $office = office_by_id((int) $request->input('venue_id', 0));
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 500);
        if ($office === null) {
            return $this->reply(404, ['ok' => false, 'message' => 'That office no longer exists.']);
        }
        if (!staff_may_handle_venue($office['id'])) {
            return $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'That office belongs to another group. Only its admin or the super admin can block customers there.']);
        }
        $exists = venusep_db_or_fail()->prepare('SELECT 1 FROM customers WHERE id = :c');
        $exists->execute([':c' => $customerId]);
        if ($exists->fetchColumn() === false) {
            return $this->reply(404, ['ok' => false, 'message' => 'That customer no longer exists.']);
        }
        if ($reason === '') {
            return $this->reply(400, ['ok' => false, 'message' => 'Write the reason. It is kept with the block; the customer never sees it.']);
        }
        $on = BlockQueries::findActive($customerId, $office['id']) !== null;
        if ($action === 'block' && $on) {
            return $this->reply(409, ['ok' => false, 'message' => 'This customer is already blocked at ' . $office['name'] . '.']);
        }
        if ($action === 'lift' && !$on) {
            return $this->reply(409, ['ok' => false, 'message' => 'This customer is not blocked at ' . $office['name'] . '.']);
        }

        // Save
        $actor = (int) session('user_id');
        if ($action === 'block') {
            try {
                BlockQueries::block($customerId, $office['id'], $reason, $actor);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                /* another admin blocked them a moment ago: the unique key */
                return $this->reply(409, ['ok' => false, 'message' => 'This customer is already blocked at ' . $office['name'] . '.']);
            }
            return $this->reply(200, ['ok' => true, 'message' => 'Blocked at ' . $office['name'] . '. They can still sign in, but cannot make new bookings there.']);
        }
        if (!BlockQueries::lift($customerId, $office['id'], $reason, $actor)) {
            return $this->reply(409, ['ok' => false, 'message' => 'This customer is not blocked at ' . $office['name'] . '.']);
        }
        return $this->reply(200, ['ok' => true, 'message' => 'Block lifted. They can book at ' . $office['name'] . ' again.']);
    }
}
