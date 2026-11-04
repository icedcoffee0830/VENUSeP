<?php

namespace App\Http\Controllers\Admin;

use App\Queries\AccountQueries;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDO;
use PDOException;

/* =====================================================================
   STAFF MANAGEMENT — ported from admin/venusep_staffManagement.php,
   admin/staff-save.php and admin/admin-register.php. ADMIN ONLY throughout.

   THE ROSTER: the real staff-side accounts (users + staff). Active /
   Suspended map onto users.is_active, the only account state there is.

   STAFF SAVE — an ADMIN editing SOMEBODY ELSE'S account. JSON.
     POST action=update|set_venue|set_active|reset_password|handover, user_id
       update:     full_name, email, employee_no, position_role, phone[, venue_id]
       set_venue:  venue_id (required for a staff account — no unassigned staff;
                   the DB trigger trg_staff_venue_required says the same)[, both_cedu=0|1]
       set_active: active=0|1
       reset_password: new_password — never your own
       handover:   password, reason — the super admin to a normal admin
   · ADMIN ONLY, re-checked here — answered as JSON, not a redirect.
   · WHO MANAGES WHOM: staff_may_manage_account() (auth.php, DB-DECISIONS 28).
   · The target must be a STAFF-SIDE account.
   · SELF-LOCKOUT GUARDS: no suspending yourself, no suspending the LAST
     active admin.
   · NEVER DELETES. Deactivation only.
   Suspension takes effect on the target's next click: staff_session_heal()
   re-reads users.is_active and account_type on every request.

   ADMIN REGISTER — the form; it posts to register-submit (auth area).
   ===================================================================== */
class StaffController
{
    public function index()
    {
        legacy('db', 'auth');
        $smRows = [];
        $smVenues = [];
        try {
            $smPdo = venusep_db_or_fail();
            /* the offices this admin may move staff to (DB-DECISIONS 28) */
            foreach ($smPdo->query('SELECT id, name FROM venues ORDER BY id') as $venue) {
                if (staff_may_handle_venue($venue['id'])) {
                    $smVenues[] = ['id' => (int) $venue['id'], 'name' => (string) $venue['name']];
                }
            }
            $smStmt = $smPdo->query(
                "SELECT u.id, u.email, u.account_type, u.is_active, u.last_login_at,
                        u.is_super_admin, u.office_group, u.handed_over_at,
                        COALESCE(s.full_name, u.username) AS full_name,
                        s.employee_no, s.position_role, s.phone, s.venue_id,
                        v.name AS venue_name, v.office_group AS venue_group,
                        s.both_cedu_offices = 1 AND v.office_group = 'cedu' AS both_cedu,
                        (SELECT GROUP_CONCAT(o.name ORDER BY o.id SEPARATOR ' and ') FROM venues o WHERE o.office_group = 'cedu') AS cedu_names
                   FROM users u
                   LEFT JOIN staff s ON s.user_id = u.id
                   LEFT JOIN venues v ON v.id = s.venue_id
                  WHERE u.account_type IN ('admin', 'staff')
                  ORDER BY u.account_type, COALESCE(s.full_name, u.username)"
            );
            foreach ($smStmt as $r) {
                $isSelf = (int) $r['id'] === (int) session('user_id');
                $canManage = !$isSelf && staff_may_manage_account($r);
                if (!$isSelf && !$canManage && !admin_is_super()) {
                    continue;   // a normal admin sees its own offices' staff only
                }
                $role = $r['is_super_admin'] ? 'Super admin' : ($r['account_type'] === 'admin' ? 'Admin' : 'Staff');
                $smRows[] = [
                    'id'       => (int) $r['id'],
                    'name'     => (string) $r['full_name'],
                    'email'    => (string) $r['email'],
                    'role'     => $role,
                    'status'   => $r['is_active'] ? 'Active' : 'Suspended',
                    'employee' => (string) $r['employee_no'],
                    'position' => (string) $r['position_role'],
                    'phone'    => (string) $r['phone'],
                    'venueId'  => $r['venue_id'] === null ? '' : (string) (int) $r['venue_id'],
                    'venue'    => match (true) {   // the office label
                        $role === 'Super admin'   => 'All offices',
                        $role === 'Admin'         => OFFICE_GROUPS[$r['office_group']] ?? 'No group yet',
                        (bool) $r['both_cedu']    => (string) $r['cedu_names'],
                        default                   => $r['venue_name'] === null ? 'Choose a venue' : (string) $r['venue_name'],
                    },
                    'both'     => (bool) $r['both_cedu'],
                    'lastLogin'=> $r['last_login_at'] ? date('M j, Y', strtotime($r['last_login_at'])) : 'never',
                    'isSelf'   => $isSelf,
                    'canManage'=> $canManage,
                    'canBoth'  => $canManage && $role === 'Staff' && $r['venue_group'] === 'cedu',
                    'canHandOver' => admin_is_super() && $role === 'Admin' && $r['is_active'] && $r['handed_over_at'] === null,
                ];
            }
        } catch (PDOException $e) {
            sql_failed($e);
            $smRows = [];
            $smVenues = [];
        }

        return view('admin.staff-management', ['smRows' => $smRows, 'smVenues' => $smVenues]);
    }

    public function register()
    {
        extract(legacy('venues'));   // $venuesById: only the offices this admin handles

        return view('admin.admin-register', ['venuesById' => $venuesById, 'canCreateAdmin' => admin_is_super()]);
    }

    /* ---------------------------------------------------------------- save */

    private function reply(int $status, array $data)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }

    /* The old ss_reply() exited mid-function; here a deep check throws the reply. */
    private function fail(int $status, array $data)
    {
        throw new HttpResponseException($this->reply($status, $data));
    }

    /* Tell the admins who look after that account (2026-10-28; DB-DECISIONS 28):
       $venueIds = the staff member's offices, null = an admin account (the super admin).
       A failure costs a notification, never the change itself. */
    private function notify(PDO $pdo, $kind, $actor, array $detail, ?array $venueIds): void
    {
        try {
            notify_account_event($pdo, $kind, (int) $actor, $detail, $venueIds);
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
        }
    }

    /* Whose admins hear of an event on this account: a staff member's office, or null for an admin. */
    private function offices(array $user): ?array
    {
        return $user['account_type'] === 'staff' ? [(int) $user['venue_id']] : null;
    }

    /* $required: a STAFF account must have a venue (decided 2026-10-27). Only
       an admin row may be left without one. */
    private function resolveVenue(PDO $pdo, $raw, $required = true): array
    {
        if ($raw !== null && !is_scalar($raw)) {
            $this->fail(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Select a valid venue.']);
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            if ($required) {
                $this->fail(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Every staff member must handle a venue. Choose one.']);
            }
            return ['id' => null, 'name' => 'Unassigned'];
        }
        if (!ctype_digit($raw) || (int) $raw < 1) {
            $this->fail(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Select a valid venue.']);
        }
        $stmt = $pdo->prepare('SELECT id, name, office_group FROM venues WHERE id = :id');
        $stmt->execute([':id' => (int) $raw]);
        $venue = $stmt->fetch();
        if (!$venue) {
            $this->fail(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue does not exist.']);
        }
        /* Move staff: the super admin anywhere, a normal admin inside its own offices (DB-DECISIONS 28) */
        if (!staff_may_handle_venue($venue['id'])) {
            $this->fail(403, ['ok' => false, 'field' => 'venue_id', 'message' => 'You can only assign staff to your own offices.']);
        }
        return ['id' => (int) $venue['id'], 'name' => (string) $venue['name'], 'cedu' => $venue['office_group'] === 'cedu'];
    }

    /* The route skips Laravel's CSRF middleware so an expired page still gets
       the old 400 JSON; the token is checked here (old field `csrf`,
       Laravel's `_token`, or the X-CSRF-TOKEN header). */
    public function save(Request $request)
    {
        legacy('auth', 'bookings', 'notifications');   /* cb_normalise_mobile(), notify_account_event() */

        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'POST');
        }
        if (!admin_is_admin()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can manage staff accounts.']);
        }
        $token = $request->input('csrf', $request->input('_token', $request->header('X-CSRF-TOKEN')));
        if (!csrf_valid($token)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $actor  = (int) session('user_id');
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

        /* The SESSION says admin; the DATABASE decides. A suspended (or demoted) admin's
           open session must not keep managing staff until some page throws them out. */
        $me = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :u');
        $me->execute([':u' => $actor]);
        $me = $me->fetch();
        if (!$me || !(bool) $me['is_active'] || $me['account_type'] !== 'admin') {
            return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can manage staff accounts.']);
        }

        $row = $pdo->prepare(
            'SELECT u.id, u.email, u.account_type, u.is_active, u.is_super_admin, u.handed_over_at,
                    s.user_id AS staff_user_id, s.venue_id AS old_venue_id, s.venue_id, s.full_name
               FROM users u
               LEFT JOIN staff s ON s.user_id = u.id
              WHERE u.id = :u'
        );
        $row->execute([':u' => $target]);
        $user = $row->fetch();
        if (!$user) {
            return $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
        }
        /* Staff-side only. A customer's row is edited by the customer, or not at all. */
        if (!in_array($user['account_type'], ['admin', 'staff'], true)) {
            return $this->reply(403, ['ok' => false, 'message' => 'That account is not a staff account.']);
        }
        /* Who manages whom (DB-DECISIONS 28): the super admin any admin or staff; a normal
           admin the staff of its own offices, never another admin. Your own row: update only. */
        if ($target !== $actor && !staff_may_manage_account($user)) {
            return $this->reply(403, ['ok' => false, 'message' => 'You cannot manage that account.']);
        }
        if ($action === 'handover') {
            return $this->handOver($request, $pdo, $actor, $user);
        }

        /* VENUE ASSIGNMENT — which venue's bookings this staff member handles.
           Required: there are no unassigned staff. */
        if ($action === 'set_venue') {
            if ($user['account_type'] !== 'staff') {
                return $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Venue assignments apply to staff accounts.']);
            }
            if ($user['staff_user_id'] === null) {
                return $this->reply(409, ['ok' => false, 'message' => 'That staff account has no staff profile to update.']);
            }
            $venue = $this->resolveVenue($pdo, $request->input('venue_id', ''));
            /* both_cedu = ticked for USeP Venues and USeP Hostel: only inside CEDU; not sent = unchanged */
            $both = $request->has('both_cedu') ? (int) !empty($request->input('both_cedu')) : null;
            try {
                $pdo->prepare('UPDATE staff SET venue_id = :v, both_cedu_offices = IF(:c, COALESCE(:b, both_cedu_offices), 0) WHERE user_id = :u')
                    ->execute([':v' => $venue['id'], ':c' => (int) $venue['cedu'], ':b' => $both, ':u' => $target]);
            } catch (PDOException $e) {
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so the venue was not changed.']);
            }
            if ((int) $user['old_venue_id'] !== (int) $venue['id']) {
                $this->notify($pdo, 'staff_moved', $actor, ['email' => $user['email'], 'venue' => $venue['name']], [(int) $user['old_venue_id'], $venue['id']]);
            }
            return $this->reply(200, ['ok' => true, 'venue_id' => $venue['id'], 'venue' => $venue['name'],
                'message' => 'Venue assignment updated.']);
        }

        /* UPDATE — name, contact, and the two fields the ORGANISATION owns
           (employee number and role). */
        if ($action === 'update') {
            $name  = trim((string) $request->input('full_name', ''));
            $email = strtolower(trim((string) $request->input('email', '')));
            $phone = trim((string) $request->input('phone', ''));
            $emp   = trim((string) $request->input('employee_no', ''));
            $role  = trim((string) $request->input('position_role', ''));
            $venueGiven = $request->request->has('venue_id');
            $venue = null;

            if ($name === '' || mb_strlen($name) > 190) {
                return $this->reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter a full name.']);
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {   /* users.email varchar(190), as register-submit */
                return $this->reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
            }
            $phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
            if ($phone !== '' && $phoneNorm === null) {
                return $this->reply(400, ['ok' => false, 'field' => 'phone', 'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
            }
            if ($venueGiven) {
                $venueRaw = $request->request->all()['venue_id'];
                if ($user['account_type'] !== 'staff' && $venueRaw !== '' && $venueRaw !== null) {
                    return $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Venue assignments apply to staff accounts.']);
                }
                $venue = $this->resolveVenue($pdo, $venueRaw, $user['account_type'] === 'staff');
            }

            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET email = :e WHERE id = :u')->execute([':e' => $email, ':u' => $target]);
                $staffValues = [
                    ':u' => $target, ':n' => $name,
                    ':e' => $emp !== '' ? mb_substr($emp, 0, 80) : null,
                    ':r' => $role !== '' ? mb_substr($role, 0, 120) : null,
                    ':p' => $phoneNorm,
                ];
                if ($venueGiven) {
                    $staffValues[':v'] = $venue['id'];
                    $pdo->prepare(
                        'INSERT INTO staff (user_id, venue_id, full_name, employee_no, position_role, phone)
                         VALUES (:u, :v, :n, :e, :r, :p)
                         ON DUPLICATE KEY UPDATE venue_id = VALUES(venue_id), full_name = VALUES(full_name),
                                                 employee_no = VALUES(employee_no), position_role = VALUES(position_role),
                                                 phone = VALUES(phone)'
                    )->execute($staffValues);
                } else {
                    $pdo->prepare(
                        'INSERT INTO staff (user_id, full_name, employee_no, position_role, phone)
                         VALUES (:u, :n, :e, :r, :p)
                         ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), employee_no = VALUES(employee_no),
                                                 position_role = VALUES(position_role), phone = VALUES(phone)'
                    )->execute($staffValues);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($e->getCode() === '23000') {
                    /* uq_users_email or uq_staff_employee_no — say which. */
                    $dupEmp = strpos($e->getMessage(), 'employee_no') !== false;
                    return $this->reply(409, ['ok' => false, 'field' => $dupEmp ? 'employee_no' : 'email',
                        'message' => $dupEmp
                            ? 'That employee number already belongs to another account.'
                            : 'That email address already belongs to another account.']);
                }
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was saved.']);
            }
            if ($venueGiven && $user['account_type'] === 'staff' && $venue['id'] !== null
                && (int) $user['old_venue_id'] !== (int) $venue['id']) {
                $this->notify($pdo, 'staff_moved', $actor, ['email' => $email, 'venue' => $venue['name']], [(int) $user['old_venue_id'], $venue['id']]);
            }
            return $this->reply(200, ['ok' => true, 'message' => 'Account updated.']);
        }

        /* RESET PASSWORD — one way back in for a staff member who forgot theirs
           (Aron 2026-10-31); Forgot password on the sign-in page is the other (DB-DECISIONS 28). The admin chooses a temporary
           password; password_changed_at goes back to NULL, so the staff member must replace
           it (EnforceTwoFactor). Who may reset whom: staff_may_manage_account() above; the
           super admin's own password is never reset here, it uses Forgot password. */
        if ($action === 'reset_password') {
            if ($target === $actor) {
                return $this->reply(403, ['ok' => false, 'message' => 'Change your own password on your Profile page.']);
            }
            $new = (string) $request->input('new_password', '');
            if (strlen($new) < 8) {
                return $this->reply(400, ['ok' => false, 'field' => 'new_password', 'message' => 'Use a password of at least 8 characters.']);
            }
            legacy('passwords');
            if (!venusep_password_ready()) {
                return $this->reply(500, ['ok' => false, 'message' => VENUSEP_PASSWORD_SETUP_ERROR]);
            }
            try {
                $pdo->prepare('UPDATE users SET password_hash = :h, password_changed_at = NULL, reauth_failed_attempts = 0,
                                      reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :u')
                    ->execute([':h' => venusep_password_hash($new), ':u' => $target]);
            } catch (PDOException $e) {
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so the password was not changed.']);
            }
            \App\Http\Controllers\Auth\SignInThrottle::passed((string) $user['email']);   // a forgotten password often means a sign-in lock too
            $this->notify($pdo, 'account_password_reset', $actor, ['email' => $user['email']], $this->offices($user));
            return $this->reply(200, ['ok' => true,
                'message' => 'Password reset. Give them the temporary password; they must replace it when they sign in.']);
        }

        /* SUSPEND / REACTIVATE */
        if ($action === 'set_active') {
            $active = !empty($request->input('active')) ? 1 : 0;

            if (!$active) {
                /* Suspending yourself: refused rather than confirmed — there is no
                   undo from the other side of it. */
                if ($target === $actor) {
                    return $this->reply(409, ['ok' => false,
                        'message' => 'You cannot suspend your own account. Ask another administrator to do it.']);
                }
                /* The last active admin. Counted, not assumed. */
                if ($user['account_type'] === 'admin') {
                    $left = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE account_type = 'admin' AND is_active = 1"
                    )->fetchColumn();
                    if ($left <= 1) {
                        return $this->reply(409, ['ok' => false,
                            'message' => 'This is the last active administrator. Suspending it would lock everyone out of the settings that only an administrator can change.']);
                    }
                }
            }

            try {
                $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :u')
                    ->execute([':a' => $active, ':u' => $target]);
            } catch (PDOException $e) {
                sql_failed($e);
                return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
            }
            if ((int) $user['is_active'] !== $active) {
                $this->notify($pdo, $active ? 'account_reactivated' : 'account_suspended', $actor,
                    ['email' => $user['email'], 'role' => $user['account_type']], $this->offices($user));
            }
            return $this->reply(200, ['ok' => true, 'active' => $active,
                'message' => $active ? 'Account reactivated.' : 'Account suspended. They can no longer sign in.']);
        }

        return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    /* HAND OVER THE SUPER ADMIN (DB-DECISIONS 28): the super admin picks an active normal
       admin, retypes their own password and writes a reason. In one step that admin becomes
       the super admin and the old one is suspended for good. Both are emailed. No undo. */
    private function handOver(Request $request, PDO $pdo, int $actor, array $user)
    {
        if (!admin_is_super()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Only the super admin can hand over the super admin.']);
        }
        if ($user['account_type'] !== 'admin' || (bool) $user['is_super_admin'] || !(bool) $user['is_active']) {
            return $this->reply(400, ['ok' => false, 'message' => 'Pick an active normal admin.']);
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            return $this->reply(400, ['ok' => false, 'field' => 'reason', 'message' => 'Write the reason (up to 500 characters).']);
        }
        $password = (string) $request->input('password', '');
        if ($password === '') {
            return $this->reply(400, ['ok' => false, 'error' => 'no_password', 'message' => 'Enter your password to confirm.']);
        }

        legacy('receipt-emails');
        try {
            $pdo->beginTransaction();
            $me = $pdo->prepare(
                'SELECT u.id, u.email, u.password_hash, u.account_type, u.is_active, u.is_super_admin,
                        u.reauth_failed_attempts, u.reauth_lock_level, COALESCE(s.full_name, u.username) AS full_name,
                        CASE WHEN u.reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until) ELSE 0 END AS lock_seconds
                   FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = :id FOR UPDATE'
            );
            $me->execute([':id' => $actor]);
            $me = $me->fetch();
            if (!$me || !(bool) $me['is_super_admin']) {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'message' => 'Only the super admin can hand over the super admin.']);
            }
            if ($no = reauth_verify($pdo, $me, $password)) {   // the shared lockout (auth.php)
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                    : $this->reply(401, ['ok' => false, 'error' => 'wrong_password',
                        'message' => 'Wrong password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
            }
            AccountQueries::handOverSuperAdmin($actor, (int) $user['id']);
            $outbox = queue_handover_emails($pdo,
                ['email' => (string) $me['email'], 'name' => (string) $me['full_name']],
                ['email' => (string) $user['email'], 'name' => (string) ($user['full_name'] ?? $user['email'])],
                $reason, $actor);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sql_failed($e);
            return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }
        foreach ($outbox as $id) {
            mail_send($pdo, $id);   // never throws
        }
        $this->notify($pdo, 'super_admin_handover', $actor, ['email' => $user['email']], null);   // the new super admin's bell
        return $this->reply(200, ['ok' => true, 'signed_out' => true,
            'message' => $user['email'] . ' is now the super admin. Your account is suspended, so you are signed out.']);
    }
}
