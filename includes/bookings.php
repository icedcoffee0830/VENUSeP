<?php
/* =====================================================================
   BOOKINGS — THE ONE READ PATH. Both portals, one query builder.

   DB-TRANSITION #1 is explicit about why this file exists: the customer's
   submit and the admin's queue must be the SAME table seen two ways, and
   they must be designed together rather than as two shapes that drift.
   Before the database, they genuinely were two shapes — the customer's
   history was one hand-written array and the admin queue another, and
   they disagreed about which bookings existed, what they cost and what
   state they were in.

   Two entry points, one row shape:
     bookings_for_customer($customerId)   the customer's own history
     bookings_all($opts)                  every booking, for staff

   STATUS LABELS come from reservation_statuses / payment_statuses, which
   carry BOTH a staff_label and a customer_label. Staff see the full
   taxonomy ("Awaiting POS - CEDU"); customers see the softened one
   ("Awaiting POS"). Neither side invents wording, so the two can never
   describe the same booking differently.

   THE REFERENCE is derived, never stored: 'VB-<year>-<id>'. The database
   owns booking identity (DB-TRANSITION #2) — a second stored reference
   column would be a second source of truth for the same fact.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/refund-policy.php';   /* payment_policy_for(), $REFUNDS_ENABLED */
require_once __DIR__ . '/documents.php';       /* doc_path() — only advertise files that exist */

/* The customer-facing reference for a booking. Shared by both portals so a
   customer quoting "VB-2026-142" and a staff member searching for it are
   talking about the same row. */
function booking_reference($id, $submittedAt = null) {
    $year = $submittedAt ? date('Y', strtotime($submittedAt)) : date('Y');
    return 'VB-' . $year . '-' . str_pad((string) (int) $id, 3, '0', STR_PAD_LEFT);
}

/* The numeric id inside a reference, or null when it is not one of ours. */
function booking_id_from_reference($ref) {
    return preg_match('/^VB-\d{4}-(\d+)$/', (string) $ref, $m) ? (int) $m[1] : null;
}

/* ---------------------------------------------------------------------
   The core query. $opts:
     customer_id   int    only this customer's bookings
     booking_id    int    a single booking
     limit         int    cap the rows (the queue pages page in the browser)
   --------------------------------------------------------------------- */
function bookings_query(array $opts = []) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return [];
    }

    $where = [];
    $args  = [];
    if (!empty($opts['customer_id'])) {
        $where[] = 'b.customer_id = :cid';
        $args[':cid'] = (int) $opts['customer_id'];
    }
    if (!empty($opts['booking_id'])) {
        $where[] = 'b.id = :bid';
        $args[':bid'] = (int) $opts['booking_id'];
    }
    $sql = "
        SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
               b.is_usep_affiliated, b.affiliation_verified,
               b.room_price, b.discount_percent, b.discount_amount, b.total_amount,
               b.refunds_allowed, b.current_deadline_at, b.submitted_at, b.approved_at,
               b.completed_at, b.cancelled_at, b.customer_notes, b.staff_notes,
               c.id AS customer_id, c.full_name AS customer_name, c.phone AS customer_phone,
               c.university_id_no, u.email AS customer_email,
               r.room_code, r.name AS room_name,
               v.name AS venue_name,
               erd.attendee_capacity, erd.fee_per_day,
               hrd.cr_type, hrd.rate_per_head_per_night,
               vd.event_name, vd.purpose, vd.start_date, vd.end_date, vd.attendee_count,
               hd.check_in_date, hd.check_out_date, hd.pos_number, hd.official_receipt_no,
               hd.checked_in,
               rs.customer_label AS res_customer_label, rs.staff_label AS res_staff_label,
               ps.customer_label AS pay_customer_label, ps.staff_label AS pay_staff_label,
               (SELECT COUNT(*) FROM hostel_occupants ho WHERE ho.booking_id = b.id) AS bed_count,
               (SELECT rf.refund_status FROM refunds rf WHERE rf.booking_id = b.id
                 ORDER BY rf.id DESC LIMIT 1) AS refund_status,
               (SELECT rf.requested_at FROM refunds rf WHERE rf.booking_id = b.id
                 ORDER BY rf.id DESC LIMIT 1) AS refund_requested_at,
               (SELECT rf.official_receipt_pending FROM refunds rf WHERE rf.booking_id = b.id
                 ORDER BY rf.id DESC LIMIT 1) AS refund_or_pending,
               /* The latest receipt's verdict. 'under_review' means a receipt is
                  in; WHICH queue tab it lands in depends on whether the scanner
                  passed it (staff confirm) or sent it to a human (staff judge). */
               /* When money actually moved, for the transaction ledger. */
               (SELECT p.paid_at FROM payments p WHERE p.booking_id = b.id
                 ORDER BY p.id DESC LIMIT 1) AS paid_at,
               (SELECT gr.verdict FROM gcash_receipts gr WHERE gr.booking_id = b.id
                 ORDER BY gr.id DESC LIMIT 1) AS receipt_verdict,
               (SELECT gr.reference_number FROM gcash_receipts gr WHERE gr.booking_id = b.id
                 ORDER BY gr.id DESC LIMIT 1) AS receipt_reference,
               (SELECT COALESCE(JSON_LENGTH(gr.flags_json), 0) FROM gcash_receipts gr
                 WHERE gr.booking_id = b.id ORDER BY gr.id DESC LIMIT 1) AS receipt_flags
          FROM bookings b
          JOIN customers c                      ON c.id = b.customer_id
          LEFT JOIN users u                     ON u.id = c.user_id
          JOIN rooms r                          ON r.id = b.room_id
          JOIN venues v                         ON v.id = r.venue_id
          LEFT JOIN event_room_details erd      ON erd.room_id = r.id
          LEFT JOIN hostel_room_details hrd     ON hrd.room_id = r.id
          LEFT JOIN venue_booking_details vd    ON vd.booking_id = b.id
          LEFT JOIN hostel_booking_details hd   ON hd.booking_id = b.id
          JOIN reservation_statuses rs          ON rs.code = b.reservation_status
          JOIN payment_statuses ps              ON ps.code = b.payment_status
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY COALESCE(vd.start_date, hd.check_in_date) DESC, b.id DESC
        " . (!empty($opts['limit']) ? 'LIMIT ' . (int) $opts['limit'] : '');

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = booking_shape($row);
    }
    return $out;
}

/* One database row -> the array shape every page consumes. Kept in ONE
   function so the customer's history and the staff queue cannot describe the
   same booking differently. */
function booking_shape(array $row) {
    $isHostel = $row['booking_type'] === 'hostel';
    $firstIso = $isHostel ? $row['check_in_date']  : $row['start_date'];
    $lastIso  = $isHostel ? $row['check_out_date'] : $row['end_date'];

    /* The LAST BOOKED DAY for the payment deadline is the check-out date itself
       for a hostel stay, and the end date for an event — exactly what
       fn_booking_last_day() returns, which is what actually sets
       bookings.current_deadline_at.

       Do NOT "correct" this to the last night stayed. Nights are exclusive of
       check-out for COUNTING and PRICING (Aug 1 -> Aug 4 is 3 nights), and that
       is a different question from when the stay ends for payment purposes.
       DB-DECISIONS #18 is explicit: post-pay is due grace days after "the last
       booked day (event end / check-out)". Subtracting a day here made this
       page display a deadline one day earlier than the one the database
       enforces — the app and the database disagreeing about the same fact. */
    $lastBooked = $lastIso;

    $nights = $isHostel && $firstIso && $lastIso
        ? max(0, (int) round((strtotime($lastIso) - strtotime($firstIso)) / 86400))
        : 0;
    $days = !$isHostel && $firstIso && $lastIso
        ? max(1, (int) round((strtotime($lastIso) - strtotime($firstIso)) / 86400) + 1)
        : 0;

    $b = [
        'id'            => (int) $row['id'],
        'bookingId'     => booking_reference($row['id'], $row['submitted_at']),
        'type'          => $row['booking_type'],

        'customerId'    => (int) $row['customer_id'],
        'customerName'  => $row['customer_name'],
        'customerEmail' => (string) $row['customer_email'],      // '' = walk-in, no login
        'customerPhone' => (string) $row['customer_phone'],
        'isWalkIn'      => $row['customer_email'] === null,

        'roomCode'      => $row['room_code'],
        'roomName'      => $row['room_name'],
        'venue'         => $row['room_name'],                    // history shows the ROOM
        'venueName'     => $row['venue_name'],
        'capacity'      => (int) $row['attendee_capacity'],

        'eventName'     => $isHostel
                            ? ($row['bed_count'] . ' bed' . ($row['bed_count'] == 1 ? '' : 's') . ' · ' . $nights . ' night' . ($nights == 1 ? '' : 's'))
                            : (string) $row['event_name'],
        'attendees'     => (int) $row['attendee_count'],

        'eventDate'     => $firstIso ? date('F j, Y', strtotime($firstIso)) : '—',
        'eventDateIso'  => (string) $firstIso,
        'endDateIso'    => (string) $lastIso,
        'days'          => $days,
        'nights'        => $nights,
        'beds'          => (int) $row['bed_count'],
        'crType'        => $row['cr_type'],

        'bookingDate'   => date('F j, Y', strtotime($row['submitted_at'])),
        'bookingDateIso'=> date('Y-m-d', strtotime($row['submitted_at'])),

        'roomPrice'     => (float) $row['room_price'],
        'discountPercent' => (float) $row['discount_percent'],
        'discountAmount'  => (float) $row['discount_amount'],
        'amountValue'   => (float) $row['total_amount'],
        'amount'        => '₱' . number_format((float) $row['total_amount']),

        /* Customers read the softened label, staff the full taxonomy. Both come
           from the lookup tables — neither side writes its own wording. */
        'bookingStatus' => $row['res_customer_label'],
        'paymentStatus' => $row['pay_customer_label'],
        'bookingStatusStaff' => $row['res_staff_label'],
        'paymentStatusStaff' => $row['pay_staff_label'],
        'reservationCode'    => $row['reservation_status'],
        'paymentCode'        => $row['payment_status'],

        'method'        => $row['payment_method'] === 'cash' ? 'Cash' : 'GCash',
        'isUsep'        => (bool) $row['is_usep_affiliated'],
        'usepVerified'  => (bool) $row['affiliation_verified'],
        'refundsAllowed'=> (bool) $row['refunds_allowed'],
        'refundStatus'  => $row['refund_status'],
        'refundFiledIso'=> $row['refund_requested_at'] ? date('Y-m-d', strtotime($row['refund_requested_at'])) : null,
        'refundOrPending' => (bool) $row['refund_or_pending'],
        'paidAtIso'       => $row['paid_at'] ? date('Y-m-d', strtotime($row['paid_at'])) : null,
        'receiptVerdict'  => $row['receipt_verdict'],       // accepted | manual_review | rejected | null
        'receiptReference'=> (string) $row['receipt_reference'],
        'receiptFlags'    => (int) $row['receipt_flags'],

        'pos'           => (string) $row['pos_number'],
        'officialReceipt' => (string) $row['official_receipt_no'],
        'checkedIn'     => (bool) $row['checked_in'],

        'customerNotes' => (string) $row['customer_notes'],
        'staffNotes'    => (string) $row['staff_notes'],
    ];

    /* WHEN this booking pays. payment_policy_for() gives the shape — which
       policy, whether payment is open, whether it is overdue — using the same
       rule the booking pages apply while a booking is still being typed.

       But the DATE comes from bookings.current_deadline_at, which
       fn_payment_deadline() wrote and which the system actually enforces
       against. Recomputing it here would be a second opinion on a stored fact,
       and the two did drift: the SQL has a branch PHP does not, where a pre-pay
       booking whose day-before deadline has already passed becomes due at the
       moment the event STARTS ("pay immediately on approval"), not on a date
       that is already behind us. Thirty-seven bookings displayed a pay-by date
       one day earlier than the one being enforced. */
    $b['payment'] = payment_policy_for($b['refundsAllowed'], $firstIso ?: date('Y-m-d'), $lastBooked);
    if (!empty($row['current_deadline_at'])) {
        $ts = strtotime($row['current_deadline_at']);
        $b['payment']['payByTs']    = $ts;
        $b['payment']['payByLabel'] = date('M j, Y', $ts);
        $b['payment']['overdue']    = $b['payment']['policy'] === 'postpay' && $ts < time();
        $b['payment']['late']       = $b['payment']['policy'] === 'prepay'
                                        && $ts <= strtotime(($firstIso ?: date('Y-m-d')) . ' 23:59:59');
    }
    $b['refundable'] = cb_is_refundable($b);
    return $b;
}

/* The logged-in customer's own bookings. */
function bookings_for_customer($customerId) {
    return bookings_query(['customer_id' => (int) $customerId]);
}

/* Every booking — the staff queue, the admin calendar, the reports. */
function bookings_all(array $opts = []) {
    return bookings_query($opts);
}

/* One booking by its reference, or null. Used by the refund form and the
   admin detail page, so both refuse an unknown reference the same way. */
function booking_by_reference($ref) {
    $id = booking_id_from_reference($ref);
    if ($id === null) {
        return null;
    }
    $rows = bookings_query(['booking_id' => $id]);
    return $rows ? $rows[0] : null;
}

/* The named guests on a hostel booking, with the exact bed each one holds.
   Loaded on demand — only the request-detail page shows a roster. */
function booking_occupants($bookingId) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT o.full_name, o.gender, o.is_primary_guest, hb.bed_label, hb.bed_code
               FROM hostel_occupants o
               LEFT JOIN bed_reservations br ON br.occupant_id = o.id
               LEFT JOIN hostel_beds hb      ON hb.id = br.bed_id
              WHERE o.booking_id = :b
              ORDER BY o.is_primary_guest DESC, o.id"
        );
        $stmt->execute([':b' => (int) $bookingId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* =====================================================================
   THE QUEUE ROW — what staff see in a list, built ONCE.

   Both the Booking Requests queue and the Dashboard's "needs action"
   preview render this shape. They were separate hand-written arrays that
   had already drifted: the dashboard showed Grace Tan overdue on Garden
   Pavilion while the queue showed the same request under a different
   customer. One builder means the dashboard count and the queue tab
   cannot disagree about how much work is waiting.
   ===================================================================== */

/* Which needs-action tab a booking belongs in, or '' for "nobody is waiting".
   A queue answers "what needs me now", so a booking waiting on the CUSTOMER
   (awaiting payment, returned for correction) deliberately gets no tab and is
   left out of the needs-action count. */
function booking_queue_category(array $b) {
    if ($b['reservationCode'] === 'pending')   return 'id';    // the ID still needs reviewing
    if ($b['paymentCode'] === 'await_pos')     return 'pos';   // waiting on CEDU — another OFFICE
    if ($b['paymentCode'] === 'overdue')       return 'overdue';
    if (in_array($b['paymentCode'], ['refund_requested', 'refund_await_or'], true)) return 'refund';
    if ($b['paymentCode'] === 'under_review') {
        /* "Confirm a pass" and "judge a flagged receipt" are different jobs,
           even though the payment state is the same one. */
        return $b['receiptVerdict'] === 'accepted' ? 'confirm' : 'review';
    }
    return '';
}

function booking_queue_pay_key(array $b) {
    if ($b['paymentCode'] === 'under_review') {
        return $b['receiptVerdict'] === 'accepted' ? 'auto_pass' : 'review';
    }
    return $b['paymentCode'];
}

/* The one line telling staff what this row needs. Computed from the booking, so
   it stays true as days pass instead of ageing into a hard-coded lie. */
function booking_queue_action(array $b) {
    $due = $b['payment']['payByLabel'];
    switch ($b['paymentCode']) {
        case 'locked':
            return $b['reservationCode'] === 'pending' ? 'Review the submitted ID' : 'Payment locked';
        case 'await_pos':      return 'Get the POS from CEDU · payment is locked until then';
        case 'await_event':    return 'Post-pay · payment opens ' . $b['payment']['opensLabel'] . ', due ' . $due;
        case 'await_gcash':    return 'Pay by ' . $due . ' · customer notified';
        case 'await_cash':     return 'Pay at cashier by ' . $due;
        case 'under_review':
            return $b['receiptVerdict'] === 'accepted'
                ? 'Match ref ' . $b['receiptReference'] . ' in GCash'
                : $b['receiptFlags'] . ($b['receiptFlags'] == 1 ? ' flag needs' : ' flags need') . ' a human look';
        case 'overdue':
            return $b['refundsAllowed']
                ? 'Pre-pay booking · deadline passed ' . $due . ' · slot releasable'
                : 'Post-pay window ended ' . $due . ' · still payable · follow up';
        case 'expired':           return 'Never paid · hold released';
        case 'refund_requested':  return 'All documents in · verify';
        case 'refund_await_or':   return 'Receipts in · OR still to come';
        case 'refund_correction': return 'Returned to the customer · waiting on them';
        case 'confirmed':
        case 'paid_cash':         return 'Paid · nothing outstanding';
        default:                  return $b['paymentStatusStaff'];
    }
}

/* Only rows that need SOMEONE get a colour; 'late' is reserved for a missed
   deadline so overdue work stands out from work that is merely waiting. */
function booking_queue_class(array $b) {
    if ($b['paymentCode'] === 'overdue') return 'late';
    return booking_queue_category($b) !== '' || $b['paymentCode'] === 'refund_correction' ? 'warn' : '';
}

/* Every booking that still needs looking at, as queue rows.
   The bulk history layer is excluded: it exists to give the reports and the
   dashboard real volume, not to be scrolled through. A queue that opens on 120
   finished bookings hides the 30 that need someone. */
function booking_queue_rows() {
    $rows = [];
    foreach (bookings_all() as $b) {
        if ($b['reservationCode'] === 'completed'
            && in_array($b['paymentCode'], ['confirmed', 'paid_cash'], true)) {
            continue;
        }
        $rows[] = [
            'id'       => $b['bookingId'],
            'name'     => $b['customerName'],
            'type'     => $b['isWalkIn'] ? 'Walk-in'
                            : ($b['isUsep'] ? 'USeP' . ($b['usepVerified'] ? ' · verified' : ' · unverified') : 'Non-USeP'),
            'room'     => $b['roomName'],
            'venue'    => $b['venueName'],
            'dates'    => booking_date_label($b),
            'res'      => $b['reservationCode'],
            'pay'      => booking_queue_pay_key($b),
            'act'      => booking_queue_action($b),
            'cls'      => booking_queue_class($b),
            'cat'      => booking_queue_category($b),
            'since'    => $b['refundFiledIso'],     // how long a refund has waited on staff
            'eventIso' => $b['eventDateIso'],
        ];
    }
    return $rows;
}

/* =====================================================================
   THE TRANSACTION LEDGER — one row per booking, for both portals.

   Pass a customer id for that person's own ledger, or nothing for the
   staff-wide view. Both transaction-history pages render the same shape;
   only the admin one shows the customer's name.

   ONE ROW PER BOOKING, not per payment: a booking that has not paid yet
   still belongs in the ledger (that is what "Payment due" rows are), and
   a booking never has two competing payments — a rejected receipt is
   re-submitted against the same one.
   ===================================================================== */
function transaction_rows($customerId = null) {
    $bookings = $customerId
        ? bookings_for_customer($customerId)
        : bookings_all();

    $rows = [];
    foreach ($bookings as $b) {
        $rows[] = [
            /* Derived from the BOOKING id, so a transaction keeps its number.
               These used to be the array index + 1, which meant a row's
               "transaction ID" changed whenever the list did — the same number
               could name a different transaction on the next page load. */
            'transactionId'   => 'TXN-' . date('Y', strtotime($b['bookingDateIso'])) . '-'
                                 . str_pad((string) $b['id'], 3, '0', STR_PAD_LEFT),
            'bookingId'       => $b['bookingId'],
            'customerName'    => $b['customerName'],
            'venue'           => $b['roomName'],
            'eventDate'       => $b['eventDate'],
            /* The day money moved, falling back to the day the booking was made
               for anything not yet paid. */
            'transactionDate' => date('F j, Y', strtotime($b['paidAtIso'] ?: $b['bookingDateIso'])),
            'amount'          => $b['amount'],
            'paymentMethod'   => $b['method'],
            'paymentStatus'   => $b['paymentStatus'],
            'bookingStatus'   => $b['bookingStatus'],
        ];
    }
    return $rows;
}

/* =====================================================================
   REPORTS — aggregates for admin/Quarterly_Reports.php.

   Those charts were four hard-coded arrays covering Q3 2025 to Q2 2026,
   which by now ended fifteen months in the past and named venues ("Social
   Hall") that are not in the catalog. They are computed here instead, so
   the reports describe whatever the business actually did.

   A booking counts toward the quarter its EVENT falls in, not the quarter
   it was booked or paid in: a venue's quarter is the business it hosted.
   REVENUE counts only money actually collected (confirmed / paid at the
   cashier), so an unpaid or overdue booking inflates nothing.
   ===================================================================== */

/* One derived table both report queries read: every booking with its event
   date, whether its money landed, and whether it fell through. */
function report_base_sql() {
    return "SELECT b.id, b.room_id, b.total_amount, b.discount_percent,
                   b.reservation_status, b.payment_status,
                   COALESCE(vd.start_date, hd.check_in_date) AS event_date,
                   IF(b.payment_status IN ('confirmed','paid_cash'), b.total_amount, 0) AS collected,
                   IF(b.reservation_status IN ('cancelled','rejected','released'), 1, 0) AS fell_through
              FROM bookings b
              LEFT JOIN venue_booking_details vd  ON vd.booking_id = b.id
              LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id";
}

/* The last $count quarters up to and including the current one, oldest first.
   Quarters with no business still appear, as zeroes — a missing quarter would
   silently compress the x-axis and make a quiet period look like growth. */
function report_quarters($count = 4) {
    $pdo = venusep_db();
    $out = [];

    /* Build the window first, so empty quarters are present by construction. */
    $cursor = strtotime(date('Y-m-01', strtotime('-' . (3 * ($count - 1)) . ' months')));
    for ($i = 0; $i < $count; $i++) {
        $y = (int) date('Y', $cursor);
        $q = (int) ceil((int) date('n', $cursor) / 3);
        $out["$y-$q"] = [
            'label' => 'Q' . $q . ' ' . $y,
            'revenue' => 0.0, 'bookings' => 0, 'cancelled' => 0, 'avg' => 0.0,
        ];
        $cursor = strtotime('+3 months', $cursor);
    }
    if ($pdo === null) {
        return array_values($out);
    }

    try {
        $rows = $pdo->query(
            "SELECT YEAR(x.event_date) AS y, QUARTER(x.event_date) AS q,
                    SUM(x.collected) AS revenue,
                    SUM(x.fell_through = 0) AS bookings,
                    SUM(x.fell_through)     AS cancelled,
                    SUM(x.collected > 0)    AS paid_bookings
               FROM (" . report_base_sql() . ") x
              WHERE x.event_date IS NOT NULL
              GROUP BY y, q"
        )->fetchAll();
        foreach ($rows as $r) {
            $key = $r['y'] . '-' . $r['q'];
            if (!isset($out[$key])) {
                continue;                       // outside the window we are charting
            }
            $out[$key]['revenue']   = (float) $r['revenue'];
            $out[$key]['bookings']  = (int) $r['bookings'];
            $out[$key]['cancelled'] = (int) $r['cancelled'];
            /* Average per PAID booking, not per booking: dividing collected
               money by bookings that never paid understates every quarter. */
            $out[$key]['avg'] = $r['paid_bookings'] > 0
                ? round((float) $r['revenue'] / (int) $r['paid_bookings'])
                : 0;
        }
    } catch (PDOException $e) { /* the zeroed window still renders */ }

    return array_values($out);
}

/* Per-venue totals for the breakdown charts and the table.
   Pass a year+quarter to narrow it; omit both for all time.

   EVERY ACTIVE VENUE APPEARS, even with nothing in it. A venue that simply
   vanishes from the breakdown in a quiet quarter reads as "this venue was
   removed", and the donut silently re-proportions around the gap — a quiet
   quarter and a deleted venue must not look the same. */
function report_by_venue($year = null, $quarter = null) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return [];
    }
    $where = '';
    $args  = [];
    if ($year !== null && $quarter !== null) {
        $where = ' AND YEAR(x.event_date) = :y AND QUARTER(x.event_date) = :q';
        $args  = [':y' => (int) $year, ':q' => (int) $quarter];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT v.name AS venue,
                    COALESCE(SUM(x.id IS NOT NULL), 0)      AS events,
                    COALESCE(SUM(x.discount_percent > 0), 0) AS discounted,
                    COALESCE(SUM(x.fell_through), 0)         AS cancelled,
                    COALESCE(SUM(x.collected), 0)            AS revenue
               FROM venues v
               LEFT JOIN rooms r ON r.venue_id = v.id
               LEFT JOIN (" . report_base_sql() . ") x
                      ON x.room_id = r.id" . $where . "
              WHERE v.is_active = 1
              GROUP BY v.id, v.name
              ORDER BY v.id"
        );
        $stmt->execute($args);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* =====================================================================
   CALENDAR EVENTS — FullCalendar rows for both portals.

   Pass a customer id for "my calendar", or nothing for the staff-wide
   view (which also labels each event with its room).

   Colour carries the RESERVATION status, because a calendar answers "is
   this date taken and is it settled" — a cancelled booking must not read
   like a live one. Payment state is deliberately not encoded: a date is
   held or it is not, and mixing two axes into one colour is what the
   two-badge design elsewhere exists to avoid.
   ===================================================================== */
function calendar_events($customerId = null) {
    /* Background, text. Anything that fell through shares the red-ish pair so a
       dead date is visually distinct from a live one at a glance. */
    $colors = [
        'pending'   => ['#fdf3e6', '#8a5a12'],
        'approved'  => ['#eaf6ef', '#1c7a4f'],
        'completed' => ['#d7d7d7', '#1f1e1e'],
        'released'  => ['#efefef', '#6b675f'],
        'rejected'  => ['#fbd5db', '#b23a3a'],
        'cancelled' => ['#fbd5db', '#b23a3a'],
        'disrupted' => ['#fbd5db', '#b23a3a'],
    ];

    $bookings = $customerId ? bookings_for_customer($customerId) : bookings_all();
    $events = [];
    foreach ($bookings as $b) {
        if (!$b['eventDateIso']) {
            continue;
        }
        $c = isset($colors[$b['reservationCode']]) ? $colors[$b['reservationCode']] : $colors['completed'];

        /* FullCalendar's `end` is EXCLUSIVE, so a booking's last day needs one
           day added or a three-day event renders as two. A hostel stay already
           ends on its check-out date, which is the morning everyone leaves —
           that IS the exclusive end, so it is passed through unchanged. */
        $end = null;
        if ($b['type'] === 'hostel') {
            $end = $b['endDateIso'] ?: null;
        } elseif ($b['endDateIso'] && $b['endDateIso'] !== $b['eventDateIso']) {
            $end = date('Y-m-d', strtotime($b['endDateIso'] . ' +1 day'));
        }

        $event = [
            'title'           => $customerId
                                    ? $b['eventName']
                                    : $b['eventName'] . ' · ' . $b['roomName'],
            'start'           => $b['eventDateIso'],
            'backgroundColor' => $c[0],
            'borderColor'     => $c[0],
            'textColor'       => $c[1],
        ];
        if ($end !== null) {
            $event['end'] = $end;
        }
        $events[] = $event;
    }
    return $events;
}

/* "Oct 18 – Oct 20, 2026", or a single date, or a stay with its bed count. */
function booking_date_label(array $b) {
    if (!$b['eventDateIso']) {
        return '—';
    }
    if ($b['type'] === 'hostel') {
        return date('M j', strtotime($b['eventDateIso'])) . ' – ' . date('M j, Y', strtotime($b['endDateIso']))
             . ' · ' . $b['beds'] . ' bed' . ($b['beds'] == 1 ? '' : 's');
    }
    return $b['eventDateIso'] === $b['endDateIso']
        ? date('M j, Y', strtotime($b['eventDateIso']))
        : date('M j', strtotime($b['eventDateIso'])) . ' – ' . date('M j, Y', strtotime($b['endDateIso']));
}

/* The per-day schedule of a venue booking. Times live per DAY (DB-DECISIONS #8)
   because a three-day event is rarely the same hours each day, so there is no
   single start/end on the booking header to read instead. */
function booking_slots($bookingId) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT slot_date, start_time, end_time, released_at
               FROM venue_booking_slots WHERE booking_id = :b ORDER BY slot_date, start_time'
        );
        $stmt->execute([':b' => (int) $bookingId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* The usable documents on a booking, newest of each kind. Returns [kind => id],
   which is all a page needs: the bytes are fetched from document-view.php,
   which repeats the path and permission checks. A dangling seed/import row is
   not a document and must not be advertised as a link that can only return 404. */
function booking_document_ids($bookingId) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT d.document_type, d.id, d.file_path
               FROM booking_documents d
               JOIN (SELECT document_type, MAX(id) AS id
                       FROM booking_documents
                      WHERE booking_id = :b
                      GROUP BY document_type) latest ON latest.id = d.id'
        );
        $stmt->execute([':b' => (int) $bookingId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $path = doc_path($r['file_path']);
            if ($path !== null && is_file($path)) {
                $out[$r['document_type']] = (int) $r['id'];
            }
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/* The latest GCash receipt on a booking, with its flags decoded. */
function booking_receipt($bookingId) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, reference_number, receiver_name, receiver_number, amount_centavos,
                    receipt_datetime, ocr_confidence, verdict, flags_json
               FROM gcash_receipts WHERE booking_id = :b ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':b' => (int) $bookingId]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $flags = json_decode((string) $r['flags_json'], true);
        $r['flags'] = is_array($flags) ? $flags : [];
        return $r;
    } catch (PDOException $e) {
        return null;
    }
}

/* What happened to this booking, newest last. Reads booking_timeline (written by
   trg_bookings_after_update on every status change) and falls back to the
   booking's own timestamps, so a booking that has never been updated since it
   was created still shows an honest history instead of an empty panel. */
function booking_history_events(array $b) {
    $pdo = venusep_db();
    $events = [];
    $events[] = [
        'w' => date('M j · g:i A', strtotime($b['bookingDateIso'])) . ' — Customer',
        'x' => 'Booking submitted',
        'm' => $b['type'] === 'hostel'
                 ? $b['beds'] . ' bed' . ($b['beds'] == 1 ? '' : 's') . ' for ' . $b['nights'] . ' night' . ($b['nights'] == 1 ? '' : 's') . ', all guests named.'
                 : $b['eventName'] . ' · ' . $b['days'] . ' day' . ($b['days'] == 1 ? '' : 's')
                   . ($b['refundsAllowed'] ? '.' : '. Customer confirmed the booking is non-refundable.'),
    ];
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare(
                "SELECT t.occurred_at, t.action_code, t.new_reservation_status, t.new_payment_status, t.note,
                        COALESCE(s.full_name, u.email, 'System') AS actor
                   FROM booking_timeline t
                   LEFT JOIN users u ON u.id = t.performed_by_user_id
                   LEFT JOIN staff s ON s.user_id = t.performed_by_user_id
                  WHERE t.booking_id = :b ORDER BY t.occurred_at, t.id"
            );
            $stmt->execute([':b' => (int) $b['id']]);
            foreach ($stmt->fetchAll() as $t) {
                $events[] = [
                    'w' => date('M j · g:i A', strtotime($t['occurred_at'])) . ' — ' . $t['actor'],
                    'x' => ucfirst(str_replace('_', ' ', $t['action_code'])),
                    'm' => (string) $t['note'],
                ];
            }
        } catch (PDOException $e) { /* the derived events above still stand */ }
    }
    return $events;
}

/* REFUND ELIGIBILITY (agreed 2026-09-09, policy snapshot added 2026-09-16) —
   the booking was made while refunds were allowed, it is PAID, and the event
   has not yet happened. A past event is a service already delivered; refunding
   it is a staff-side exception, not a self-service request. Both the history
   page (whether to offer the button) and the refund page (whether to accept the
   booking at all) ask THIS function, so they can never disagree.
   Deliberately NOT checked here: the live switch. Turning it OFF must not take
   a refund away from a booking that was sold as refundable. */
if (!function_exists('cb_is_refundable')) {
    function cb_is_refundable(array $booking) {
        return !empty($booking['refundsAllowed'])
            && in_array($booking['reservationCode'], ['approved'], true)
            && in_array($booking['paymentCode'], ['confirmed', 'paid_cash'], true)
            && $booking['eventDateIso'] > date('Y-m-d');
    }
}

/* Shared output helpers (guarded so a double include cannot redeclare). */
if (!function_exists('bh_e')) {
    function bh_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('bh_badge')) {
    function bh_badge($status) { return 'badge-' . strtolower(preg_replace('/[^a-zA-Z]+/', '-', $status)); }
}

/* Normalise a PH mobile to 11 digits starting 09, or null if it cannot be one.
   Used on BOTH sides so the customer's entry and the staff's entry are compared
   as numbers, never as strings — "0917 555 0123" and "09175550123" are the same
   number and must never be reported as a mismatch. */
if (!function_exists('cb_normalise_mobile')) {
    function cb_normalise_mobile($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) === 12 && substr($d, 0, 2) === '63') $d = '0' . substr($d, 2);
        if (strlen($d) === 10 && substr($d, 0, 1) === '9')  $d = '0' . $d;
        return (strlen($d) === 11 && substr($d, 0, 2) === '09') ? $d : null;
    }
}

/* EVERY status code the database defines, with the badge label and colour a
   staff page should show for it. The booking detail page kept its own JS map of
   three reservation codes and a handful of payment ones, so opening a
   `completed`, `cancelled`, `rejected` or `disrupted` booking — 124 of the 157
   in the system — threw on an undefined lookup and rendered a blank page.
   Labels come from the lookup tables, so a status added later gets a badge
   without anyone remembering to edit a page. */
if (!function_exists('status_badge_maps')) {
    function status_badge_maps() {
        static $cached = null;
        if ($cached !== null) { return $cached; }

        /* Colour carries URGENCY, not category: red = someone lost something or
           is late, amber = waiting on a person, green = settled, navy = in
           motion but fine, gray = nothing to do yet. */
        $colours = [
            'res' => [
                'pending'   => 'b-amber', 'approved'  => 'b-green',
                'completed' => 'b-navy',  'released'  => 'b-gray',
                'cancelled' => 'b-red',   'rejected'  => 'b-red',
                'disrupted' => 'b-amber',
            ],
            'pay' => [
                'locked'            => 'b-gray',  'await_event'       => 'b-gray',
                'await_pos'         => 'b-amber', 'await_gcash'       => 'b-amber',
                'await_cash'        => 'b-amber', 'under_review'      => 'b-amber',
                'confirmed'         => 'b-green', 'paid_cash'         => 'b-green',
                'overdue'           => 'b-red',   'expired'           => 'b-red',
                'refund_requested'  => 'b-navy',  'refund_await_or'   => 'b-amber',
                'refund_processing' => 'b-navy',  'refund_correction' => 'b-amber',
                'refunded'          => 'b-green', 'refund_denied'     => 'b-red',
            ],
        ];

        $maps = ['res' => [], 'pay' => []];
        $pdo  = venusep_db();
        if ($pdo === null) { return $maps; }
        try {
            foreach (['res' => 'reservation_statuses', 'pay' => 'payment_statuses'] as $key => $table) {
                $rows = $pdo->query("SELECT code, staff_label FROM {$table}")->fetchAll();
                foreach ($rows as $r) {
                    $maps[$key][$r['code']] = [
                        't' => $r['staff_label'],
                        /* An unknown code still gets a badge — a page that cannot
                           name a status must still open. */
                        'c' => isset($colours[$key][$r['code']]) ? $colours[$key][$r['code']] : 'b-gray',
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log('status_badge_maps: ' . $e->getMessage());
        }
        $cached = $maps;
        return $maps;
    }
}
