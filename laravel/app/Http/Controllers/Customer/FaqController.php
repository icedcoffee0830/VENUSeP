<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;

/* FAQ (was customer/faq.php) — public customer help page. Every question
   comes from the database (legacy faqs.php): only active rows for the CURRENT
   refund policy. Live rates and the discount are filled into the answers.
   Two frames, one page: ?in=app sits inside the account shell; otherwise the
   standalone page with the browse-mode nav and footer. Writes nothing. */
class FaqController
{
    public function __invoke(Request $request)
    {
        $vars = legacy('customer-bookings', 'faqs');   /* $customerContact for the header chip, $REFUNDS_ENABLED */

        /* faq_placeholders() reads these with `global` — the old pages ran the
           includes at file scope, so they WERE globals. legacy() keeps them in
           its registry instead, so hand them over (TODO(integrator): the bridge
           could do this for every legacy variable). */
        foreach (['DISCOUNT_PERCENT', 'POSTPAY_GRACE_DAYS', 'HOSTEL_RATES', 'HOSTEL_CR_LABEL', 'REFUNDS_ENABLED'] as $g) {
            if (!array_key_exists($g, $GLOBALS) && array_key_exists($g, $vars)) {
                $GLOBALS[$g] = $vars[$g];
            }
        }

        $fqRows = faqs_by_section(false, (bool) $vars['REFUNDS_ENABLED']);

        /* the <details> list for one section; the section heading stays in the page */
        $fqSections = [];
        foreach (['booking', 'discount', 'hostel', 'gcash', 'cash', 'after'] as $key) {
            if (!faq_sections()) {
                $fqSections[$key] = '<p class="fq-a fq-unavailable">The FAQ is temporarily unavailable. Please try again in a moment.</p>';
                continue;
            }
            $html = '';
            foreach ((isset($fqRows[$key]) ? $fqRows[$key] : []) as $r) {
                $html .= '<details><summary>' . faq_question_html($r['question'])
                       . '<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>'
                       . faq_answer_html($r['answer']) . '</details>';
            }
            $fqSections[$key] = $html;
        }

        return view('customer.faq', [
            'customerContact' => $vars['customerContact'],
            'REFUNDS_ENABLED' => $vars['REFUNDS_ENABLED'],
            'fqSections'      => $fqSections,
            'fqApp'           => $request->query('in') === 'app',
        ]);
    }
}
