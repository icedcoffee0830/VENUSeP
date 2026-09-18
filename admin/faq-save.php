<?php
/* =====================================================================
   FAQ SAVE — the ONLY writer of the faqs table (added 2026-09-18).
   Called by the forms on admin/faq-management.php. Classic POST + redirect
   back to the page with ?msg=… so a refresh never re-submits.

   POST  csrf=<token>  action=add|edit|toggle|delete|restore|move
         add     : section, question, answer, policy
         edit    : id, section, question, answer, policy   (built-ins too)
         toggle  : id                (active <-> hidden)
         delete  : id                (added rows only — built-ins are refused)
         restore : id                (built-ins: back to default_question/answer)
         move    : id, dir=up|down   (swap sort_order with the neighbour)

   Every check happens HERE: logged-in staff or admin, CSRF token, lengths.
   The page's buttons are for convenience, not a security boundary.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/faqs.php';

admin_require_login();                                     // admin or staff

function fq_back($msg, $extra = '')
{
    header('Location: faq-management.php?msg=' . rawurlencode($msg) . $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fq_back('method');
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    fq_back('expired');
}
$pdo = venusep_db();
if ($pdo === null) {
    fq_back('db');
}

$action   = isset($_POST['action']) ? (string) $_POST['action'] : '';
$id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$section  = isset($_POST['section']) ? trim((string) $_POST['section']) : '';
$question = isset($_POST['question']) ? trim((string) $_POST['question']) : '';
$answer   = isset($_POST['answer']) ? trim(str_replace("\r\n", "\n", (string) $_POST['answer'])) : '';
$policy   = isset($_POST['policy']) && in_array($_POST['policy'], ['any', 'refunds_on', 'refunds_off'], true) ? $_POST['policy'] : 'any';
$userId   = (int) $_SESSION['user_id'];
$sections = faq_sections();

try {
    switch ($action) {
        case 'add':
        case 'edit':
            if (!isset($sections[$section])) {
                fq_back('section');
            }
            if ($question === '' || mb_strlen($question) > 255) {
                fq_back('question', '&keep=1');
            }
            if ($answer === '' || mb_strlen($answer) > 4000) {
                fq_back('answer', '&keep=1');
            }
            if ($action === 'add') {
                $next = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faqs WHERE section_key = " . $pdo->quote($section))->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO faqs (section_key, question, answer, policy, sort_order, created_by_user_id, updated_by_user_id)
                                       VALUES (:s, :q, :a, :p, :o, :u, :u2)");
                $stmt->execute([':s' => $section, ':q' => $question, ':a' => $answer, ':p' => $policy, ':o' => $next, ':u' => $userId, ':u2' => $userId]);
                fq_back('added', '&show=' . $policy . '#' . $section);   /* the page previews that policy so the new row is visible */
            }
            $stmt = $pdo->prepare("UPDATE faqs SET section_key = :s, question = :q, answer = :a, policy = :p, updated_by_user_id = :u WHERE id = :id");
            $stmt->execute([':s' => $section, ':q' => $question, ':a' => $answer, ':p' => $policy, ':u' => $userId, ':id' => $id]);
            fq_back('saved', '&show=' . $policy . '#' . $section);

        case 'toggle':
            $stmt = $pdo->prepare("UPDATE faqs SET is_active = NOT is_active, updated_by_user_id = :u WHERE id = :id");
            $stmt->execute([':u' => $userId, ':id' => $id]);
            fq_back('toggled');

        case 'delete':
            /* built-ins ship with the system: hide them, never delete them */
            $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = :id AND is_builtin = 0");
            $stmt->execute([':id' => $id]);
            fq_back($stmt->rowCount() ? 'deleted' : 'builtin');

        case 'restore':
            $stmt = $pdo->prepare("UPDATE faqs SET question = default_question, answer = default_answer, updated_by_user_id = :u
                                    WHERE id = :id AND is_builtin = 1 AND default_question IS NOT NULL");
            $stmt->execute([':u' => $userId, ':id' => $id]);
            fq_back('restored');

        case 'move':
            $dir = isset($_POST['dir']) && $_POST['dir'] === 'up' ? 'up' : 'down';
            $row = $pdo->prepare("SELECT section_key, sort_order FROM faqs WHERE id = :id");
            $row->execute([':id' => $id]);
            $me = $row->fetch();
            if (!$me) {
                fq_back('missing');
            }
            /* the neighbour in the same section, just above or just below */
            $nb = $pdo->prepare($dir === 'up'
                ? "SELECT id, sort_order FROM faqs WHERE section_key = :s AND sort_order < :o ORDER BY sort_order DESC, id DESC LIMIT 1"
                : "SELECT id, sort_order FROM faqs WHERE section_key = :s AND sort_order > :o ORDER BY sort_order ASC, id ASC LIMIT 1");
            $nb->execute([':s' => $me['section_key'], ':o' => $me['sort_order']]);
            $other = $nb->fetch();
            if ($other) {
                $pdo->beginTransaction();
                $swap = $pdo->prepare("UPDATE faqs SET sort_order = :o WHERE id = :id");
                $swap->execute([':o' => $other['sort_order'], ':id' => $id]);
                $swap->execute([':o' => $me['sort_order'], ':id' => $other['id']]);
                $pdo->commit();
            }
            fq_back('moved', '#' . $me['section_key']);

        default:
            fq_back('action');
    }
} catch (PDOException $e) {
    fq_back('db');
}
