<?php

namespace App\Http\Controllers\Admin;

use App\Queries\AccountQueries;
use App\Queries\BlockQueries;
use App\Queries\CustomerQueries;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     fix_email, customer_id, email, reason: correct a counter account's wrong email
       (mayFixEmail(); kept in customer_email_fixes; the welcome email goes to the new address).
   ===================================================================== */
class CustomerManagementController
{
    public function index()
    {
        legacy('auth', 'offices');
        $scope = staff_venue_scope();   /* null = the super admin: every customer, every office */
        $offices = array_values(array_filter(office_all(), fn ($o) => staff_may_handle_venue($o['id'])));
        $cmRows = [];
        try {
            venusep_db_or_fail();
            $blocks = BlockQueries::activeByCustomer($scope);
            foreach (CustomerQueries::listForAdmin($scope) as $r) {
                $on = $blocks[(int) $r['customer_id']] ?? [];
                $cmRows[] = [
                    'userId'    => (int) $r['user_id'],
                    'id'        => (int) $r['customer_id'],
                    'name'      => (string) $r['full_name'],
                    'email'     => (string) $r['email'],
                    'phone'     => (string) ($r['phone'] ?? ''),
                    'usep'      => $r['university_id_no'] !== null && $r['university_id_no'] !== '' ? 'USeP' : '—',
                    'status'    => $r['is_active'] ? 'Active' : 'Suspended',
                    'bookings'  => (int) $r['booking_count'],
                    'open'      => (int) $r['open_count'],
                    'joined'    => $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '—',
                    'counter'   => $r['counter_venue_id'] !== null,
                    'lastLogin' => $r['last_login_at'] ? date('M j, Y', strtotime($r['last_login_at'])) : 'never',
                    'fixEmail'  => self::mayFixEmail($r['counter_venue_id'], $r['last_login_at'] === null, (bool) $r['not_google']),
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

        return live_view('admin.customer-management', ['cmRows' => $cmRows, 'cmMaySuspend' => admin_is_super()]);
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
        if ($action === 'fix_email') {
            return $this->fixEmail($request);
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

        $user = CustomerQueries::findUser($target);
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
                AccountQueries::setActive($target, $active);
            } catch (PDOException $e) {
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
            }
            return $this->reply(200, ['ok' => true, 'active' => $active,
                'message' => $active ? 'Account reactivated.' : 'Account suspended. They have been signed out and can no longer sign in.']);
        }

        return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    /* THE ONE EDIT OF A CUSTOMER'S DETAILS (DB-DECISIONS 28): a normal admin of the group
       whose counter made the account (USeP CEDU: either counter) may correct its email, while
       it has never been signed into and is not a Google account. Nobody else, the super admin
       included: only the customer edits their own details. */
    private static function mayFixEmail($counterVenueId, bool $neverSignedIn, bool $notGoogle): bool
    {
        return $counterVenueId !== null && $neverSignedIn && $notGoogle
            && !admin_is_super() && staff_may_handle_venue($counterVenueId);
    }

    /* Correct a counter account's wrong email: check, then save. The welcome email goes to the new address. */
    private function fixEmail(Request $request)
    {
        legacy('receipt-emails');
        // Check
        $account = CustomerQueries::account((int) $request->input('customer_id', 0));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 500);
        if ($account === null) {
            return $this->reply(404, ['ok' => false, 'message' => 'That customer account no longer exists.']);
        }
        if (!self::mayFixEmail($account['counter_venue_id'], $account['last_login_at'] === null, $account['google_sub'] === null)) {
            return $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only the customer can change this email. An admin of the group whose counter made the account may correct it, and only before anyone has signed into it and when it is not a Google account.']);
        }
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->reply(400, ['ok' => false, 'message' => 'That email address doesn’t look right.']);
        }
        if ($email === $account['email']) {
            return $this->reply(400, ['ok' => false, 'message' => 'That is the email the account already has.']);
        }
        if (CustomerQueries::emailTaken($email)) {
            return $this->reply(409, ['ok' => false, 'message' => 'Another account already has that email.']);
        }
        if ($reason === '') {
            return $this->reply(400, ['ok' => false, 'message' => 'Write the reason. It is kept with the correction.']);
        }

        // Save
        $pdo = venusep_db_or_fail();
        try {
            $outboxId = DB::transaction(function () use ($account, $email, $reason) {
                if (!CustomerQueries::fixEmail($account, $email, $reason, (int) session('user_id'))) {
                    return null;
                }
                return queue_counter_welcome($account['full_name'], $email, office_by_id($account['counter_venue_id']), (int) session('user_id'));
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            return $this->reply(409, ['ok' => false, 'message' => 'Another account already has that email.']);
        }
        if ($outboxId === null) {
            return $this->reply(409, ['ok' => false, 'message' => 'The account changed a moment ago (signed into, or its email changed), so the email was not corrected.']);
        }
        mail_send($outboxId);   // never throws
        return $this->reply(200, ['ok' => true, 'message' => 'Email corrected. A welcome email went to ' . $email . '.']);
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
