<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDOException;

/* profile-save.php — a person editing their OWN account, both portals. JSON.
   POST action=details|password|photo|tfa_begin|tfa_confirm|tfa_codes|tfa_disable
               |google_link_begin|google_unlink
   ALWAYS THE SESSION'S OWN ACCOUNT — there is no id parameter anywhere.
     customer -> customers row · staff / admin -> staff row (created if missing)
   The email and password always live on `users`. Profile pictures only: the
   photo goes under public assets/; documents never do. See the old file's
   header for the full reasoning; the rules are unchanged.
   CSRF: Laravel's check (an expired page gets the old 400 JSON, bootstrap/app.php). */
class ProfileSaveController
{
    /* pf_reply(): the JSON reply, ending the request like the old exit. */
    private function reply(int $status, array $data): never
    {
        throw new HttpResponseException(response()->json($data, $status)->header('Cache-Control', 'no-store'));
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'room-photos', 'bookings', 'two-factor', 'passwords', 'google-auth', 'remember-me');

        if (!$request->isMethod('post')) {
            throw new HttpResponseException(response()->json(['ok' => false, 'message' => 'Use POST.'], 405)
                ->header('Allow', 'POST')->header('Cache-Control', 'no-store'));
        }

        $type = session('account_type');
        if (!session()->has('user_id') || !in_array($type, ['customer', 'staff', 'admin'], true)) {
            $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }
        $userId = (int) session('user_id');
        $isCustomer = $type === 'customer';
        /* A customer session must pass the same checks as every customer page (#23). */
        if ($isCustomer && !customer_logged_in()) {
            $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was saved.']);
        }

        $post = $request->request;
        $action = (string) $post->get('action', '');

        if ($action === 'details') {
            $this->details($pdo, $post, $userId, $isCustomer);
        }
        if ($action === 'password') {
            $this->password($pdo, $post, $userId, $isCustomer);
        }
        if ($action === 'google_link_begin' || $action === 'google_unlink') {
            $this->google($pdo, $post, $userId, $isCustomer, $action);
        }
        if ($action === 'photo') {
            $this->photo($pdo, $request, $userId, $isCustomer);
        }
        if (in_array($action, ['tfa_begin', 'tfa_confirm', 'tfa_codes', 'tfa_disable'], true)) {
            $this->twoStep($pdo, $post, $userId, (string) $type, $action);
        }

        $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }

    /* ---- DETAILS ---- */
    private function details($pdo, $post, int $userId, bool $isCustomer): never
    {
        $name  = trim((string) $post->get('full_name', ''));
        $email = strtolower(trim((string) $post->get('email', '')));
        $phone = trim((string) $post->get('contact_number', ''));

        if ($name === '' || mb_strlen($name) > 190) {
            $this->reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter your full name.']);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
        }
        $phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
        if ($phone !== '' && $phoneNorm === null) {
            $this->reply(400, ['ok' => false, 'field' => 'contact_number',
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
                    ':a' => mb_substr(trim((string) $post->get('address', '')), 0, 500) ?: null,
                    ':i' => mb_substr(trim((string) $post->get('university_id_no', '')), 0, 80) ?: null,
                    ':u' => $userId,
                ]);
            } else {
                /* The seeded admin has no staff row — created on first save. */
                $pdo->prepare(
                    'INSERT INTO staff (user_id, full_name, phone) VALUES (:u, :n, :p)
                     ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)'
                )->execute([':u' => $userId, ':n' => $name, ':p' => $phoneNorm]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $this->reply(409, ['ok' => false, 'field' => 'email',
                    'message' => 'That email address is already used by another account.']);
            }
            error_log('VENUSeP profile-save (details): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was saved.']);
        }

        /* The header chip and the booking pages read these from the session. */
        if ($isCustomer) {
            session(['customer_name' => $name, 'customer_email' => $email]);
        }
        session(['user_email' => $email]);
        $this->reply(200, ['ok' => true, 'message' => 'Your details were saved.']);
    }

    /* ---- PASSWORD — the current password is required (or, for a Google-only
       account's first password, a fresh Google confirmation, #23). ---- */
    private function password($pdo, $post, int $userId, bool $isCustomer): never
    {
        $current = (string) $post->get('current_password', '');
        $new     = (string) $post->get('new_password', '');
        $confirm = (string) $post->get('confirm_new_password', '');

        if ($new === '' || strlen($new) < 8) {
            $this->reply(400, ['ok' => false, 'field' => 'new_password', 'message' => 'Use a password of at least 8 characters.']);
        }
        if ($new !== $confirm) {
            $this->reply(400, ['ok' => false, 'field' => 'confirm_new_password', 'message' => 'The two passwords do not match.']);
        }
        /* Argon2id is required (DB-DECISIONS #21): refuse before touching the database. */
        if (!venusep_password_ready()) {
            $this->reply(500, ['ok' => false, 'message' => VENUSEP_PASSWORD_SETUP_ERROR]);
        }
        try {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :u');
            $stmt->execute([':u' => $userId]);
            $hash = $stmt->fetchColumn();
            $firstPassword = $hash === null;
            if ($firstPassword) {
                /* The proof is spent here, whatever happens next. */
                $proofOk = $isCustomer && google_proof_valid('google_reauth', $userId);
                google_proof_clear('google_reauth');
                if (!$proofOk) {
                    $this->reply(403, ['ok' => false, 'reauth' => true,
                        'message' => 'Confirm with Google first, then choose your password. The confirmation lasts 10 minutes.']);
                }
                $upd = $pdo->prepare('UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :u AND password_hash IS NULL');
                $upd->execute([':h' => venusep_password_hash($new), ':u' => $userId]);
                if ($upd->rowCount() !== 1) {
                    $this->reply(409, ['ok' => false, 'message' => 'A password was set for this account meanwhile. Reload the page.']);
                }
            } else {
                if ($hash === false || !password_verify($current, $hash)) {
                    $this->reply(401, ['ok' => false, 'field' => 'current_password', 'message' => 'That is not your current password.']);
                }
                $pdo->prepare('UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :u')
                    ->execute([':h' => venusep_password_hash($new), ':u' => $userId]);
            }
        } catch (PDOException $e) {
            error_log('VENUSeP profile-save (password): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so your password was not changed.']);
        }

        /* A new password means a new session id; this session's sign-in time moves
           forward so it is the one that stays (customer_session_heal(), #23). */
        session()->migrate(true);
        session(['auth_at' => time()]);
        /* ...and every REMEMBERED device signs in again with the new password. */
        try {
            remember_forget_all($pdo, $userId);
        } catch (PDOException $e) {
            error_log('VENUSeP profile-save (forget remembered devices): ' . $e->getMessage());
        }
        remember_cookie_clear();
        $this->reply(200, ['ok' => true, 'message' => $firstPassword
            ? 'Your password was set. You can now sign in with your email and password, or with Google.'
            : 'Your password was changed.']);
    }

    /* ---- GOOGLE SIGN-IN (#23) — connect or disconnect, customers only; both
       need the password re-typed. ---- */
    private function google($pdo, $post, int $userId, bool $isCustomer, string $action): never
    {
        if (!$isCustomer) {
            $this->reply(403, ['ok' => false, 'message' => 'Google sign-in is for customer accounts only.']);
        }
        try {
            $stmt = $pdo->prepare('SELECT password_hash, google_sub FROM users WHERE id = :u');
            $stmt->execute([':u' => $userId]);
            $acct = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('VENUSeP profile-save (' . $action . '): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }
        if (!$acct) {
            $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }
        if ($acct['password_hash'] === null) {
            $this->reply(409, ['ok' => false, 'message' => 'Google is the only way into this account. Set a password first.']);
        }
        if ($action === 'google_link_begin' && $acct['google_sub'] !== null) {
            $this->reply(409, ['ok' => false, 'message' => 'Google is already connected to this account.']);
        }
        if ($action === 'google_unlink' && $acct['google_sub'] === null) {
            $this->reply(409, ['ok' => false, 'message' => 'Google is not connected to this account.']);
        }
        if ($action === 'google_link_begin' && !google_enabled()) {
            $this->reply(409, ['ok' => false, 'message' => 'Google sign-in is not set up on this server yet.']);
        }
        if (!password_verify((string) $post->get('password', ''), $acct['password_hash'])) {
            $this->reply(401, ['ok' => false, 'field' => 'password', 'message' => 'That is not your current password.']);
        }

        if ($action === 'google_link_begin') {
            google_proof_set('google_link', $userId);
            $this->reply(200, ['ok' => true, 'redirect' => route('customer.google-start', ['purpose' => 'link'])]);
        }
        try {
            $pdo->prepare('UPDATE users SET google_sub = NULL WHERE id = :u AND password_hash IS NOT NULL')
                ->execute([':u' => $userId]);
        } catch (PDOException $e) {
            error_log('VENUSeP profile-save (google_unlink): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so Google is still connected.']);
        }
        $this->reply(200, ['ok' => true, 'message' => 'Google was disconnected. Sign in with your email and password from now on.']);
    }

    /* ---- PHOTO — a profile picture, and nothing else. ---- */
    private function photo($pdo, Request $request, int $userId, bool $isCustomer): never
    {
        $dir = public_path('assets/img/avatars');
        $webBase = 'assets/img/avatars';
        $stem = ($isCustomer ? 'c' : 's') . $userId;

        $clear = function () use ($dir, $stem) {
            foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
                $p = $dir . '/' . $stem . '.' . $ext;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        };
        $table = $isCustomer ? 'customers' : 'staff';
        /* An admin may have no staff row yet: create it, or the UPDATEs would match nothing. */
        if (!$isCustomer) {
            $pdo->prepare('INSERT INTO staff (user_id, full_name) SELECT id, username FROM users WHERE id = :u
                           ON DUPLICATE KEY UPDATE user_id = user_id')->execute([':u' => $userId]);
        }

        if (!empty($request->request->get('remove'))) {
            $clear();
            $pdo->prepare("UPDATE {$table} SET photo_path = NULL WHERE user_id = :u")->execute([':u' => $userId]);
            $this->reply(200, ['ok' => true, 'photo' => null, 'message' => 'Photo removed.']);
        }

        $file = $request->files->get('photo');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a picture to upload.']);
        }
        /* The same validator room photos use. PDFs are deliberately NOT accepted. */
        $err = '';
        $tmp = $file->getPathname();
        $ext = rp_validate_image($tmp, $file->getClientOriginalName(), $err);
        if ($ext === null) {
            $this->reply(400, ['ok' => false, 'message' => $err]);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->reply(500, ['ok' => false, 'message' => 'Could not create the upload folder.']);
        }

        $clear();
        $dest = $dir . '/' . $stem . '.' . $ext;
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$moved) {
            $this->reply(500, ['ok' => false, 'message' => 'Could not save the picture.']);
        }
        @chmod($dest, 0644);

        /* Stored RELATIVE (assets/img/avatars/...): the pages show it with asset(). */
        $path = $webBase . '/' . $stem . '.' . $ext;
        $pdo->prepare("UPDATE {$table} SET photo_path = :p WHERE user_id = :u")
            ->execute([':p' => $path, ':u' => $userId]);

        $this->reply(200, ['ok' => true, 'photo' => $path, 'message' => 'Photo updated.']);
    }

    /* ---- TWO-STEP VERIFICATION (DB-DECISIONS #20). Turning it ON needs the
       password; anything else a current code or a recovery code (the lockout
       ladder). The new key waits in the session until a code from it is confirmed. ---- */
    private function twoStep($pdo, $post, int $userId, string $type, string $action): never
    {
        $code = (string) $post->get('code', '');
        try {
            $tfa = tfa_status($pdo, $userId);

            /* Checked before any code is tried, so a refused request spends no attempt. */
            if (!tfa_available_for($type)) {
                $this->reply(403, ['ok' => false, 'message' => 'Two-step verification is not available for this account.']);
            }
            if ($action === 'tfa_disable' && tfa_required_for($type)) {
                $this->reply(403, ['ok' => false, 'message' => 'Admin accounts must keep two-step verification on.']);
            }
            if (($action === 'tfa_codes' || $action === 'tfa_disable') && !$tfa['enabled']) {
                $this->reply(409, ['ok' => false, 'message' => 'Two-step verification is off for this account.']);
            }

            if ($action === 'tfa_begin' && !$tfa['enabled']) {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :u');
                $stmt->execute([':u' => $userId]);
                $hash = $stmt->fetchColumn();
                if ($hash === null) {
                    $this->reply(409, ['ok' => false, 'field' => 'password',
                        'message' => 'Set a password first (Account Security, above), then turn this on.']);
                }
                if ($hash === false || !password_verify((string) $post->get('password', ''), $hash)) {
                    $this->reply(401, ['ok' => false, 'field' => 'password', 'message' => 'That is not your current password.']);
                }
            } elseif ($action !== 'tfa_confirm') {
                $check = tfa_verify($pdo, $userId, $code);
                if (!$check['ok']) {
                    $this->reply($check['error'] === 'locked' ? 423 : 401,
                        ['ok' => false, 'field' => 'code', 'message' => tfa_error_message($check),
                         'seconds' => (int) ($check['seconds'] ?? 0)]);   // the panel counts a lock down
                }
            }

            $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :u');
            $stmt->execute([':u' => $userId]);
            $email = (string) $stmt->fetchColumn();

            if ($action === 'tfa_begin') {
                $secret = tfa_setup_secret($userId, $tfa['enabled_at']);
                $this->reply(200, ['ok' => true, 'uri' => totp_uri($secret, $email), 'secret' => $secret]);
            }

            if ($action === 'tfa_confirm') {
                $secret = tfa_setup_current($userId, $tfa['enabled_at']);
                if ($secret === null) {
                    $this->reply(400, ['ok' => false, 'message' => 'That set-up has expired. Start it again.']);
                }
                $matched = totp_match_step($secret, $code, time(), null);
                if ($matched === null) {
                    $this->reply(400, ['ok' => false, 'field' => 'code', 'message' => tfa_mismatch_message($email)]);
                }
                $codes = tfa_enable($pdo, $userId, $secret, $matched, $tfa['enabled_at']);
                if ($codes === null) {   // 2FA changed in another window since this set-up began
                    tfa_setup_clear();
                    $this->reply(409, ['ok' => false, 'message' => 'Two-step verification was just changed somewhere else. Reload the page and start again.']);
                }
                tfa_setup_clear();
                /* Turned on (or moved): every remembered device signs in once more, code and all (#23). */
                remember_forget_all($pdo, $userId);
                $this->reply(200, ['ok' => true, 'codes' => $codes, 'message' => $tfa['enabled']
                    ? 'Your new phone is set up. The old phone and the old recovery codes no longer work.'
                    : 'Two-step verification is on.']);
            }

            if ($action === 'tfa_codes') {
                $codes = tfa_regenerate_codes($pdo, $userId);
                $this->reply(200, ['ok' => true, 'codes' => $codes, 'message' => 'New recovery codes made. The old ones no longer work.']);
            }

            tfa_reset($pdo, $userId);   // tfa_disable: customers only, checked above
            tfa_setup_clear();
            remember_forget_all($pdo, $userId);
            $this->reply(200, ['ok' => true, 'message' => 'Two-step verification is off.']);
        } catch (PDOException $e) {
            error_log('VENUSeP profile-save (2fa): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }
    }
}
