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

        /* one question: $questionHtml and $answerHtml already escaped */
        $details = fn (string $questionHtml, string $for, string $answerHtml) => '<details><summary><span>' . $questionHtml
            . ($for !== '' ? ' <span class="fq-for">' . e($for) . '</span>' : '') . '</span>'
            . '<span class="fq-plus"><svg class="fq-icon" width="14" height="14"><use href="#fq-i-plus"/></svg></span></summary>'
            . $answerHtml . '</details>';

        /* the <details> list for one section; the section heading stays in the page */
        $fqSections = [];
        foreach (['booking', 'discount', 'hostel', 'gcash', 'cash', 'after'] as $key) {
            if (!faq_sections()) {
                $fqSections[$key] = '<p class="fq-a fq-unavailable">The FAQ is temporarily unavailable. Please try again in a moment.</p>';
                continue;
            }
            $html = $key === 'booking' ? $details('What do I need to book?', '', faq_answer_html(self::whatYouNeed($fqOffices))) : '';
            if ($key === 'after' && $fqOffices) {
                $html .= '<p class="fq-a fq-rules">Each office sets its own rule for new bookings:</p><div class="fq-a"><ul>';
                foreach ($fqOffices as $o) {
                    $html .= '<li><strong>' . e($o['name']) . ':</strong> '
                           . ['pay_to_use' => 'paid before your stay', 'prepay' => 'paid before your event', 'postpay' => 'paid after your event'][office_payment_policy($o)]
                           . ($o['refunds'] ? ', and you can ask for a refund.' : ', and non-refundable.') . '</li>';
                }
                $html .= '</ul></div>';
            }
            foreach ((isset($fqRows[$key]) ? $fqRows[$key] : []) as $r) {
                if (($for = faq_row_for($r, $fqOffices)) === null) {
                    continue;
                }
                $html .= $details(faq_question_html($r['question']), $for, faq_answer_html($r['answer']));
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

    /* "What do I need to book?" (Aron, paper 2026-11-24): the rules booking-submit enforces
       (BookingSubmitController, BookingRules), in FAQ answer markup, read from the live
       settings, so the answer changes with them. Built here, not a faqs row: the lead time
       and refund switch are per office, and no {placeholder} can say that. */
    private static function whatYouNeed(array $offices): string
    {
        $events = array_filter($offices, fn ($o) => $o['type'] !== 'hostel');
        $leads = array_unique(array_column($events, 'lead'));
        $hours = fn ($h) => $h . ' hour' . ($h == 1 ? '' : 's');
        $notice = count($leads) === 1 ? '**' . $hours(reset($leads)) . '**'
            : implode('; ', array_map(fn ($o) => $o['name'] . ': **' . $hours($o['lead']) . '**', $events));
        $noRefund = array_column(array_filter($offices, fn ($o) => !$o['refunds']), 'name');
        $hostel = (bool) array_filter($offices, fn ($o) => $o['type'] === 'hostel');

        return implode("\n", array_filter([
            'Check these before you submit:',
            '- **A valid ID**, for every booking: your USeP ID or any government-issued ID. To get the USeP discount ({discount} off), it must be your **USeP ID**.',
            $events ? '- **Enough notice:** a venue booking must start at least ' . $notice . ' after you book.' . ($hostel ? ' A hostel stay can start today.' : '') : null,
            '- **Dates inside the booking window:** you can book up to **' . (int) config('venusep.max_days_ahead') . ' days** ahead. Your last day, or your check-out day, must fall inside it.',
            '- **No more than ' . (int) config('venusep.max_unpaid') . ' unpaid bookings per office.** Pay or cancel one before booking with that office again.',
            $hostel ? '- **Hostel stays are paid by the end of the check-in day**, or the stay is released.' : null,
            $noRefund ? '- **The no-refund agreement:** ' . \Illuminate\Support\Arr::join($noRefund, ', ', ' and ') . (count($noRefund) === 1 ? ' does' : ' do')
                . ' not take refund requests, so when you book there you tick a box confirming the booking is non-refundable.' : null,
        ]));
    }
}
