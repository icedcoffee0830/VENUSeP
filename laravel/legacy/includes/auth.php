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
    if (!in_array(session('account_type'), $allowed, true)) {
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
        $stmt = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :u');
        $stmt->execute([':u' => (int) session('user_id')]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return;
    }
    if (!$row || !(bool) $row['is_active'] || !in_array($row['account_type'], ['admin', 'staff'], true)) {
        venusep_logout();
        session(['staff_suspended_notice' => true]);
        return;
    }
    session(['account_type' => $row['account_type']]);
}

function admin_is_admin()
{
    return session()->has('user_id') && session('account_type') === 'admin';
}

/* =====================================================================
   WHICH VENUE A STAFF MEMBER HANDLES (decided 2026-10-27).
   null = admin (every venue); the staff member's venue id; 0 = unknown
   (matches nothing — refuse rather than allow). Re-read every request.
   ===================================================================== */
function staff_venue_scope()
{
    if (session('account_type') === 'admin') {
        return null;
    }
    $uid = session('user_id');
    if ($uid === null) {
        return 0;
    }
    $key = 'venusep_venue_scope:' . $uid;
    if (request()->attributes->has($key)) {
        return request()->attributes->get($key);
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        request()->attributes->set($key, 0);
        return 0;
    }
    $st = $pdo->prepare('SELECT s.venue_id FROM staff s JOIN users u ON u.id = s.user_id
                          WHERE s.user_id = :u AND u.account_type = \'staff\'');
    $st->execute([':u' => (int) $uid]);
    $venue = $st->fetchColumn();
    $scope = ($venue === false || $venue === null) ? 0 : (int) $venue;
    request()->attributes->set($key, $scope);
    return $scope;
}

function staff_may_handle_venue($venueId)
{
    $scope = staff_venue_scope();
    return $scope === null || ($scope !== 0 && $scope === (int) $venueId);
}

/* The venue STAFF lists are narrowed to (decided 2026-10-31: the three
   departments never see each other's bookings): null = no narrowing (admins,
   customers); a staff member's venue id; 0 = staff with no venue (sees nothing). */
function staff_list_venue()
{
    return session('account_type') === 'staff' ? staff_venue_scope() : null;
}

/* May this staff session see / handle the booking? Admins and non-staff: yes
   (their own checks apply). An unknown booking passes: the caller's 404 handles it. */
function staff_may_handle_booking($bookingId)
{
    if (staff_list_venue() === null) {
        return true;
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        return false;
    }
    $st = $pdo->prepare('SELECT r.venue_id FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = :b');
    $st->execute([':b' => (int) $bookingId]);
    $venue = $st->fetchColumn();
    return $venue === false || staff_may_handle_venue($venue);
}

/* =====================================================================
   "TYPE YOUR PASSWORD AGAIN" — the one lockout every sensitive action shares
   (refund switch, demo mode, GCash accounts, backup restore, creating an
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
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')
            ->execute([':id' => $user['id']]);
        return null;
    }
    $attempts = (int) $user['reauth_failed_attempts'] + 1;
    if ($attempts < 5) {
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')
            ->execute([':a' => $attempts, ':id' => $user['id']]);
        $pdo->commit();
        return ['attemptsLeft' => 5 - $attempts];
    }
    $ladder = [10, 30, 60, 300, 900];
    $level = min((int) $user['reauth_lock_level'] + 1, 255);
    $seconds = $ladder[min($level, count($ladder)) - 1];
    $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = :l,
                          reauth_locked_until = DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE id = :id')
        ->execute([':l' => $level, ':s' => $seconds, ':id' => $user['id']]);
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
        $stmt = $pdo->prepare(
            'SELECT c.id, u.is_active FROM customers c JOIN users u ON u.id = c.user_id WHERE c.user_id = :u LIMIT 1'
        );
        $stmt->execute([':u' => $uid]);
        $row = $stmt->fetch();
        if ($row !== false && (bool) $row['is_active']) {
            session(['customer_id' => (int) $row['id']]);
        } else {
            venusep_logout();
            /* disabled by an admin: the sign-in page says so once (Bug 2) — the
               customer side's ?suspended=1, kept in the new session instead */
            if ($row !== false) {
                session(['customer_disabled_notice' => true]);
            }
            return;
        }
    } catch (PDOException $e) {
        // leave the session as it is
    }

    try {
        $stmt = $pdo->prepare('SELECT UNIX_TIMESTAMP(password_changed_at) FROM users WHERE id = :u');
        $stmt->execute([':u' => $uid]);
        $changed = $stmt->fetchColumn();
        if ($changed !== false && $changed !== null && (int) $changed > (int) session('auth_at', 0)) {
            venusep_logout();
        }
    } catch (PDOException $e) {
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
        $stmt = $pdo->prepare(
            'SELECT COALESCE(s.full_name, u.username, u.email) AS who
               FROM users u LEFT JOIN staff s ON s.user_id = u.id
              WHERE u.id = :u LIMIT 1'
        );
        $stmt->execute([':u' => (int) session('user_id')]);
        $who = $stmt->fetchColumn();
        return $who === false ? 'Staff' : (string) $who;
    } catch (PDOException $e) {
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
