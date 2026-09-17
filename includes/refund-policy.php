<?php
/* =====================================================================
   REFUND POLICY — is the customer refund module switched on?
   THE ONE SOURCE for the switch, read from system_settings.refunds_enabled.

   Included by:
     includes/customer-bookings.php  (so every page that knows the customer's
                                      bookings also knows the policy)
     admin/payment-settings.php      (the switch itself)

   DECIDED 2026-09-16 (USeP does not do refunds):
     · ONE global switch, admin-only, DEFAULT OFF.
     · It covers CUSTOMER-REQUESTED refunds only. A closure by USeP
       (maintenance etc.) is never affected: those customers are offered a
       replacement room or a new date, and refunded if they decline both.
     · Each booking snapshots the switch when it is MADE
       (bookings.refunds_allowed). Flipping it later never grants or removes
       refunds on an existing booking — customers are held to the policy
       they agreed to.
     · Open requests are finished by staff even after the switch goes OFF.

   FAIL SAFE: if the database cannot be reached this reports OFF. Wrongly
   saying "non-refundable" is recoverable; wrongly accepting a refund
   request the school will not honour is not.

   This is the ONE part of the refund flow that is real. Bookings themselves
   are still [SIM] (includes/customer-bookings.php).
   ===================================================================== */
require_once __DIR__ . '/db.php';

/* Full details for the admin card; null when the database is unreachable. */
function refund_setting_details()
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $pdo = venusep_db();
    if ($pdo === null) {
        return $cache;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT s.setting_value, s.updated_at, u.email AS updated_by_email
               FROM system_settings s
               LEFT JOIN users u ON u.id = s.updated_by_user_id
              WHERE s.setting_key = 'refunds_enabled'
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            $cache = [
                'enabled' => $row['setting_value'] === '1',
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by_email'],   // null = never changed since setup
            ];
        }
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

function refunds_enabled()
{
    $d = refund_setting_details();
    return $d !== null && $d['enabled'];
}

$REFUNDS_ENABLED = refunds_enabled();

/* =====================================================================
   PAYMENT TIMING (DB-DECISIONS #18, 2026-09-17) — the SAME switch decides
   WHEN a booking is paid, and a booking keeps the policy it was made under
   (bookings.refunds_allowed):

     refunds ON  = PRE-PAY : pay after approval, by 23:59 the day before the
                             first day (if that is already past: before it starts).
                             Unpaid at the deadline -> the hold is released.
     refunds OFF = POST-PAY: nothing can be paid until the LAST day is over;
                             then pay within postpay_grace_days (default 3).
                             Unpaid at the deadline -> OVERDUE, never released,
                             and it can still be paid late.

   Mirrors fn_payment_deadline() in venusep_schema.sql. The PHP copy exists so
   the [SIM] pages can label bookings before the DB is wired; the JS copy
   (payment_policy_js) so the two booking pages can label a booking that is
   still being typed. All three say the same thing — change one, change all.
   ===================================================================== */
function postpay_grace_days()
{
    static $days = null;
    if ($days !== null) {
        return $days;
    }
    $days = 3;
    $pdo = venusep_db();
    if ($pdo === null) {
        return $days;
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'postpay_grace_days' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        if ($v !== false && (int)$v > 0) {
            $days = (int)$v;
        }
    } catch (PDOException $e) {
        // keep the default
    }
    return $days;
}

/* Everything a page needs to say about one booking's payment timing.
     $prepay    the booking's refunds_allowed snapshot (TRUE = pre-pay)
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
        'open'       => $now >= $over,
        'late'       => false,
        'overdue'    => $now > $payBy,
        'opensLabel' => 'after ' . date('M j, Y', $last),
    ];
}

/* The same rule for the browser: the two booking pages render with JS, so
   they need it before a booking exists. Echo inside a <script>. */
function payment_policy_js()
{
    return 'const PAY_POLICY = { prepay: ' . ($GLOBALS['REFUNDS_ENABLED'] ? 'true' : 'false') . ', graceDays: ' . postpay_grace_days() . " };
"
        . "/* payPolicyFor(firstIso, lastIso) -> { policy, payBy: Date, label, open, late, opensLabel, grace } — see includes/refund-policy.php */
"
        . "function payPolicyFor(firstIso, lastIso){
"
        . "  const grace=PAY_POLICY.graceDays;
"
        . "  if(!firstIso) return { policy: PAY_POLICY.prepay?'prepay':'postpay', payBy:null, label:'—', open:false, late:false, opensLabel:'', grace };
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
        . "  return { policy:'postpay', payBy, label:fmt(payBy), open: now>=over, late:false, opensLabel:'after '+fmt(last), grace };
"
        . "}
";
}
$POSTPAY_GRACE_DAYS = postpay_grace_days();
