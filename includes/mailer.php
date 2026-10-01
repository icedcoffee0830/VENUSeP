<?php
/* =====================================================================
   MAILER — the ONLY file that talks to PHPMailer (DB-DECISIONS: email +
   System Receipts). Everything else queues a row and asks this file to
   send it.

   THE OUTBOX. An email is a row in `email_outbox` before it is anything
   else. A staff action queues its email INSIDE its own transaction
   (mail_enqueue never sends, so it cannot slow or break the transaction)
   and sends it AFTER commit (mail_send). If the mail server is down, the
   action has still happened; the row just says 'failed' and staff press
   Resend. The body is rendered when queued, so a resend is the same email.

   WHO GETS EMAIL (settled 2026-10-01):
     walk-ins        their counter confirmation and their System Receipt,
                     if they gave an address at the counter
     account holders nothing automatic — only "Email me this receipt"

   SETTINGS come from includes/mail-config.php (git-ignored; template in
   mail-config.example.php). With no settings file the transport is 'log':
   each email is written as a .eml file outside the web root and nothing
   leaves the machine, so a fresh checkout can never email a real person.
   The settings may hold a Gmail App Password — never echo or log them.
   ===================================================================== */

require_once __DIR__ . '/documents.php';   /* doc_ensure_dir() for the log transport; also loads db.php */

use PHPMailer\PHPMailer\PHPMailer;

const MAIL_KINDS = ['walkin_booking', 'walkin_receipt', 'receipt_copy'];

/* The settings file's array over the defaults. A missing or broken file means
   'log' — the safe direction. Takes a path so the tests can point it anywhere. */
function mail_config_from(string $path): array
{
    $defaults = [
        'transport'  => 'log',
        'host'       => '127.0.0.1',
        'port'       => 1025,
        'encryption' => '',
        'username'   => '',
        'password'   => '',
        'from_email' => 'noreply@venusep.test',
        'from_name'  => 'VENUSeP',
        'base_url'   => 'http://localhost/VENUSeP',
    ];
    if (!is_file($path)) {
        return $defaults;
    }
    $cfg = include $path;
    return is_array($cfg) ? array_merge($defaults, $cfg) : $defaults;
}

function mail_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = mail_config_from(__DIR__ . '/mail-config.php');
    }
    return $cfg;
}

/* For links inside emails, e.g. mail_base_url() . '/customer/booking-history.php'. */
function mail_base_url(): string
{
    return rtrim((string) mail_config()['base_url'], '/');
}

/* Queue one email. Never sends, so it is safe inside the caller's transaction.
   $m: kind, to_email, subject, body_html, body_text, and the nullable
   booking_id, receipt_id, requested_by_user_id. A bad address is a caller bug
   (the counter form validates first), so it throws rather than queueing junk. */
function mail_enqueue(PDO $pdo, array $m): int
{
    $to = trim((string) ($m['to_email'] ?? ''));
    if (!in_array($m['kind'] ?? '', MAIL_KINDS, true)) {
        throw new InvalidArgumentException('Unknown email kind.');
    }
    if ($to === '' || mb_strlen($to) > 190 || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Not a usable email address.');
    }
    $pdo->prepare(
        'INSERT INTO email_outbox (kind, booking_id, receipt_id, requested_by_user_id,
                                   to_email, subject, body_html, body_text)
         VALUES (:k, :b, :r, :u, :t, :s, :h, :x)'
    )->execute([
        ':k' => $m['kind'],
        ':b' => $m['booking_id'] ?? null,
        ':r' => $m['receipt_id'] ?? null,
        ':u' => $m['requested_by_user_id'] ?? null,
        ':t' => $to,
        ':s' => mb_substr((string) $m['subject'], 0, 255),
        ':h' => (string) $m['body_html'],
        ':x' => (string) $m['body_text'],
    ]);
    return (int) $pdo->lastInsertId();
}

/* Files to attach to an outbox row: the System Receipt PDF when the row names
   a receipt. Drawn fresh from the receipt's frozen snapshot, so a resend
   carries exactly the same receipt. */
function mail_attachments_for(array $row): array
{
    if (empty($row['receipt_id'])) {
        return [];
    }
    require_once __DIR__ . '/system-receipt.php';
    $receipt = receipt_load(venusep_db(), (int) $row['receipt_id']);
    if ($receipt === null) {
        throw new RuntimeException('Receipt ' . (int) $row['receipt_id'] . ' no longer exists.');
    }
    return [['bytes' => receipt_pdf_bytes($receipt), 'name' => $receipt['number'] . '.pdf', 'type' => 'application/pdf']];
}

/* Send one queued email and record what happened. Returns true when it went
   out (or already had). NEVER throws: whoever called this has already done
   the real work, and a mail problem must not reach them. */
function mail_send(PDO $pdo, int $outboxId, ?array $config = null): bool
{
    $config = $config ?? mail_config();
    $mail = null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE id = :id');
        $stmt->execute([':id' => $outboxId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        if ($row['status'] === 'sent') {
            return true;
        }

        $mail = mail_build($row, $config);
        if ($config['transport'] === 'smtp') {
            $mail->send();
        } else {
            mail_write_log($mail, (int) $row['id']);
        }

        $pdo->prepare(
            "UPDATE email_outbox SET status = 'sent', sent_at = NOW(), last_error = NULL,
                    attempts = attempts + 1 WHERE id = :id"
        )->execute([':id' => $outboxId]);
        return true;
    } catch (\Throwable $e) {
        $error = ($mail !== null && $mail->ErrorInfo !== '') ? $mail->ErrorInfo : $e->getMessage();
        /* Belt and braces: whatever the library put in its message, the
           password never reaches the database or the log. */
        if ((string) $config['password'] !== '') {
            $error = str_replace((string) $config['password'], '[hidden]', $error);
        }
        try {
            $pdo->prepare(
                "UPDATE email_outbox SET status = 'failed', last_error = :e,
                        attempts = attempts + 1 WHERE id = :id"
            )->execute([':e' => mb_substr($error, 0, 500), ':id' => $outboxId]);
        } catch (\Throwable $ignored) {
            // the database itself is the problem; the error_log line below still records it
        }
        error_log('VENUSeP mail #' . $outboxId . ' failed: ' . $error);
        return false;
    }
}

/* One outbox row -> a ready-to-send PHPMailer message. */
function mail_build(array $row, array $config): PHPMailer
{
    require_once __DIR__ . '/vendor/phpmailer-7.1.1/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer-7.1.1/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer-7.1.1/SMTP.php';

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    if ($config['transport'] === 'smtp') {
        $mail->isSMTP();
        $mail->Host       = (string) $config['host'];
        $mail->Port       = (int) $config['port'];
        $mail->SMTPAuth   = (string) $config['username'] !== '';
        $mail->Username   = (string) $config['username'];
        $mail->Password   = (string) $config['password'];
        $mail->Timeout    = 15;
        $mail->SMTPDebug  = 0;
        if ($config['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;   // Mailpit speaks plain SMTP
        }
    }
    $mail->setFrom((string) $config['from_email'], (string) $config['from_name']);
    $mail->addAddress((string) $row['to_email']);
    $mail->Subject = (string) $row['subject'];
    $mail->isHTML(true);
    $mail->Body    = (string) $row['body_html'];
    $mail->AltBody = (string) $row['body_text'];
    foreach (mail_attachments_for($row) as $a) {
        $mail->addStringAttachment($a['bytes'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type']);
    }
    /* The logo travels inside the email: a link to this server would not
       load for anyone outside it, and most mail apps block remote images. */
    if (strpos((string) $row['body_html'], 'cid:venusep-logo') !== false) {
        $mail->addEmbeddedImage(__DIR__ . '/../assets/img/receipt-logo.png', 'venusep-logo', 'venusep.png', PHPMailer::ENCODING_BASE64, 'image/png');
    }
    return $mail;
}

/* The 'log' transport: the exact message, written to a .eml file outside the
   web root (open it in any mail app). Nothing leaves the machine. */
function mail_write_log(PHPMailer $mail, int $outboxId): void
{
    $mail->preSend();
    $dir = doc_ensure_dir('mail-log');
    if ($dir === null) {
        throw new RuntimeException('The mail log folder could not be created.');
    }
    if (file_put_contents($dir . '/' . $outboxId . '.eml', $mail->getSentMIMEMessage()) === false) {
        throw new RuntimeException('The email could not be written to the mail log.');
    }
}
