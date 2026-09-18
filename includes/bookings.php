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

    /* A hostel stay's last BOOKED day is the night before check-out: nights are
       exclusive of the check-out date, and the payment deadline must count from
       the last night actually stayed, not from the morning everyone leaves. */
    $lastBooked = $isHostel && $lastIso
        ? date('Y-m-d', strtotime($lastIso . ' -1 day'))
        : $lastIso;

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
        'receiptVerdict'  => $row['receipt_verdict'],       // accepted | manual_review | rejected | null
        'receiptReference'=> (string) $row['receipt_reference'],
        'receiptFlags'    => (int) $row['receipt_flags'],

        'pos'           => (string) $row['pos_number'],
        'officialReceipt' => (string) $row['official_receipt_no'],
        'checkedIn'     => (bool) $row['checked_in'],

        'customerNotes' => (string) $row['customer_notes'],
        'staffNotes'    => (string) $row['staff_notes'],
    ];

    /* WHEN this booking pays — the same shared rule the booking pages use,
       applied to the policy this booking was MADE under. */
    $b['payment']    = payment_policy_for($b['refundsAllowed'], $firstIso ?: date('Y-m-d'), $lastBooked);
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
                : $b['receiptFlags'] . ' flag' . ($b['receiptFlags'] == 1 ? '' : 's') . ' need a human look';
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

/* The latest GCash receipt on a booking, with its flags decoded. */
function booking_receipt($bookingId) {
    $pdo = venusep_db();
    if ($pdo === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT reference_number, receiver_name, receiver_number, amount_centavos,
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
