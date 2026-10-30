<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   FAQ MANAGEMENT + FAQ SAVE — ported from admin/faq-management.php and
   admin/faq-save.php (added 2026-09-18). Reads legacy faqs.php (faqs +
   faq_sections); save() is the ONLY writer of the faqs table. Classic POST
   + redirect back with ?msg=… so a refresh never re-submits.

   POST  action=add|edit|toggle|delete|restore|move
         add     : section, question, answer, policy
         edit    : id, section, question, answer, policy   (built-ins too)
         toggle  : id                (active <-> hidden)
         delete  : id                (added rows only — built-ins are refused)
         restore : id                (built-ins: back to default_question/answer)
         move    : id, dir=up|down   (swap sort_order with the neighbour)
   Guards: route middleware `staff` (admin or staff) + Laravel's CSRF check.
   ===================================================================== */
class FaqController
{
    public function index(Request $request)
    {
        extract(legacy('faqs'));   // $REFUNDS_ENABLED, $DISCOUNT_PERCENT, $POSTPAY_GRACE_DAYS, $HOSTEL_RATES, $HOSTEL_CR_LABEL

        $fqSections = faq_sections();
        $fqRows     = faqs_by_section(true);                      /* every row, every policy — the page filters in the browser */
        $fqMsg      = (string) $request->query('msg', '');
        $fqNotes = [
            'added'    => ['ok',  'Question added. Customers can see it on the FAQ page now.'],
            'saved'    => ['ok',  'Changes saved.'],
            'toggled'  => ['ok',  'Visibility updated.'],
            'deleted'  => ['ok',  'Question deleted.'],
            'restored' => ['ok',  'Original wording restored.'],
            'moved'    => ['ok',  'Order updated.'],
            'question' => ['bad', 'The question is required and must be 255 characters or fewer.'],
            'answer'   => ['bad', 'The answer is required and must be 4,000 characters or fewer.'],
            'section'  => ['bad', 'Pick one of the six sections.'],
            'builtin'  => ['bad', 'Built-in questions cannot be deleted — hide it instead.'],
            'expired'  => ['bad', 'This page had expired. Nothing was saved — try again.'],
            'db'       => ['bad', 'The database could not be reached. Nothing was saved.'],
            'missing'  => ['bad', 'That question no longer exists.'],
        ];
        $fqTotal = 0; $fqAdded = 0; $fqHidden = 0; $fqChanged = 0;
        foreach ($fqRows as $rows) { foreach ($rows as $r) { $fqTotal++; if (!$r['is_builtin']) $fqAdded++; if (!$r['is_active']) $fqHidden++; if ($r['is_changed']) $fqChanged++; } }

        return view('admin.faq-management', [
            'fqSections' => $fqSections,
            'fqRows'     => $fqRows,
            'fqDbOk'     => !empty($fqSections),
            'fqLive'     => $REFUNDS_ENABLED ? 'refunds_on' : 'refunds_off',
            'fqNote'     => $fqNotes[$fqMsg] ?? null,
            'fqPolicies' => ['any' => 'Always shown', 'refunds_on' => 'Only when refunds are ON', 'refunds_off' => 'Only when refunds are OFF'],
            'fqTotal' => $fqTotal, 'fqAdded' => $fqAdded, 'fqHidden' => $fqHidden, 'fqChanged' => $fqChanged,
            'REFUNDS_ENABLED' => $REFUNDS_ENABLED, 'DISCOUNT_PERCENT' => $DISCOUNT_PERCENT,
            'POSTPAY_GRACE_DAYS' => $POSTPAY_GRACE_DAYS, 'HOSTEL_CR_LABEL' => $HOSTEL_CR_LABEL,
            /* what each {placeholder} becomes (already escaped) — the "Insert a current value" menu shows the same */
            'fqValues' => faq_placeholders(),
            /* the editor keeps the stored link; its serializer strips "…/customer/" back off */
            'fqAnswer' => fn ($answer, $forEditor = false) => str_replace(
                'href="booking-history.php"',
                'href="../customer/booking-history.php"',
                faq_answer_html($answer, $forEditor)
            ),
        ]);
    }

    private function back(string $msg, string $extra = '')
    {
        return redirect(route('admin.faq-management') . '?msg=' . rawurlencode($msg) . $extra);
    }

    public function save(Request $request)
    {
        legacy('faqs');

        if (!$request->isMethod('post')) {
            return $this->back('method');
        }
        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->back('db');
        }

        $action   = (string) $request->input('action', '');
        $id       = (int) $request->input('id', 0);
        $section  = trim((string) $request->input('section', ''));
        $question = trim((string) $request->input('question', ''));
        $answer   = trim(str_replace("\r\n", "\n", (string) $request->input('answer', '')));
        $policy   = in_array($request->input('policy'), ['any', 'refunds_on', 'refunds_off'], true) ? $request->input('policy') : 'any';
        $userId   = (int) session('user_id');
        $sections = faq_sections();

        try {
            switch ($action) {
                case 'add':
                case 'edit':
                    if (!isset($sections[$section])) {
                        return $this->back('section');
                    }
                    if ($question === '' || mb_strlen($question) > 255) {
                        return $this->back('question', '&keep=1');
                    }
                    if ($answer === '' || mb_strlen($answer) > 4000) {
                        return $this->back('answer', '&keep=1');
                    }
                    $nextSql = "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faqs WHERE section_key = " . $pdo->quote($section);
                    if ($action === 'add') {
                        $next = (int) $pdo->query($nextSql)->fetchColumn();
                        $stmt = $pdo->prepare("INSERT INTO faqs (section_key, question, answer, policy, sort_order, created_by_user_id, updated_by_user_id)
                                               VALUES (:s, :q, :a, :p, :o, :u, :u2)");
                        $stmt->execute([':s' => $section, ':q' => $question, ':a' => $answer, ':p' => $policy, ':o' => $next, ':u' => $userId, ':u2' => $userId]);
                        return $this->back('added', '&show=' . $policy . '#' . $section);   /* the page previews that policy so the new row is visible */
                    }
                    $cur = $pdo->prepare("SELECT section_key, sort_order FROM faqs WHERE id = :id");
                    $cur->execute([':id' => $id]);
                    $cur = $cur->fetch();
                    if (!$cur) {
                        return $this->back('missing');
                    }
                    /* moved to another section: it goes to the end of that one, never sharing a position there */
                    $order = $cur['section_key'] === $section ? (int) $cur['sort_order'] : (int) $pdo->query($nextSql)->fetchColumn();
                    $stmt = $pdo->prepare("UPDATE faqs SET section_key = :s, question = :q, answer = :a, policy = :p, sort_order = :o, updated_by_user_id = :u WHERE id = :id");
                    $stmt->execute([':s' => $section, ':q' => $question, ':a' => $answer, ':p' => $policy, ':o' => $order, ':u' => $userId, ':id' => $id]);
                    return $this->back('saved', '&show=' . $policy . '#' . $section);

                case 'toggle':
                    $stmt = $pdo->prepare("UPDATE faqs SET is_active = NOT is_active, updated_by_user_id = :u WHERE id = :id");
                    $stmt->execute([':u' => $userId, ':id' => $id]);
                    return $this->back($stmt->rowCount() ? 'toggled' : 'missing');   /* NOT is_active always changes a row that exists */

                case 'delete':
                    /* built-ins ship with the system: hide them, never delete them */
                    $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = :id AND is_builtin = 0");
                    $stmt->execute([':id' => $id]);
                    if ($stmt->rowCount()) {
                        return $this->back('deleted');
                    }
                    $left = $pdo->prepare("SELECT 1 FROM faqs WHERE id = :id");
                    $left->execute([':id' => $id]);
                    return $this->back($left->fetchColumn() ? 'builtin' : 'missing');   /* gone already: a second click, or another admin */

                case 'restore':
                    $stmt = $pdo->prepare("UPDATE faqs SET question = default_question, answer = default_answer, updated_by_user_id = :u
                                            WHERE id = :id AND is_builtin = 1 AND default_question IS NOT NULL");
                    $stmt->execute([':u' => $userId, ':id' => $id]);
                    return $this->back('restored');

                case 'move':
                    $dir = $request->input('dir') === 'up' ? 'up' : 'down';
                    $row = $pdo->prepare("SELECT section_key, sort_order FROM faqs WHERE id = :id");
                    $row->execute([':id' => $id]);
                    $me = $row->fetch();
                    if (!$me) {
                        return $this->back('missing');
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
                    return $this->back('moved', '#' . $me['section_key']);

                default:
                    return $this->back('action');
            }
        } catch (PDOException $e) {
            return $this->back('db');
        }
    }
}
