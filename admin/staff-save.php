<?php
/* =====================================================================
   STAFF SAVE — an ADMIN editing SOMEBODY ELSE'S account.

   POST  csrf, action=update|set_active, user_id
     update:     full_name, email, employee_no, position_role, phone
     set_active: active=0|1
   Replies with JSON.

   THIS IS THE FIRST ENDPOINT THAT TAKES AN ACCOUNT ID.
   Everything else that touches an account is self-service — registration
   creates your own, profile-save.php edits your own and has no id
   parameter at all, which is what makes it impossible to aim at someone
   else. Here the id is the whole point, so it carries the checks that
   design avoided needing:

     · ADMIN ONLY, re-checked here. The page guard is not the boundary.
       This matches admin-register.php, which has always been admin-only —
       it made no sense that creating a staff account required admin while
       managing one did not.
     · The target must be a STAFF-SIDE account. An admin must not be able
       to rewrite a customer's row from here; customers are not staff, and
       nothing on this page is built for them.

   SELF-LOCKOUT GUARDS. Two ways an admin could lock everyone out of the
   system, both refused:
     · suspending THEMSELVES
     · suspending the LAST active admin
   The second is the one that bites: with two admins it looks safe, right
   up until the other one is already suspended.

   NEVER DELETES. Deactivation only, like rooms and venues. "Marites
   Robles approved this booking" has to keep saying that forever, and the
   foreign keys would refuse anyway once an account has any history.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/bookings.php';   /* cb_normalise_mobile() */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ss_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ss_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
if (!admin_is_admin()) {
    ss_reply(403, ['ok' => false, 'message' => 'Only an administrator can manage staff accounts.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    ss_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$actor  = (int) $_SESSION['user_id'];
$action = (string) ($_POST['action'] ?? '');
$target = isset($_POST['user_id']) && ctype_digit((string) $_POST['user_id']) ? (int) $_POST['user_id'] : 0;
if (!$target) {
    ss_reply(400, ['ok' => false, 'message' => 'Invalid account.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    ss_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was saved.']);
}

$row = $pdo->prepare('SELECT id, email, account_type, is_active FROM users WHERE id = :u');
$row->execute([':u' => $target]);
$user = $row->fetch();
if (!$user) {
    ss_reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
}
/* Staff-side only. A customer's row is edited by the customer, or not at all. */
if (!in_array($user['account_type'], ['admin', 'staff'], true)) {
    ss_reply(403, ['ok' => false, 'message' => 'That account is not a staff account.']);
}

/* ---------------------------------------------------------------------
   UPDATE — name, contact, and the two fields the ORGANISATION owns
   (employee number and role), which is why they are read-only on a
   person's own profile page and editable here.
   --------------------------------------------------------------------- */
if ($action === 'update') {
    $name  = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $emp   = trim((string) ($_POST['employee_no'] ?? ''));
    $role  = trim((string) ($_POST['position_role'] ?? ''));

    if ($name === '' || mb_strlen($name) > 190) {
        ss_reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter a full name.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ss_reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
    }
    $phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
    if ($phone !== '' && $phoneNorm === null) {
        ss_reply(400, ['ok' => false, 'field' => 'phone', 'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET email = :e WHERE id = :u')->execute([':e' => $email, ':u' => $target]);
        $pdo->prepare(
            'INSERT INTO staff (user_id, full_name, employee_no, position_role, phone)
             VALUES (:u, :n, :e, :r, :p)
             ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), employee_no = VALUES(employee_no),
                                     position_role = VALUES(position_role), phone = VALUES(phone)'
        )->execute([
            ':u' => $target, ':n' => $name,
            ':e' => $emp !== '' ? mb_substr($emp, 0, 80) : null,
            ':r' => $role !== '' ? mb_substr($role, 0, 120) : null,
            ':p' => $phoneNorm,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23000') {
            /* uq_users_email or uq_staff_employee_no — say which, because the
               admin can fix either and "already in use" alone would not tell
               them where to look. */
            $dupEmp = strpos($e->getMessage(), 'employee_no') !== false;
            ss_reply(409, ['ok' => false, 'field' => $dupEmp ? 'employee_no' : 'email',
                'message' => $dupEmp
                    ? 'That employee number already belongs to another account.'
                    : 'That email address already belongs to another account.']);
        }
        error_log('VENUSeP staff-save (update): ' . $e->getMessage());
        ss_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was saved.']);
    }
    ss_reply(200, ['ok' => true, 'message' => 'Account updated.']);
}

/* ---------------------------------------------------------------------
   SUSPEND / REACTIVATE
   --------------------------------------------------------------------- */
if ($action === 'set_active') {
    $active = !empty($_POST['active']) ? 1 : 0;

    if (!$active) {
        /* Suspending yourself: the click that ends your own access. Refused
           rather than confirmed, because there is no undo from the other side
           of it — you would need another admin to let you back in. */
        if ($target === $actor) {
            ss_reply(409, ['ok' => false,
                'message' => 'You cannot suspend your own account. Ask another administrator to do it.']);
        }
        /* The last active admin. With two admins this looks safe right up until
           the other one is already suspended — which is exactly when nobody
           notices. Counted, not assumed. */
        if ($user['account_type'] === 'admin') {
            $left = (int) $pdo->query(
                "SELECT COUNT(*) FROM users WHERE account_type = 'admin' AND is_active = 1"
            )->fetchColumn();
            if ($left <= 1) {
                ss_reply(409, ['ok' => false,
                    'message' => 'This is the last active administrator. Suspending it would lock everyone out of the settings that only an administrator can change.']);
            }
        }
    }

    try {
        $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :u')
            ->execute([':a' => $active, ':u' => $target]);
    } catch (PDOException $e) {
        error_log('VENUSeP staff-save (set_active): ' . $e->getMessage());
        ss_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
    }
    ss_reply(200, ['ok' => true, 'active' => $active,
        'message' => $active ? 'Account reactivated.' : 'Account suspended. They can no longer sign in.']);
}

ss_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
