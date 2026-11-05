<?php
/* =====================================================================
   REFUND POLICY — does an office take refund requests?
   ONE SWITCH PER OFFICE (DB-DECISIONS 28, 2026-11-04): venues.refunds_enabled,
   read through office_all() ('refunds'), changed by the super admin only on
   Payment Settings (RefundSwitchController).

   Included by:
     includes/customer-bookings.php  (so every page that knows the customer's
                                      bookings also knows the policy)
     admin/payment-settings.php      (the switch itself)

   DECIDED 2026-09-16 (USeP does not do refunds), per office since 2026-11-04:
     · DEFAULT OFF. Bahay Alumni and USeP Venues: ON = pay before + refunds,
       OFF = pay after + no refunds. USeP Hostel always pays before the stay
       (bookings.pay_to_use); its switch only turns refunds on or off.
     · It covers CUSTOMER-REQUESTED refunds only.
     · Each booking snapshots its office's switch when it is MADE
       (bookings.refunds_allowed). Flipping it later never grants or removes
       refunds on an existing booking — customers are held to the policy
       they agreed to.
     · Open requests are finished by staff even after the switch goes OFF.

   FAIL SAFE: if the database cannot be reached this reports OFF. Wrongly
   saying "non-refundable" is recoverable; wrongly accepting a refund
   request the school will not honour is not.
   ===================================================================== */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/offices.php';

/* The switch of the office this page is about (?room=, a booking page); off on a page
   about no one office: those pages (the public page, the FAQ) describe each office's rule. */
$REFUNDS_ENABLED = (office_page()['refunds'] ?? false);

/* =====================================================================
   PAYMENT TIMING (DB-DECISIONS #18, 2026-09-17) — the office's switch also decides
   WHEN a venue booking is paid, and a booking keeps the policy it was made under
   (bookings.refunds_allowed):

     refunds ON  = PRE-PAY : pay after approval, by 23:59 the day before the
                             first day (if that is already past: before it starts).
                             Unpaid at the deadline -> the hold is released.
     refunds OFF = POST-PAY: nothing can be paid until the LAST day is over;
                             then pay within postpay_grace_days (default 3).
                             Unpaid at the deadline -> OVERDUE, never released,
                             and it can still be paid late.

   Mirrors fn_payment_deadline() in venusep_schema.sql. The PHP copy exists so
   the pages can label a booking before it exists; the JS copy
   (payment_policy_js) so the two booking pages can label a booking that is
   still being typed. All three say the same thing — change one, change all.
   ===================================================================== */
function postpay_grace_days()
{
    require_once __DIR__ . '/offices.php';
    $grace = array_column(office_all(), 'grace');
    return office_page()['grace'] ?? ($grace ? max($grace) : 3);
}

/* Everything a page needs to say about one booking's payment timing.
     $prepay    the booking's refunds_allowed snapshot (TRUE = pre-pay), or
                'pay_to_use' for a hostel stay paid before the bed is used
                (bookings.pay_to_use, 2026-10-27): due by the END of the
                check-in day, open from approval
     $firstIso  first booked day  'Y-m-d'   (event start / check-in)
     $lastIso   last booked day   'Y-m-d'   (event end / check-out); defaults to first
     $now       unix time, for tests
   Returns: policy 'prepay'|'postpay'; payByTs; payByLabel 'Oct 21, 2026';
            open (payment can be made now); late (pre-pay: deadline already
            passed at approval -> pay immediately); overdue (post-pay: window
            missed); opensLabel (post-pay: 'after Oct 18, 2026'). */
function payment_policy_for($prepay, $firstIso, $lastIso = null, $now = null)
{
    $now   = $now ?? time();
    $first = strtotime($firstIso . ' 00:00:00');
    $last  = strtotime(($lastIso ?: $firstIso) . ' 00:00:00');
    if ($prepay === 'pay_to_use') {
        $payBy = strtotime('23:59:59', $first);
        return [
            'policy'     => 'pay_to_use',
            'payByTs'    => $payBy,
            'payByLabel' => date('M j, Y', $payBy),
            'open'       => true,
            'late'       => false,
            'overdue'    => false,
            'opensLabel' => 'on approval',
        ];
    }
    if ($prepay) {
        $payBy = strtotime('-1 day 23:59:59', $first);
        $late  = $payBy <= $now;
        return [
            'policy'     => 'prepay',
            'payByTs'    => $payBy,
            'payByLabel' => date('M j, Y', $payBy),
            'open'       => true,
            'late'       => $late,
            'overdue'    => false,
            'opensLabel' => 'on approval',
        ];
    }
    $grace = postpay_grace_days();
    $payBy = strtotime('+' . $grace . ' days 23:59:59', $last);
    $over  = strtotime('+1 day 00:00:00', $last);            // payment opens the day after the last day
    return [
        'policy'     => 'postpay',
        'payByTs'    => $payBy,
        'payByLabel' => date('M j, Y', $payBy),
        'askByLabel' => date('M j, Y', $over),               // what the customer is told — see customer_pay_by_label()
        'open'       => $now >= $over,
        'late'       => false,
        'overdue'    => $now > $payBy,
        'opensLabel' => 'after ' . date('M j, Y', $last),
    ];
}

/* The pay-by date the CUSTOMER is told (Aron, 2026-10-30). Post-pay: "pay by
   the day after your event". The stored deadline (fn_payment_deadline: last day
   + postpay_grace_days) is untouched and is still what turns the booking
   Overdue — the grace days are a quiet buffer, not the date we ask for.
   Pre-pay and pay-to-use: the stored deadline itself. */
function customer_pay_by_label(array $payment)
{
    return isset($payment['askByLabel']) ? $payment['askByLabel'] : $payment['payByLabel'];
}

/* The same rule for the browser: the two booking pages render with JS, so
   they need it before a booking exists. Echo inside a <script>. */
function payment_policy_js()
{
    return 'const PAY_POLICY = { prepay: ' . ($GLOBALS['REFUNDS_ENABLED'] ? 'true' : 'false') . ', graceDays: ' . postpay_grace_days() . " };
"
        . "/* payPolicyFor(firstIso, lastIso) -> { policy, payBy: Date, label, open, late, opensLabel, grace, overdueAfter } — see includes/refund-policy.php.
"
        . "   Post-pay: label = the day after the event, the date the customer is told (customer_pay_by_label());
"
        . "   payBy / overdueAfter = the grace end, when it actually turns Overdue (staff wording). */
"
        . "function payPolicyFor(firstIso, lastIso){
"
        . "  const grace=PAY_POLICY.graceDays;
"
        . "  if(!firstIso) return { policy: PAY_POLICY.prepay?'prepay':'postpay', payBy:null, label:'—', overdueAfter:'—', open:false, late:false, opensLabel:'', grace };
"
        . "  const first=new Date(firstIso+'T00:00:00'), last=new Date((lastIso||firstIso)+'T00:00:00'), now=new Date();
"
        . "  const fmt=d=>d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
"
        . "  if(PAY_POLICY.prepay){
"
        . "    const payBy=new Date(first.getTime()-1000);           /* 23:59:59 the day before */
"
        . "    const late=payBy<=now;
"
        . "    return { policy:'prepay', payBy, label: late?'immediately upon approval — your event is close':fmt(payBy), open:true, late, opensLabel:'on approval', grace };
"
        . "  }
"
        . "  const payBy=new Date(last.getTime()+(PAY_POLICY.graceDays+1)*86400000-1000);   /* 23:59:59, graceDays after the last day */
"
        . "  const over=new Date(last.getTime()+86400000);
"
        . "  return { policy:'postpay', payBy, label:fmt(over), overdueAfter:fmt(payBy), open: now>=over, late:false, opensLabel:'after '+fmt(last), grace };
"
        . "}
";
}
$POSTPAY_GRACE_DAYS = postpay_grace_days();
