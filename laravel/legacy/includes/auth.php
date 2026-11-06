<?php
/* =====================================================================
   AUTH — Laravel version of the old includes/auth.php.

   Same function names and the same rules, so the legacy includes that call
   them (customer-bookings.php, customer-nav.php, notification-bell.php, ...)
   run unchanged. What moved:
     · the session is Laravel's (session('user_id') ...), never $_SESSION;
     · the page guards are route middleware (app/Http/Middleware) — the
       *_require_* functions below remain for legacy callers and throw a
       redirect instead of header()+exit;
     · csrf_token() is Laravel's own helper (same job, same session-bound
       token), so it is NOT redefined here; csrf_valid() checks against it.

   WHO GETS IN (agreed 2026-09-16) — unchanged:
     not logged in -> staff sign-in · customer session -> staff sign-in
     staff -> admin pages, not the refund switch · admin -> everything
   ===================================================================== */

use Illuminate\Http\Exceptions\HttpResponseException;

require_once __DIR__ . '/two-factor.php';   /* tfa_required_for(), tfa_status() */
require_once __DIR__ . '/db.php';

/* The old function started the PHP session and applied the 2FA gate. Laravel
   starts the session itself and the gate is the EnforceTwoFactor middleware,
   so this is a no-op kept for callers. */
function venusep_session_start()
{
}

/* True the first time $key is seen in THIS request (a static would last the
   whole PHP process — many requests in a test run). */
function venusep_once_per_request(string $key): bool
{
    $attr = 'venusep_once:' . $key;
    if (request()->attributes->has($attr)) {
        return false;
    }
    request()->attributes->set($attr, true);
    return true;
}

function venusep_redirect_now(string $url)
{
    throw new HttpResponseException(redirect($url));
}

/* Is the session a staff-side one (admin or staff)? */
function venusep_is_staff_session(): bool
{
    return session()->has('user_id') && in_array(session('account_type'), ['admin', 'staff'], true);
}

function admin_require_login(array $allowed = ['admin', 'staff'])
{
    if (!venusep_is_staff_session()) {
        venusep_redirect_now(route('admin.admin-login'));
    }
    /* 'super' = the super admin only (DB-DECISIONS 28) */
    if (!in_array(session('account_type'), $allowed, true) && !(in_array('super', $allowed, true) && admin_is_super())) {
        venusep_redirect_now(route('admin.dashboard'));   // staff on an admin-only page
    }
    staff_session_heal();
}

/* =====================================================================
   A SUSPENDED ACCOUNT LOSES ITS SESSION; a demoted admin stops being one
   on the next click; 2FA switched off in the database (sp_reset_2fa) ends
   a 2FA-required session. Checked once per request. Redirects (throws).
   ===================================================================== */
function staff_session_heal()
{
    $uid = (int) session('user_id');
    if (!venusep_once_per_request(__FUNCTION__ . ':' . $uid)) {
        return;
    }

    staff_session_refresh();          // suspended -> signed out; demoted -> the new account_type
    if (!venusep_is_staff_session()) {
        venusep_redirect_now(route('admin.admin-login') . '?suspended=1');
    }

    $pdo = venusep_db();
    if ($pdo === null) {
        return;                       // offline: do not log anyone out over a hiccup
    }
    if (tfa_required_for((string) session('account_type'))) {
        try {
            $on = tfa_status($pdo, $uid)['enabled'];
        } catch (PDOException $e) {
            sql_failed($e);
            $on = true;   // cannot tell: leave it to the sign-in page
        }
        if (!$on) {
            venusep_logout();
            venusep_redirect_now(route('admin.admin-login') . '?tfa=required');
        }
    }
}

/* =====================================================================
   EVERY REQUEST of a staff-side session (the EnforceTwoFactor middleware):
   a suspended or removed account loses its session at once, and a demoted
   admin stops being one. staff_session_heal() above only runs on pages
   behind the `staff` guard (and calls this); this also covers the JSON endpoints a page that
   is still open keeps calling, and the counter booking pages. No redirect:
   the page guard or the endpoint's own "session ended" reply takes over, and
   the sign-in page says why (the flag below).
   ===================================================================== */
function staff_session_refresh(): void
{
    if (!venusep_is_staff_session() || !venusep_once_per_request(__FUNCTION__ . ':' . (int) session('user_id'))) {
        return;
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        return;                       // offline: do not log anyone out over a hiccup
    }
    try {
        $row = \App\Queries\AccountQueries::findStaffSession((int) session('user_id'));
    } catch (PDOException $e) {
        sql_failed($e);
        return;
    }
    if (!$row || !(bool) $row['is_active'] || !in_array($row['account_type'], ['admin', 'staff'], true)) {
        venusep_logout();
        session(['staff_suspended_notice' => true]);
        return;
    }
    /* Signed in before the password last changed: a stolen or forgotten session ends here,
       like customer_session_heal(). (profile-save moves auth_at for the session that changed it.) */
    if (session()->has('auth_at') && (int) $row['changed_ts'] > (int) session('auth_at')) {
        venusep_logout();
        return;
    }
    session(['account_type' => $row['account_type'],
             /* the admin typed this staff member's password: they must set their own (Aron 2026-10-31) */
             'must_change_password' => $row['account_type'] === 'staff' && (bool) $row['must_change']]);
}

function admin_is_admin()
{
    return session()->has('user_id') && session('account_type') === 'admin';
}

/* =====================================================================
   ROLES AND OFFICES (DB-DECISIONS 28, decided 2026-11-04). Re-read every request.
     super admin (users.is_super_admin): every office;
     normal admin: its group's offices (users.office_group = venues.office_group),
       none while it has no group;
     staff: staff.venue_id, plus the office of any cover going on now (staff_covers,
       migration 18: it ends by itself, so it is read here on every request).
   Customers and guests are never narrowed: staff_list_venue() is null for them.

   INSIDE an office staff see and act on their HALLS only (Aron 2026-11-05, option B):
   their own halls and the halls they cover right now, the hostel being one unit. That is
   staff_me()['halls'] (fn_staff_on_room), and staff_may_handle_room() is the one check.
   The office list above stays for Reports (whole office) and the calendar's "Busy" days.
   ===================================================================== */
const OFFICE_GROUPS = ['bahay_alumni' => 'Bahay Alumni', 'cedu' => 'USeP CEDU'];   // users/venues.office_group

/* A cover going on right now, on staff_covers c (fn_staff_on_room() says the same in the DB). */
const STAFF_COVER_ON_SQL = 'c.approved_at IS NOT NULL AND c.ended_at IS NULL AND c.starts_at <= NOW() AND c.ends_at > NOW()';

function staff_me(): array
{
    $uid = (int) session('user_id');
    $key = 'venusep_staff_me:' . $uid;
    if (request()->attributes->has($key)) {
        return request()->attributes->get($key);
    }
    /* unknown = no office, no hall: refuse rather than allow. halls: room id => office id;
       null = not narrowed below the office (admins, customers, guests). */
    $me = ['super' => false, 'offices' => [], 'halls' => session('account_type') === 'staff' ? [] : null];
    if ($uid > 0 && venusep_db() !== null && venusep_is_staff_session()) {
        try {
            $me['super'] = \App\Queries\StaffHallQueries::isSuperAdmin($uid);
            $me['offices'] = \App\Queries\StaffHallQueries::officeIdsOf($uid);
            if ($me['halls'] !== null) {
                $me['halls'] = \App\Queries\StaffHallQueries::roomsOf($uid);
            }
        } catch (PDOException $e) {
            sql_failed($e);
        }
    }
    request()->attributes->set($key, $me);
    return $me;
}

function admin_is_super(): bool
{
    return admin_is_admin() && staff_me()['super'];
}

/* null = every office (the super admin); otherwise the office (venue) ids this
   session handles, [] = none. */
function staff_venue_scope()
{
    return admin_is_super() ? null : staff_me()['offices'];
}

function staff_may_handle_venue($venueId)
{
    $scope = staff_venue_scope();
    return $scope === null || in_array((int) $venueId, $scope, true);
}

/* The offices a staff-side session's lists are narrowed to: null = no narrowing
   (the super admin, customers, guests); otherwise the office ids, [] = none. */
function staff_list_venue()
{
    return venusep_is_staff_session() ? staff_venue_scope() : null;
}

/* Does a list shown to this session include the office? Venue/room lists, GCash
   cards, the counter's rooms. */
function staff_sees_venue($venueId)
{
    $offices = staff_list_venue();
    return $offices === null || in_array((int) $venueId, $offices, true);
}

/* Does this session see more than one office? Its booking lists then label each row. */
function staff_sees_many_offices(): bool
{
    $offices = staff_list_venue();
    return $offices === null || count($offices) > 1;
}

/* The same rule inside SQL: " $glue $sql" with the office ids in $sql's %s
   (write it as "x IN (%s)"), or '' for anyone not narrowed. */
function staff_venue_sql($sql, $glue = 'WHERE')
{
    $offices = staff_list_venue();
    return $offices === null ? '' : " $glue " . sprintf($sql, implode(',', $offices ?: [0]));
}

/* THE ONE CHECK: may this session see / act on this room, and so on its bookings? Staff:
   one of their halls, the hostel, or a cover going on now. Admins: the room's office. */
function staff_may_handle_room($roomId, $venueId)
{
    $halls = staff_me()['halls'];
    return $halls === null ? staff_may_handle_venue($venueId) : isset($halls[(int) $roomId]);
}

/* The same check from a room code (the counter's pages). An unknown room is refused. */
function staff_may_handle_room_code($roomCode)
{
    if (venusep_db() === null || !is_string($roomCode)) {
        return false;
    }
    $room = \App\Queries\RoomQueries::findIdAndOffice($roomCode);
    return $room !== null && staff_may_handle_room($room['id'], $room['venue_id']);
}

/* Does a list shown to this session include the room? Customers and guests: always. */
function staff_sees_room($roomId, $venueId)
{
    return !venusep_is_staff_session() || staff_may_handle_room($roomId, $venueId);
}

/* An office's own details (name, description, photo): its group's admins and the super
   admin only. Staff never, whatever halls or covers they hold (DB-DECISIONS 28). */
function admin_may_edit_office($venueId): bool
{
    return admin_is_admin() && staff_may_handle_venue($venueId);
}

/* The staff member's room ids; null = not narrowed below the office. */
function staff_hall_ids()
{
    $halls = staff_me()['halls'];
    return $halls === null ? null : array_keys($halls);
}

/* Does this office hold a hall of this session? Everyone else's lists: yes. */
function staff_has_hall_in($venueId)
{
    $halls = staff_me()['halls'];
    return $halls === null || in_array((int) $venueId, $halls, true);
}

const STAFF_NO_HALLS_NOTE = 'You have no halls yet. Ask your admin to assign you one.';

function staff_has_no_halls(): bool
{
    return staff_me()['halls'] === [];
}

/* The hall rule inside SQL, added after staff_venue_sql(): " AND $column IN (...)" for
   staff, '' for anyone else. */
function staff_hall_sql($column)
{
    $ids = staff_hall_ids();
    return $ids === null ? '' : " AND $column IN (" . (implode(',', $ids) ?: '0') . ')';
}

/* The same for an office column: only offices holding one of the staff member's halls. */
function staff_hall_office_sql($column)
{
    $halls = staff_me()['halls'];
    return $halls === null ? '' : " AND $column IN (" . (implode(',', array_unique($halls)) ?: '0') . ')';
}

/* The bell (notifications n): staff keep news of their halls only, so a hall taken away or a
   cover that ended takes its news with it. News about no booking stays. */
function staff_news_sql()
{
    return staff_me()['halls'] === null ? ''
        : ' AND (n.booking_id IS NULL OR n.booking_id IN (SELECT b2.id FROM bookings b2 WHERE 1' . staff_hall_sql('b2.room_id') . '))';
}

/* May this session see / handle the booking? Customers: yes (their own checks
   apply). An unknown booking passes: the caller's 404 handles it. */
function staff_may_handle_booking($bookingId)
{
    if (staff_list_venue() === null) {
        return true;
    }
    if (venusep_db() === null) {
        return false;
    }
    $room = \App\Queries\RoomQueries::findRoomOfBooking((int) $bookingId);
    return $room === null || staff_may_handle_room($room['id'], $room['venue_id']);
}

/* May this admin manage (edit, move, reset the password of, suspend) that
   staff-side account? $acct: account_type, venue_id, is_super_admin, handed_over_at.
   Super admin: any admin or staff. Normal admin: staff of its own offices only,
   never another admin. Nobody manages the super admin or a former one.
   (Your own account is refused by the caller.) */
function staff_may_manage_account(array $acct): bool
{
    if (!admin_is_admin() || !empty($acct['is_super_admin']) || !empty($acct['handed_over_at'])) {
        return false;
    }
    if (admin_is_super()) {
        return true;
    }
    return $acct['account_type'] === 'staff' && $acct['venue_id'] !== null && staff_may_handle_venue($acct['venue_id']);
}

/* =====================================================================
   "TYPE YOUR PASSWORD AGAIN" — the one lockout every sensitive action shares
   (refund switch, GCash accounts, backup restore, creating an
   admin, the counter's customer check). Stored on users.reauth_*, so another
   browser does not start over: 5 wrong in a row = one lock, each lock longer
   than the last (10s, 30s, 1m, 5m, then 15m). A right password resets it.

   $user: the account row, read FOR UPDATE inside the caller's transaction
   (two wrong tries at the same moment must both count), with id,
   password_hash, reauth_failed_attempts, reauth_lock_level and lock_seconds
   (CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(...) ELSE 0 END).
   Returns null = right password, the transaction stays open; otherwise the
   transaction is already closed (a wrong try is committed so it counts) and
   ['locked' => seconds, 'justLocked' => bool] or ['attemptsLeft' => n].
   ===================================================================== */
function reauth_verify(PDO $pdo, array $user, string $password): ?array
{
    if ((int) $user['lock_seconds'] > 0) {
        $pdo->rollBack();
        return ['locked' => (int) $user['lock_seconds'], 'justLocked' => false];
    }
    if (password_verify($password, (string) $user['password_hash'])) {
        \App\Queries\AccountQueries::clearPasswordTries((int) $user['id']);
        return null;
    }
    $attempts = (int) $user['reauth_failed_attempts'] + 1;
    if ($attempts < 5) {
        \App\Queries\AccountQueries::countPasswordTry((int) $user['id'], $attempts);
        $pdo->commit();
        return ['attemptsLeft' => 5 - $attempts];
    }
    $ladder = [10, 30, 60, 300, 900];
    $level = min((int) $user['reauth_lock_level'] + 1, 255);
    $seconds = $ladder[min($level, count($ladder)) - 1];
    \App\Queries\AccountQueries::lockPasswordTries((int) $user['id'], $level, $seconds);
    $pdo->commit();
    return ['locked' => $seconds, 'justLocked' => true];
}

/* ---- CUSTOMER SIDE — a staff session never counts as a customer. ---- */
function customer_logged_in()
{
    if (!session()->has('user_id') || !session()->has('customer_id') || session('account_type') !== 'customer') {
        /* "Remember me" (#23): no session, but this device was remembered —
           open one from the cookie. Never over someone else's session. */
        if (!session()->has('user_id') && request()->cookies->has('venusep_remember')) {
            require_once __DIR__ . '/remember-me.php';
            $pdo = venusep_db();
            if ($pdo !== null && function_exists('remember_restore') && remember_restore($pdo)) {
                customer_session_heal();
                return session()->has('user_id') && session()->has('customer_id');
            }
        }
        return false;
    }
    customer_session_heal();
    return session()->has('user_id') && session()->has('customer_id') && session('account_type') === 'customer';
}

/* The session's customer must still be a real, ACTIVE row, and its password
   must not have changed since this session signed in (#23). Ends the session
   (no redirect) when either fails; callers decide what to show. */
function customer_session_heal()
{
    $uid = (int) session('user_id');
    if (!venusep_once_per_request(__FUNCTION__ . ':' . $uid)) {
        return;
    }

    $pdo = venusep_db();
    if ($pdo === null) {
        return;
    }
    try {
        $row = \App\Queries\AccountQueries::findCustomerOfUser($uid);
        if ($row !== null && (bool) $row['is_active']) {
            session(['customer_id' => (int) $row['id']]);
        } else {
            venusep_logout();
            /* disabled by an admin: the sign-in page says so once (Bug 2) — the
               customer side's ?suspended=1, kept in the new session instead */
            if ($row !== null) {
                session(['customer_disabled_notice' => true]);
            }
            return;
        }
    } catch (PDOException $e) {
        sql_failed($e);
        // leave the session as it is
    }

    try {
        $changed = \App\Queries\AccountQueries::passwordChangedAt($uid);
        if ($changed !== null && $changed > (int) session('auth_at', 0)) {
            venusep_logout();
        }
    } catch (PDOException $e) {
        sql_failed($e);
        // migration 04 not run yet: nothing to compare against
    }
}

/* =====================================================================
   COUNTER MODE — the booking pages, driven by staff, for a walk-in.
   The privilege comes from the SESSION, never the URL: ?counter=1 only
   expresses intent and is ignored unless a staff session is present.
   ===================================================================== */
function counter_mode()
{
    if (empty(request()->query('counter'))) {
        return false;
    }
    return venusep_is_staff_session();
}

function counter_staff_name()
{
    if (!counter_mode()) {
        return '';
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        return 'Staff';
    }
    try {
        return \App\Queries\VenueQueries::displayName((int) session('user_id')) ?? 'Staff';
    } catch (PDOException $e) {
        sql_failed($e);
        return 'Staff';
    }
}

function booking_page_require_access()
{
    if (counter_mode()) {
        return;
    }
    customer_require_login();
}

/* Not logged in as a customer -> the customer login page (never ?next=). */
function customer_require_login()
{
    if (!customer_logged_in()) {
        venusep_redirect_now(route('customer.customer-login'));
    }
}

/* Ends the session completely: the data, the cookie id, and the CSRF token. */
function venusep_logout()
{
    session()->invalidate();
    session()->regenerateToken();
}

function csrf_valid($token)
{
    return is_string($token) && $token !== '' && hash_equals((string) session()->token(), $token);
}
