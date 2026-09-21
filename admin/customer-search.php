<?php
/* =====================================================================
   CUSTOMER SEARCH — for the counter screen's "who is this for" step.

   GET  q=<at least 3 characters of a name or email>
   Replies with JSON: { ok, results: [{ id, name, email, phone }] }

   THIS IS NOT A CUSTOMER DIRECTORY, and the shape of it says so:

     · three characters minimum, so it cannot be walked with "a"
     · ten results, so a broad term returns a sample and not a database
     · the PHONE IS MASKED (0917•••0499). A staff member at a counter
       needs to confirm the number the person in front of them just
       said, which the last four digits do. Handing over the whole
       number would make this box a way to collect the contact details
       of every customer the university has, which is not what anyone
       asked it for.
     · walk-ins (customers with no user account) are excluded: they have
       no login, so they can never be the "has an account" case, and
       including them would offer staff an account to link that cannot
       be proved by a password.

   Staff only — the same gate as every other admin endpoint.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cs_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

venusep_session_start();
$type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($type, ['admin', 'staff'], true)) {
    cs_reply(403, ['ok' => false, 'results' => [], 'message' => 'Staff only.']);
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (mb_strlen($q) < 3) {
    cs_reply(200, ['ok' => true, 'results' => []]);
}

/* Show enough of a number to confirm it, never enough to write it down. */
function cs_mask_phone($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if ($d === '') {
        return '—';
    }
    if (strlen($d) <= 4) {
        return str_repeat('•', strlen($d));
    }
    return substr($d, 0, 4) . str_repeat('•', max(0, strlen($d) - 8)) . substr($d, -4);
}

$pdo = venusep_db();
if ($pdo === null) {
    cs_reply(503, ['ok' => false, 'results' => [], 'message' => 'The database is unreachable.']);
}

try {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.full_name, c.phone, u.email
           FROM customers c
           JOIN users u ON u.id = c.user_id
          WHERE u.is_active = 1
            AND (c.full_name LIKE :like OR u.email LIKE :like2)
          ORDER BY c.full_name
          LIMIT 10"
    );
    $like = '%' . $q . '%';
    $stmt->execute([':like' => $like, ':like2' => $like]);

    $out = [];
    foreach ($stmt as $row) {
        $out[] = [
            'id'    => (int) $row['id'],
            'name'  => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'phone' => cs_mask_phone($row['phone']),
        ];
    }
    cs_reply(200, ['ok' => true, 'results' => $out]);
} catch (PDOException $e) {
    error_log('VENUSeP customer-search: ' . $e->getMessage());
    cs_reply(500, ['ok' => false, 'results' => [], 'message' => 'Search failed.']);
}
