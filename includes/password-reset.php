<?php
/* =====================================================================
   FORGOT PASSWORD — customers only (DB-DECISIONS #23).

     customer/forgot-password.php   asks for the email, calls pr_request()
     customer/reset-password.php    the link lands here: pr_find(), then
                                    the new password, pr_complete()

   SECURITY
     - The reply is the SAME whether or not the email has an account, so
       the page cannot be used to test which addresses are registered.
     - The token is 32 random bytes; only its SHA-256 is stored. A copy of
       the table cannot be turned back into working links.
     - Valid PR_RESET_TTL_MIN minutes, once. A new request cancels every
       older unused link for that account.
     - At most one email per account every PR_RESET_THROTTLE_MIN minutes,
       so nobody can flood someone's inbox from this form.
     - The email goes through the outbox like every other email, but the
       link is SCRUBBED from the stored body right after sending, and these
       rows are never resent: the customer asks again instead.
     - Using the link sets a new password and password_changed_at, which
       ends every session that was signed in before (customer_session_heal())
       and forgets every remembered device — whoever was in the account is out.
     - It does NOT sign anyone in. They log in with the new password, and a
       customer with two-step verification on is still asked for the code.
     - Staff and admin accounts are never sent a link from here.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bookings.php';         /* bh_e(), used by the email frame */
require_once __DIR__ . '/receipt-emails.php';   /* re_frame(), re_paragraph(), RE_NO_REPLY, mail_*() */
require_once __DIR__ . '/passwords.php';

const PR_RESET_TTL_MIN      = 30;   // minutes a link works
const PR_RESET_THROTTLE_MIN = 5;    // minutes between emails to one account
const PR_REQUEST_REPLY      = 'If that email belongs to a VENUSeP account, we’ve sent it a link to reset the password. The link works for 30 minutes. Check your spam folder if it doesn’t arrive.';

function pr_hash(string $token): string
{
    return hash('sha256', $token);
}

/* "Forgot password" for this email. Says nothing about whether it worked: the
   caller always shows PR_REQUEST_REPLY. Throws PDOException only.
   $mailConfig: the tests pass the log transport, so nothing leaves the machine. */
function pr_request(PDO $pdo, string $email, ?string $ip, ?array $mailConfig = null): void
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.email, c.full_name
           FROM users u JOIN customers c ON c.user_id = u.id
          WHERE u.email = :e AND u.account_type = 'customer' AND u.is_active = 1
          LIMIT 1"
    );
    $stmt->execute([':e' => $email]);
    $who = $stmt->fetch();
    if (!$who) {
        return;   // no customer account: nothing is sent, and the reply is the same
    }
    $userId = (int) $who['id'];

    $recent = $pdo->prepare(
        'SELECT 1 FROM password_resets
          WHERE user_id = :u AND created_at > NOW() - INTERVAL ' . PR_RESET_THROTTLE_MIN . ' MINUTE LIMIT 1'
    );
    $recent->execute([':u' => $userId]);
    if ($recent->fetchColumn() !== false) {
        return;   // one email per account per few minutes — the earlier link still works
    }

    $token = pr_b64url(random_bytes(32));
    $link  = mail_base_url() . '/customer/reset-password.php?token=' . $token;
    $m     = pr_email(re_first_name((string) $who['full_name']), $link, (string) $who['email']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
            ->execute([':u' => $userId]);
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
             VALUES (:u, :h, NOW() + INTERVAL ' . PR_RESET_TTL_MIN . ' MINUTE, :ip)'
        )->execute([':u' => $userId, ':h' => pr_hash($token), ':ip' => $ip !== null ? mb_substr($ip, 0, 45) : null]);
        $outboxId = mail_enqueue($pdo, [
            'kind' => 'password_reset', 'requested_by_user_id' => $userId, 'to_email' => (string) $who['email'],
            'subject' => $m['subject'], 'body_html' => $m['html'], 'body_text' => $m['text'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    mail_send($pdo, $outboxId, $mailConfig);   // never throws

    /* The stored copy must not stay a working key: take the link out of it,
       whether or not it went out. A failed send is not resent — they ask again. */
    $pdo->prepare(
        'UPDATE email_outbox
            SET body_html = REPLACE(body_html, :l1, :r1), body_text = REPLACE(body_text, :l2, :r2)
          WHERE id = :id'
    )->execute([':l1' => bh_e($link), ':r1' => '[link removed after sending]',
                ':l2' => $link, ':r2' => '[link removed after sending]', ':id' => $outboxId]);
}

/* URL-safe base64 without padding (the token's alphabet). */
function pr_b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/* The reset a link points to, if it can still be used: unused, not expired,
   for an active customer. Null otherwise — the page never says which. */
function pr_find(PDO $pdo, string $token): ?array
{
    if ($token === '' || strlen($token) > 128 || preg_match('/[^A-Za-z0-9_-]/', $token)) {
        return null;
    }
    return pr_row($pdo, 'r.token_hash = :k', [':k' => pr_hash($token)]);
}

/* The same check by id, for the form's second request (the token itself is
   no longer in the URL by then — it was moved into the session). */
function pr_find_id(PDO $pdo, int $resetId, int $userId): ?array
{
    return pr_row($pdo, 'r.id = :k AND r.user_id = :u', [':k' => $resetId, ':u' => $userId]);
}

function pr_row(PDO $pdo, string $where, array $args): ?array
{
    $stmt = $pdo->prepare(
        "SELECT r.id, r.user_id, u.email
           FROM password_resets r JOIN users u ON u.id = r.user_id
          WHERE {$where} AND r.used_at IS NULL AND r.expires_at > NOW()
            AND u.account_type = 'customer' AND u.is_active = 1
          LIMIT 1"
    );
    $stmt->execute($args);
    $row = $stmt->fetch();
    return $row ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'email' => (string) $row['email']] : null;
}

/* Uses the link: the new password, the link spent, every other link for the
   account cancelled, and password_changed_at set so older sessions end. All or
   nothing; false if the link stopped being usable in the meantime. */
function pr_complete(PDO $pdo, int $resetId, int $userId, string $newPassword): bool
{
    $hash = venusep_password_hash($newPassword);   // before the transaction: it is slow on purpose
    $pdo->beginTransaction();
    try {
        $spend = $pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
              WHERE id = :r AND user_id = :u AND used_at IS NULL AND expires_at > NOW()'
        );
        $spend->execute([':r' => $resetId, ':u' => $userId]);
        if ($spend->rowCount() !== 1) {
            $pdo->rollBack();
            return false;   // used in another tab, cancelled by a newer request, or expired
        }
        $pdo->prepare(
            "UPDATE users SET password_hash = :h, password_changed_at = NOW()
              WHERE id = :u AND account_type = 'customer' AND is_active = 1"
        )->execute([':h' => $hash, ':u' => $userId]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
            ->execute([':u' => $userId]);
        /* Every remembered device is forgotten too: whoever got in may have
           ticked "Remember me" (#23). */
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :u')->execute([':u' => $userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* The email: the same frame as every other VENUSeP email. */
function pr_email(string $firstName, string $link, string $toEmail): array
{
    $button = '<tr><td style="padding:22px 32px 0 32px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="border-radius:999px;background:#a11626;">'
        . '<a href="' . bh_e($link) . '" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:999px;">Choose a new password</a>'
        . '</td></tr></table></td></tr>';
    $html = re_frame(
        'Reset your VENUSeP password. The link works for ' . PR_RESET_TTL_MIN . ' minutes.',
        'Reset your password',
        $firstName,
        'Someone — hopefully you — asked to reset the password for your VENUSeP account. Use the button below to choose a new one.',
        $button
            . re_paragraph('The link works once, for ' . PR_RESET_TTL_MIN . ' minutes. If the button does not work, copy this address into your browser:<br>'
                . '<span style="word-break:break-all;color:#1d1214;">' . bh_e($link) . '</span>', '18px 32px 0 32px', '13px', '#6e6a64')
            . re_paragraph('<strong style="color:#1d1214;">Didn’t ask for this?</strong> Ignore this email. Your password stays the same, and nobody can use this link without access to your inbox.', '18px 32px 28px 32px', '13px', '#4a4440'),
        'Sent because a password reset was requested for ' . bh_e($toEmail) . ' on VENUSeP.'
    );
    $text = "Reset your password\n\nGood day, {$firstName}!\n"
          . "Someone — hopefully you — asked to reset the password for your VENUSeP account.\n\n"
          . "Choose a new password here (works once, for " . PR_RESET_TTL_MIN . " minutes):\n{$link}\n\n"
          . "Didn’t ask for this? Ignore this email. Your password stays the same.\n\n" . RE_NO_REPLY;
    return ['subject' => 'Reset your VENUSeP password', 'html' => $html, 'text' => $text];
}
