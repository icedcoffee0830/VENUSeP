<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;

/* FAQ (was customer/faq.php) — public customer help page. Every question
   comes from the database (legacy faqs.php): only active rows, for each office's
   own refund switch (faq_row_for; DB-DECISIONS 28), and the "After you book"
   section opens with each office's rule. Live rates and the discount are filled in.
   Two frames, one page: ?in=app sits inside the account shell; otherwise the
   standalone page with the browse-mode nav and footer. Writes nothing. */
class FaqController
{
    public function __invoke(Request $request)
    {
        $vars = legacy('auth', 'offices', 'customer-bookings', 'faqs');   /* $customerContact for the header chip */

        /* faq_placeholders() reads these with `global` — the old pages ran the
           includes at file scope, so they WERE globals. legacy() keeps them in
           its registry instead, so hand them over (TODO(integrator): the bridge
           could do this for every legacy variable). */
        foreach (['DISCOUNT_PERCENT', 'POSTPAY_GRACE_DAYS', 'HOSTEL_RATES', 'HOSTEL_CR_LABEL', 'REFUNDS_ENABLED'] as $g) {
            if (!array_key_exists($g, $GLOBALS) && array_key_exists($g, $vars)) {
                $GLOBALS[$g] = $vars[$g];
            }
        }

        $fqRows = faqs_by_section(false);
        $fqOffices = array_filter(office_all(), fn ($o) => staff_sees_venue($o['id']));
        $fqAnyRefunds = (bool) array_filter($fqOffices, fn ($o) => $o['refunds']);

        /* the <details> list for one section; the section heading stays in the page */
        $fqSections = [];
        foreach (['booking', 'discount', 'hostel', 'gcash', 'cash', 'after'] as $key) {
            if (!faq_sections()) {
                $fqSections[$key] = '<p class="fq-a fq-unavailable">The FAQ is temporarily unavailable. Please try again in a moment.</p>';
                continue;
            }
            $html = '';
            if ($key === 'after' && $fqOffices) {
                $html .= '<p class="fq-a fq-rules">Each office sets its own rule for new bookings:</p><div class="fq-a"><ul>';
                foreach ($fqOffices as $o) {
                    $html .= '<li><strong>' . e($o['name']) . ':</strong> '
                           . ($o['type'] === 'hostel' ? 'paid before your stay' : ($o['refunds'] ? 'paid before your event' : 'paid after your event'))
                           . ($o['refunds'] ? ', and you can ask for a refund.' : ', and non-refundable.') . '</li>';
                }
                $html .= '</ul></div>';
            }
            foreach ((isset($fqRows[$key]) ? $fqRows[$key] : []) as $r) {
                if (($for = faq_row_for($r, $fqOffices)) === null) {
                    continue;
                }
                $html .= '<details><summary><span>' . faq_question_html($r['question'])
                       . ($for !== '' ? ' <span class="fq-for">' . e($for) . '</span>' : '') . '</span>'
                       . '<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>'
                       . faq_answer_html($r['answer']) . '</details>';
            }
            $fqSections[$key] = $html;
        }

        return view('customer.faq', [
            'customerContact' => $vars['customerContact'],
            'fqAnyRefunds'    => $fqAnyRefunds,
            'fqSections'      => $fqSections,
            'fqApp'           => $request->query('in') === 'app',
            /* staff at the counter: links keep ?counter=1, customer-only links are hidden */
            'fqCounterQ'      => counter_mode() ? '?counter=1' : '',
        ]);
    }
}
