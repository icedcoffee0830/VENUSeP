<?php

namespace App\Http\Controllers\Admin;

use App\Queries\FaqQueries;
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
class FaqManagementController
{
    private const NOTES = [
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

    public function index(Request $request)
    {
        $legacy = legacy('faqs', 'offices');
        $DISCOUNT_PERCENT = $legacy['DISCOUNT_PERCENT'];      // set in pricing.php
        $POSTPAY_GRACE_DAYS = $legacy['POSTPAY_GRACE_DAYS'];  // set in refund-policy.php
        $HOSTEL_CR_LABEL = $legacy['HOSTEL_CR_LABEL'];        // set in hostel-rooms.php
        /* one refund switch per office (DB-DECISIONS 28): the preview starts on the state every
           office is in, or on Everything while they differ */
        $fqSwitches = array_column(office_all(), 'refunds', 'name');
        $fqOn = array_filter($fqSwitches);
        $fqLive = !$fqOn ? 'refunds_off' : (count($fqOn) === count($fqSwitches) ? 'refunds_on' : 'all');

        $fqSections = faq_sections();
        $fqRows     = faqs_by_section(true);                      /* every row, every policy — the page filters in the browser */
        $fqMsg      = (string) $request->query('msg', '');
        $fqTotal = 0; $fqAdded = 0; $fqHidden = 0; $fqChanged = 0;
        foreach ($fqRows as $rows) { foreach ($rows as $r) { $fqTotal++; if (!$r['is_builtin']) $fqAdded++; if (!$r['is_active']) $fqHidden++; if ($r['is_changed']) $fqChanged++; } }

        return live_view('admin.faq-management', [
            'fqSections' => $fqSections,
            'fqRows'     => $fqRows,
            'fqDbOk'     => !empty($fqSections),
            'fqLive'     => $fqLive,
            'fqSwitches' => $fqSwitches,
            'fqNote'     => self::NOTES[$fqMsg] ?? null,
            'fqPolicies' => ['any' => 'Always shown', 'refunds_on' => 'Only where refunds are ON', 'refunds_off' => 'Only where refunds are OFF'],
            'fqTotal' => $fqTotal, 'fqAdded' => $fqAdded, 'fqHidden' => $fqHidden, 'fqChanged' => $fqChanged,
            'DISCOUNT_PERCENT' => $DISCOUNT_PERCENT,
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
        /* the page's script (public/assets/js/live.js): the same answer, no redirect */
        if (request()->expectsJson()) {
            [$tone, $text] = self::NOTES[$msg] ?? ['bad', 'Nothing was saved.'];
            preg_match('/show=(\w+)/', $extra, $show);
            return response()->json(['ok' => $tone === 'ok', 'message' => $text, 'show' => $show[1] ?? null]);
        }
        return redirect(route('admin.faq-management') . '?msg=' . rawurlencode($msg) . $extra);
    }

    public function save(Request $request)
    {
        legacy('faqs');

        if (!$request->isMethod('post')) {
            return $this->back('method');
        }
        if (venusep_db() === null) {
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
                    if ($action === 'add') {
                        FaqQueries::add($section, $question, $answer, $policy, FaqQueries::nextPosition($section), $userId);
                        return $this->back('added', '&show=' . $policy . '#' . $section);   /* the page previews that policy so the new row is visible */
                    }
                    $cur = FaqQueries::findPosition($id);
                    if (!$cur) {
                        return $this->back('missing');
                    }
                    /* moved to another section: it goes to the end of that one, never sharing a position there */
                    $order = $cur['section_key'] === $section ? (int) $cur['sort_order'] : FaqQueries::nextPosition($section);
                    FaqQueries::save($id, $section, $question, $answer, $policy, $order, $userId);
                    return $this->back('saved', '&show=' . $policy . '#' . $section);

                case 'toggle':
                    return $this->back(FaqQueries::toggle($id, $userId) ? 'toggled' : 'missing');   /* NOT is_active always changes a row that exists */

                case 'delete':
                    /* built-ins ship with the system: hide them, never delete them */
                    if (FaqQueries::delete($id)) {
                        return $this->back('deleted');
                    }
                    return $this->back(FaqQueries::exists($id) ? 'builtin' : 'missing');   /* gone already: a second click, or another admin */

                case 'restore':
                    FaqQueries::restore($id, $userId);
                    return $this->back('restored');

                case 'move':
                    $dir = $request->input('dir') === 'up' ? 'up' : 'down';
                    $me = FaqQueries::findPosition($id);
                    if (!$me) {
                        return $this->back('missing');
                    }
                    /* the neighbour in the same section, just above or just below */
                    $other = FaqQueries::findNeighbour($me['section_key'], (int) $me['sort_order'], $dir === 'up');
                    if ($other) {
                        FaqQueries::swap($id, (int) $me['sort_order'], (int) $other['id'], (int) $other['sort_order']);
                    }
                    return $this->back('moved', '#' . $me['section_key']);

                default:
                    return $this->back('action');
            }
        } catch (PDOException $e) {
            sql_failed($e);
            return $this->back('db');
        }
    }
}
