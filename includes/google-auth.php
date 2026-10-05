<?php
/* =====================================================================
   SIGN IN WITH GOOGLE — customers only (DB-DECISIONS #23).

   THE FLOW
     customer/google-start.php     makes a one-time state, nonce and PKCE
                                   verifier, keeps them in the session, and
                                   sends the browser to Google.
     customer/google-callback.php  Google sends the browser back with a
                                   one-time code. We swap it for the
                                   person's identity, server to server, then
                                   decide what that identity may do
                                   (google_resolve()).
     customer/customer-login.php   finishes: the normal session, the
                                   two-step code if they turned it on, or the
                                   one-time "finish your account" screen for a
                                   brand-new customer.

   WHAT GOOGLE GIVES US — and what it never does. We get a statement signed
   by Google: account id ("sub"), email, whether Google verified that email,
   and the name. We NEVER get the Google password; the person types it on
   Google's own page. So an account created here has no password at all
   (users.password_hash NULL) until its owner sets one.

   THE RULES
     - Identity is google_sub, never email. An email can be changed in the
       profile; the Google id cannot, so a Google login can never be moved
       onto someone else's account by editing an address.
     - An email already registered WITH A PASSWORD is never linked
       automatically. VENUSeP does not verify emails at sign-up, so anyone
       could have registered someone else's address first; auto-linking
       would let that person and the real owner share one account. The
       customer logs in with their password instead, and may then connect
       Google from their profile — proving the password AND the Google
       account in one session, which rules that attack out.
     - Adding or changing a way of signing in needs a fresh proof, never a
       session alone: connecting Google needs the password re-typed;
       setting a first password needs a fresh trip to the linked Google
       account (google_proof_*()); disconnecting needs the password.
     - Staff and admin accounts are never signed in through Google, whether
       matched by id or by email.
     - A customer who turned on two-step verification is still asked for the
       code. Google proves who they are to Google, not that they hold the
       VENUSeP authenticator entry.

   SECURITY OF THE EXCHANGE
     state     random, one-time, tied to this session: a callback this
               browser did not start is refused (login CSRF).
     PKCE      S256: a stolen code is useless without the verifier, which
               never leaves the server.
     nonce     must come back inside the ID token: a token minted for some
               other sign-in cannot be replayed into this one.
     the token is fetched DIRECTLY from Google's token endpoint over
               verified TLS, authenticated with our client secret. OpenID
               Connect Core 3.1.3.7 allows trusting it from that channel
               without checking the signature; issuer, audience, expiry,
               nonce and email_verified are still all checked.

   SETTINGS: includes/google-config.php (git-ignored; template in
   google-config.example.php). Without it the Google buttons are hidden.
   ===================================================================== */

require_once __DIR__ . '/db.php';

const GOOGLE_AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const GOOGLE_ISSUERS        = ['https://accounts.google.com', 'accounts.google.com'];
const GOOGLE_FLOW_TTL       = 600;   // seconds allowed between leaving for Google and coming back
const GOOGLE_SIGNUP_TTL     = 900;   // seconds to fill in the one-time "finish your account" screen
const GOOGLE_CLOCK_SKEW     = 120;   // seconds of disagreement tolerated between our clock and Google's
const GOOGLE_PROOF_TTL      = 600;   // seconds a "just confirmed" proof lasts (see google_proof_set())

/* Why a trip to Google was started. Only 'signin' works without a session;
   the other two belong to a customer who is already signed in (profile page).
     signin  log in, or sign up
     link    connect Google to this account (the password was re-typed first)
     reauth  prove this Google account again, to set a first password */
const GOOGLE_PURPOSES = ['signin', 'link', 'reauth'];

/* The settings file's array over the defaults. Takes a path so the tests can point it anywhere. */
function google_config_from(string $path): array
{
    $defaults = ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''];
    if (!is_file($path)) {
        return $defaults;
    }
    $cfg = include $path;
    return is_array($cfg) ? array_merge($defaults, $cfg) : $defaults;
}

function google_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = google_config_from(__DIR__ . '/google-config.php');
    }
    return $cfg;
}

/* Is Google sign-in set up on this machine? The buttons only show when it is. */
function google_enabled(): bool
{
    $cfg = google_config();
    return trim((string) $cfg['client_id']) !== ''
        && trim((string) $cfg['client_secret']) !== ''
        && trim((string) $cfg['redirect_uri']) !== '';
}

function google_b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function google_b64url_decode(string $text): ?string
{
    if ($text === '' || preg_match('/[^A-Za-z0-9_-]/', $text)) {
        return null;
    }
    $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $raw === false ? null : $raw;
}

/* Starts a trip to Google: a fresh state, nonce and PKCE verifier kept in the
   session (only the verifier's HASH goes to Google), and the URL to send the
   browser to. A second click replaces the first attempt, so only the newest one
   can finish. $userId is the signed-in customer for 'link' / 'reauth'.
   $remember: "Remember me" was ticked — carried to the callback (sign-in only). */
function google_begin(string $purpose = 'signin', int $userId = 0, bool $remember = false): string
{
    $cfg      = google_config();
    $state    = google_b64url(random_bytes(32));
    $nonce    = google_b64url(random_bytes(32));
    $verifier = google_b64url(random_bytes(48));   // 64 characters; PKCE allows 43–128
    $_SESSION['google_flow'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time(),
                                'purpose' => in_array($purpose, GOOGLE_PURPOSES, true) ? $purpose : 'signin',
                                'user_id' => $userId, 'remember' => $remember && $purpose === 'signin'];

    return GOOGLE_AUTH_ENDPOINT . '?' . http_build_query([
        'client_id'             => (string) $cfg['client_id'],
        'redirect_uri'          => (string) $cfg['redirect_uri'],
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => google_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',   // always let them pick which Google account
    ], '', '&', PHP_QUERY_RFC3986);
}

/* The sign-in this browser started, consumed: it works ONCE, and only if the
   state Google sent back is the one we made, within GOOGLE_FLOW_TTL. */
function google_take_flow(string $state): ?array
{
    $flow = $_SESSION['google_flow'] ?? null;
    unset($_SESSION['google_flow']);
    if (!is_array($flow) || $state === '' || !hash_equals((string) $flow['state'], $state)) {
        return null;
    }
    if (time() - (int) $flow['at'] > GOOGLE_FLOW_TTL) {
        return null;
    }
    return $flow;
}

/* The claims inside an ID token, unverified (see the header for why that is
   safe here: the token came straight from Google's token endpoint). */
function google_decode_id_token(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $json = google_b64url_decode($parts[1]);
    $claims = $json === null ? null : json_decode($json, true);
    return is_array($claims) ? $claims : null;
}

/* Swaps the one-time code for the person's identity. Throws RuntimeException
   with a message for the error log; it never contains the client secret. */
function google_exchange_code(string $code, string $verifier, array $cfg): array
{
    $ch = curl_init(GOOGLE_TOKEN_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => (string) $cfg['client_id'],
            'client_secret' => (string) $cfg['client_secret'],
            'redirect_uri'  => (string) $cfg['redirect_uri'],
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
        ]),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,   // never turned off: the whole trust argument rests on it
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('token request failed: ' . $error);
    }
    $reply = json_decode((string) $body, true);
    if ($status !== 200 || !is_array($reply)) {
        $why = is_array($reply) ? (string) ($reply['error'] ?? '') . ' ' . (string) ($reply['error_description'] ?? '') : 'not JSON';
        throw new RuntimeException('token endpoint answered ' . $status . ': ' . mb_substr(trim($why), 0, 200));
    }
    $claims = is_string($reply['id_token'] ?? null) ? google_decode_id_token($reply['id_token']) : null;
    if ($claims === null) {
        throw new RuntimeException('token endpoint sent no readable id_token');
    }
    return $claims;
}

/* Null when the claims are acceptable, otherwise the reason (for the log only).
   Pure, so tests/google_auth_test.php can try every way it should refuse. */
function google_check_claims(array $c, string $clientId, string $nonce, int $now): ?string
{
    if (!in_array($c['iss'] ?? null, GOOGLE_ISSUERS, true)) {
        return 'wrong issuer';
    }
    $aud = $c['aud'] ?? null;
    $audOk = is_array($aud)
        ? in_array($clientId, $aud, true) && ($c['azp'] ?? null) === $clientId
        : $aud === $clientId;
    if ($clientId === '' || !$audOk) {
        return 'token was not issued to this client';
    }
    if (!is_int($c['exp'] ?? null) || $c['exp'] + GOOGLE_CLOCK_SKEW < $now) {
        return 'token expired';
    }
    if (!is_int($c['iat'] ?? null) || $c['iat'] - GOOGLE_CLOCK_SKEW > $now) {
        return 'token issued in the future';
    }
    if (!is_string($c['nonce'] ?? null) || $nonce === '' || !hash_equals($nonce, $c['nonce'])) {
        return 'nonce mismatch';
    }
    $sub = $c['sub'] ?? null;
    if (!is_string($sub) || $sub === '' || strlen($sub) > 255) {
        return 'no usable subject';
    }
    $email = $c['email'] ?? null;
    if (!is_string($email) || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'no usable email';
    }
    /* Google sends a boolean; very old tokens sent the string "true". */
    if (($c['email_verified'] ?? null) !== true && ($c['email_verified'] ?? null) !== 'true') {
        return 'email not verified by Google';
    }
    return null;
}

/* What a verified Google identity may do here.
     ['action' => 'signin', 'user_id' => int, 'email' => string]
     ['action' => 'new']       nobody has this Google id or this email: sign up
     ['action' => 'taken']     a customer already registered this email (with a
                               password, or linked to another Google account)
     ['action' => 'refused']   a staff/admin account, a suspended one, or a
                               login with no customer profile */
function google_resolve(PDO $pdo, string $sub, string $email): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.account_type, u.is_active, c.id AS customer_id
           FROM users u LEFT JOIN customers c ON c.user_id = u.id
          WHERE u.google_sub = :s LIMIT 1'
    );
    $stmt->execute([':s' => $sub]);
    $row = $stmt->fetch();
    if ($row) {
        if ($row['account_type'] !== 'customer' || !(bool) $row['is_active'] || $row['customer_id'] === null) {
            return ['action' => 'refused'];
        }
        return ['action' => 'signin', 'user_id' => (int) $row['id'], 'email' => (string) $row['email']];
    }

    $stmt = $pdo->prepare('SELECT account_type FROM users WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $type = $stmt->fetchColumn();
    if ($type === false) {
        return ['action' => 'new'];
    }
    return ['action' => $type === 'customer' ? 'taken' : 'refused'];
}

/* ---------------------------------------------------------------------
   SHORT-LIVED PROOFS, kept in the session for GOOGLE_PROOF_TTL seconds.
   A session alone must never be enough to add or change a way of signing
   in (the same rule as profile-save.php's "current password required"):
     google_link    the customer re-typed their password, so they may now
                    go to Google and connect it (spent by google-start.php)
     google_reauth  the customer just proved their linked Google account,
                    so a Google-only account may set its first password
                    (spent by profile-save.php)
   --------------------------------------------------------------------- */
function google_proof_set(string $key, int $userId): void
{
    $_SESSION[$key] = ['user_id' => $userId, 'at' => time()];
}

function google_proof_valid(string $key, int $userId): bool
{
    $p = $_SESSION[$key] ?? null;
    return is_array($p) && (int) ($p['user_id'] ?? 0) === $userId && $userId > 0
        && time() - (int) ($p['at'] ?? 0) <= GOOGLE_PROOF_TTL;
}

function google_proof_clear(string $key): void
{
    unset($_SESSION[$key]);
}

/* Connects a Google id to a signed-in customer. Null on success (or if it was
   already this account's), otherwise the sentence to show them. One Google
   account can belong to one VENUSeP account only (uq_users_google_sub). */
function google_link(PDO $pdo, int $userId, string $sub): ?string
{
    $taken = 'That Google account is already connected to another VENUSeP account.';
    $stmt = $pdo->prepare('SELECT id FROM users WHERE google_sub = :s LIMIT 1');
    $stmt->execute([':s' => $sub]);
    $owner = $stmt->fetchColumn();
    if ($owner !== false) {
        return (int) $owner === $userId ? null : $taken;
    }
    try {
        $upd = $pdo->prepare(
            "UPDATE users SET google_sub = :s
              WHERE id = :u AND google_sub IS NULL AND account_type = 'customer'"
        );
        $upd->execute([':s' => $sub, ':u' => $userId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return $taken;   // connected elsewhere in the instant since the check above
        }
        throw $e;
    }
    return $upd->rowCount() === 1 ? null : 'This account is already connected to a different Google account. Disconnect it first.';
}

/* The Google identity waiting on the "finish your account" screen, or null. */
function google_signup_pending(): ?array
{
    $p = $_SESSION['google_signup'] ?? null;
    if (!is_array($p)) {
        return null;
    }
    if (time() - (int) ($p['at'] ?? 0) > GOOGLE_SIGNUP_TTL) {
        unset($_SESSION['google_signup']);
        return null;
    }
    return $p;
}

/* Creates the customer for a brand-new Google identity: no password, the
   Google id linked, and the email marked verified (Google verified it).
   Throws PDOException; 23000 means the email or Google id was taken meanwhile. */
function google_create_customer(PDO $pdo, string $sub, string $email, string $name, ?string $phone): int
{
    require_once __DIR__ . '/accounts.php';
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO users (email, username, password_hash, google_sub, account_type, email_verified_at)
             VALUES (:e, :u, NULL, :s, 'customer', NOW())"
        )->execute([':e' => $email, ':u' => venusep_unique_username($pdo, $email), ':s' => $sub]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO customers (user_id, full_name, phone) VALUES (:u, :n, :p)')
            ->execute([':u' => $userId, ':n' => $name, ':p' => $phone]);
        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
