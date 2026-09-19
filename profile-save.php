<?php
/* =====================================================================
   PROFILE SAVE — a person editing their OWN account. Both portals.

   POST  csrf, action=details|password|photo
     details:  full_name, email, contact_number, address, university_id_no
     password: current_password, new_password, confirm_new_password
     photo:    FILES[photo]        (or remove=1 to clear it)
   Replies with JSON.

   ALWAYS THE SESSION'S OWN ACCOUNT. There is no id parameter, anywhere.
   Editing someone else is a different job with different rules (Staff
   Management), and an endpoint that could do both would be one missing
   check away from letting any customer rewrite any account.

   Which profile row is touched follows the session:
     customer            -> customers
     staff / admin       -> staff   (created if missing: the seeded admin
                            has no staff row, and their name has to live
                            somewhere once they can edit it)
   The email and password always live on `users`, for everyone.

   ⚠️ PROFILE PICTURES ONLY. The photo goes under assets/, which the web
   server hands to anyone with the URL — that is fine for a picture
   someone chose to show, and completely wrong for anything else. IDs and
   receipts go to booking_documents, outside the web root, behind
   document-view.php. Nothing in this file may ever write one there.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/room-photos.php';   /* rp_validate_image() — same image rules as room photos */
require_once __DIR__ . '/includes/bookings.php';      /* cb_normalise_mobile() */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pf_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pf_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    pf_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($type, ['customer', 'staff', 'admin'], true)) {
    pf_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
$userId    = (int) $_SESSION['user_id'];
$isCustomer = $type === 'customer';

$pdo = venusep_db();
if ($pdo === null) {
    pf_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was saved.']);
}

$action = (string) ($_POST['action'] ?? '');

/* ---------------------------------------------------------------------
   DETAILS
   --------------------------------------------------------------------- */
if ($action === 'details') {
    $name  = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['contact_number'] ?? ''));

    if ($name === '' || mb_strlen($name) > 190) {
        pf_reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter your full name.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        pf_reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
    }
    $phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
    if ($phone !== '' && $phoneNorm === null) {
        pf_reply(400, ['ok' => false, 'field' => 'contact_number',
            'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET email = :e WHERE id = :u')->execute([':e' => $email, ':u' => $userId]);

        if ($isCustomer) {
            $pdo->prepare(
                'UPDATE customers SET full_name = :n, phone = :p, address = :a, university_id_no = :i
                  WHERE user_id = :u'
            )->execute([
                ':n' => $name, ':p' => $phoneNorm,
                ':a' => mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 500) ?: null,
                ':i' => mb_substr(trim((string) ($_POST['university_id_no'] ?? '')), 0, 80) ?: null,
                ':u' => $userId,
            ]);
        } else {
            /* The seeded admin has no staff row — created on first save rather
               than refusing to let them edit their own name. */
            $pdo->prepare(
                'INSERT INTO staff (user_id, full_name, phone) VALUES (:u, :n, :p)
                 ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)'
            )->execute([':u' => $userId, ':n' => $name, ':p' => $phoneNorm]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($e->getCode() === '23000') {
            pf_reply(409, ['ok' => false, 'field' => 'email',
                'message' => 'That email address is already used by another account.']);
        }
        error_log('VENUSeP profile-save (details): ' . $e->getMessage());
        pf_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was saved.']);
    }

    /* The header chip and the booking pages read these from the session, so a
       stale copy would show the OLD name until the next login. */
    if ($isCustomer) {
        $_SESSION['customer_name'] = $name;
        $_SESSION['customer_email'] = $email;
    }
    $_SESSION['user_email'] = $email;
    pf_reply(200, ['ok' => true, 'message' => 'Your details were saved.']);
}

/* ---------------------------------------------------------------------
   PASSWORD
   --------------------------------------------------------------------- */
if ($action === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_new_password'] ?? '');

    if ($new === '' || strlen($new) < 8) {
        pf_reply(400, ['ok' => false, 'field' => 'new_password', 'message' => 'Use a password of at least 8 characters.']);
    }
    if ($new !== $confirm) {
        pf_reply(400, ['ok' => false, 'field' => 'confirm_new_password', 'message' => 'The two passwords do not match.']);
    }

    /* THE CURRENT PASSWORD IS REQUIRED. Without it, anyone who found an
       unlocked machine could lock the real owner out of their own account —
       a session alone must never be enough to change the credential that
       created it. Same principle as the refund switch re-asking for it. */
    try {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :u');
        $stmt->execute([':u' => $userId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || !password_verify($current, $hash)) {
            pf_reply(401, ['ok' => false, 'field' => 'current_password', 'message' => 'That is not your current password.']);
        }
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :u')
            ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => $userId]);
    } catch (PDOException $e) {
        error_log('VENUSeP profile-save (password): ' . $e->getMessage());
        pf_reply(500, ['ok' => false, 'message' => 'Something went wrong, so your password was not changed.']);
    }

    /* A new password means a new session id: if anyone else was riding the old
       one, changing the password should end their ride too. */
    session_regenerate_id(true);
    pf_reply(200, ['ok' => true, 'message' => 'Your password was changed.']);
}

/* ---------------------------------------------------------------------
   PHOTO — a profile picture, and nothing else.
   --------------------------------------------------------------------- */
if ($action === 'photo') {
    $dir = __DIR__ . '/assets/img/avatars';
    $webBase = 'assets/img/avatars';
    $stem = ($isCustomer ? 'c' : 's') . $userId;

    /* Clear any previous file first: the new one may have a different
       extension, and two avatars for one person is one too many. */
    $clear = function () use ($dir, $stem) {
        foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
            $p = $dir . '/' . $stem . '.' . $ext;
            if (is_file($p)) { @unlink($p); }
        }
    };
    $table = $isCustomer ? 'customers' : 'staff';

    if (!empty($_POST['remove'])) {
        $clear();
        $pdo->prepare("UPDATE {$table} SET photo_path = NULL WHERE user_id = :u")->execute([':u' => $userId]);
        pf_reply(200, ['ok' => true, 'photo' => null, 'message' => 'Photo removed.']);
    }

    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        pf_reply(400, ['ok' => false, 'message' => 'Choose a picture to upload.']);
    }
    /* The same validator room photos use: a real image, an allowed extension,
       under the size cap. PDFs are deliberately NOT accepted — an avatar is a
       picture, and the document uploader is the place for anything else. */
    $err = '';
    $ext = rp_validate_image($_FILES['photo']['tmp_name'], $_FILES['photo']['name'], $err);
    if ($ext === null) {
        pf_reply(400, ['ok' => false, 'message' => $err]);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        pf_reply(500, ['ok' => false, 'message' => 'Could not create the upload folder.']);
    }

    $clear();
    $dest = $dir . '/' . $stem . '.' . $ext;
    $moved = is_uploaded_file($_FILES['photo']['tmp_name'])
        ? move_uploaded_file($_FILES['photo']['tmp_name'], $dest)
        : copy($_FILES['photo']['tmp_name'], $dest);
    if (!$moved) {
        pf_reply(500, ['ok' => false, 'message' => 'Could not save the picture.']);
    }
    @chmod($dest, 0644);

    /* Stored RELATIVE to the project root, so pages one level deep prefix it
       with ../ and the value does not pin the install to one URL. */
    $path = $webBase . '/' . $stem . '.' . $ext;
    $pdo->prepare("UPDATE {$table} SET photo_path = :p WHERE user_id = :u")
        ->execute([':p' => $path, ':u' => $userId]);

    pf_reply(200, ['ok' => true, 'photo' => $path, 'message' => 'Photo updated.']);
}

pf_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
