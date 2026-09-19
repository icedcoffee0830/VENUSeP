<?php
/* =====================================================================
   AUTH — session start, the admin page guard, logout, CSRF tokens.

   Admin pages put ONE line at the very top, before any output:
       <?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
   admin-register.php is stricter (admin only, staff are sent to the dashboard):
       admin_require_login(['admin']);

   WHO GETS IN (agreed 2026-09-16):
     not logged in   -> sent to admin-login.php
     customer        -> sent to admin-login.php (a customer session is not staff)
     staff           -> allowed on admin pages, but cannot change the refund switch
     admin           -> everything

   The session only says who logged in. Anything that CHANGES something
   sensitive (admin/refund-switch.php) re-reads the account from the database
   and re-checks the password — it never trusts the session alone.
   ===================================================================== */

function venusep_session_start()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,     // JavaScript cannot read the session cookie
            'samesite' => 'Lax',    // not sent on cross-site POSTs
        ]);
        session_start();
    }
}

/* The admin folder's URL path, so redirects work from admin/ AND admin/Backup/. */
function admin_base_url()
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if (basename($dir) === 'Backup') {
        $dir = dirname($dir);
    }
    return rtrim($dir, '/');
}

function admin_require_login(array $allowed = ['admin', 'staff'])
{
    venusep_session_start();
    $type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
    $isStaffSide = isset($_SESSION['user_id']) && in_array($type, ['admin', 'staff'], true);

    if (!$isStaffSide) {
        header('Location: ' . admin_base_url() . '/admin-login.php');
        exit;
    }
    if (!in_array($type, $allowed, true)) {
        // logged in on the staff side, but this page is admin-only
        header('Location: ' . admin_base_url() . '/Admin_Dashboard.php');
        exit;
    }
    staff_session_heal();

    /* After logout, the Back button must not show a cached admin page. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/* =====================================================================
   A SUSPENDED ACCOUNT LOSES ITS SESSION.

   users.is_active is checked at LOGIN, which stops a suspended person
   signing in — but it said nothing about the session they already had.
   Suspending someone is normally done because you want them out now, and
   they stayed in until they happened to log out.

   Their account_type is re-read too: an admin demoted to staff must stop
   being an admin on their next click, not on their next login.
   Checked once per request.
   ===================================================================== */
function staff_session_heal()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    require_once __DIR__ . '/db.php';
    $pdo = venusep_db();
    if ($pdo === null) {
        return;                       // offline: do not log anyone out over a hiccup
    }
    try {
        $stmt = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :u');
        $stmt->execute([':u' => (int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row || !(bool) $row['is_active']) {
            venusep_logout();
            header('Location: ' . admin_base_url() . '/admin-login.php?suspended=1');
            exit;
        }
        $_SESSION['account_type'] = $row['account_type'];
    } catch (PDOException $e) {
        // leave the session alone; the page's own error handling takes over
    }
}

function admin_is_admin()
{
    venusep_session_start();
    return isset($_SESSION['user_id'], $_SESSION['account_type']) && $_SESSION['account_type'] === 'admin';
}

/* ---- CUSTOMER SIDE (2026-09-17, ACE) — mirrors admin_require_login() above.
   The session is what customer-login.php writes: user_id, customer_id,
   account_type = 'customer'. A staff session does NOT count as a customer —
   the two sides never mix. Public pages (landing, FAQ, login, register) never
   call this; the booking + account pages call it as their first line. ---- */
function customer_logged_in()
{
    venusep_session_start();
    if (!isset($_SESSION['user_id'], $_SESSION['customer_id'], $_SESSION['account_type'])
        || $_SESSION['account_type'] !== 'customer') {
        return false;
    }
    customer_session_heal();
    return true;
}

/* =====================================================================
   The session's customer_id must still point at a real row.

   It can stop pointing at one: re-running sp_seed_demo() rebuilds the
   demo cast, so their `customers` rows get NEW ids while every open
   session still carries the old ones. The symptom is brutal and
   misleading — the customer looks logged in, their pages render, and
   then every booking fails on a foreign key with "something went wrong".

   user_id is the durable identity (customers.user_id is UNIQUE), so the
   row can always be found again. Checked once per request; a session
   whose ACCOUNT is genuinely gone is ended rather than left half-valid.
   ===================================================================== */
function customer_session_heal()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    require_once __DIR__ . '/db.php';
    $pdo = venusep_db();
    if ($pdo === null) {
        return;                       // offline: leave the session alone rather than log anyone out
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => (int) $_SESSION['user_id']]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $_SESSION['customer_id'] = (int) $id;
        } else {
            /* The account itself no longer exists. Ending the session is the
               honest outcome: nothing this person does could succeed. */
            venusep_logout();
        }
    } catch (PDOException $e) {
        // leave the session as it is; the page's own error handling takes over
    }
}

/* Not logged in as a customer -> the customer login page. Always the login
   page, never back to the requested URL (decided 2026-09-17: no ?next= hop). */
function customer_require_login()
{
    if (!customer_logged_in()) {
        header('Location: customer-login.php');
        exit;
    }
    /* After logout, the Back button must not show a cached customer page. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/* Ends the session completely: the data, the cookie, and the id. */
function venusep_logout()
{
    venusep_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => isset($p['samesite']) ? $p['samesite'] : 'Lax',
        ]);
    }
    session_destroy();
}

function csrf_token()
{
    venusep_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid($token)
{
    venusep_session_start();
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
