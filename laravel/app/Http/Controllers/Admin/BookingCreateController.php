<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Http\Request;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/* =====================================================================
   COUNTER BOOKING (was admin/booking-create.php) — the ONLY way staff
   create a booking for a walk-in.

   POST (from the booking page in counter mode)
     _token, type=venue|hostel, room=<room_code>, method=gcash|cash,
     hostel: check_in, check_out, occupants=<json [{name,gender}]>, cash_received=1
     venue:  event_name, date_start, date_end, attendees, times=<json>,
     agree_no_refund=0|1,
     booker_mode=account|walkin,
       account: customer_id
       walkin : walkin_name, walkin_phone, walkin_email (or walkin_no_email=1)[, walkin_address]
     id_checked=1, usep_id=0|1
   Replies with JSON: { ok, reference, bookingId, payBy }

   NO ID FILE (staff looked at the ID; the judgement goes in the timeline).
   THE DISCOUNT IS GRANTED HERE (staff saw the ID). IT IS APPROVED
   IMMEDIATELY, through sp_approve_booking. A WALK-IN WITH NO ACCOUNT CAN
   ONLY PAY AT THE COUNTER (method forced to 'cash').

   WHAT IS NOT TRUSTED FROM THE PAGE: the price (recomputed from the room's
   rate), the slot (sp_add_venue_slot), the customer (re-read), and the
   identity proof — only a password checked in THIS staff session by
   admin/customer-verify writes session('counter_proof')[customer id].
   ===================================================================== */
class BookingCreateController extends Controller
{
    use RepliesJson;

    private const COUNTER_PROOF_TTL = 900;      // must match admin/customer-verify

    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'bookings', 'refund-policy', 'receipt-emails');
        /* pricing.php ends with a <script> block for the booking pages; this
           endpoint answers in JSON, so its output is swallowed. */
        ob_start();
        legacy('pricing');
        ob_end_clean();

        if (!$request->isMethod('post')) {
            $this->reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        $actorType = session('account_type');
        if (!session()->has('user_id') || !in_array($actorType, ['admin', 'staff'], true)) {
            $this->reply(403, ['ok' => false, 'message' => 'Only staff can take a counter booking.']);
        }
        $actor = (int) session('user_id');

        /* The ID check is the whole basis for dropping the upload. */
        if (empty($request->post('id_checked'))) {
            $this->reply(400, ['ok' => false, 'message' => 'Confirm you have seen a valid ID before creating the booking.']);
        }

        $type     = (string) ($request->post('type') ?? '');
        $roomCode = (string) ($request->post('room') ?? '');
        $usepId   = !empty($request->post('usep_id')) ? 1 : 0;
        $method   = $request->post('method') === 'cash' ? 'cash' : 'gcash';

        if (!in_array($type, ['venue', 'hostel'], true)) {
            $this->reply(400, ['ok' => false, 'message' => 'Invalid request.']);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode)) {
            $this->reply(400, ['ok' => false, 'message' => 'Invalid room.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was created.']);
        }

        /* ---- who the booking is for ---- */
        $c = [
            'actor' => $actor, 'roomCode' => $roomCode, 'usepId' => $usepId, 'method' => $method,
            'bookerMode' => $request->post('booker_mode') === 'walkin' ? 'walkin' : 'account',
            'customerId' => 0, 'bookerName' => '', 'isNewWalkIn' => false,
            'contactEmail' => '',      /* walk-ins only, required unless the guest has none */
            'walkinNoEmail' => false,  /* staff ticked "Guest has no email" */
            'phone' => null, 'address' => '',
        ];
        if ($c['bookerMode'] === 'account') {
            $c['customerId'] = (int) ($request->post('customer_id') ?? 0);
            if ($c['customerId'] <= 0) {
                $this->reply(400, ['ok' => false, 'message' => 'Pick the customer&rsquo;s account.']);
            }
            /* The identity proof, re-checked server-side. */
            $proofs = session('counter_proof');
            $proof = is_array($proofs) && isset($proofs[$c['customerId']]) ? (int) $proofs[$c['customerId']] : 0;
            if ($proof <= 0 || (time() - $proof) > self::COUNTER_PROOF_TTL) {
                $this->reply(403, ['ok' => false, 'message' => 'Ask the customer to confirm their password again before booking.']);
            }
            try {
                $stmt = $pdo->prepare(
                    'SELECT c.id, c.full_name FROM customers c JOIN users u ON u.id = c.user_id
                      WHERE c.id = :c AND u.is_active = 1 LIMIT 1'
                );
                $stmt->execute([':c' => $c['customerId']]);
                $row = $stmt->fetch();
            } catch (PDOException $e) {
                error_log('VENUSeP booking-create (customer): ' . $e->getMessage());
                $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
            }
            if (!$row) {
                $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
            }
            $c['bookerName'] = (string) $row['full_name'];
        } else {
            $c['bookerName'] = trim((string) ($request->post('walkin_name') ?? ''));
            $c['phone']      = cb_normalise_mobile($request->post('walkin_phone') ?? '');
            $c['address']    = trim((string) ($request->post('walkin_address') ?? ''));
            if ($c['bookerName'] === '' || mb_strlen($c['bookerName']) > 190) {
                $this->reply(400, ['ok' => false, 'message' => 'Enter the guest&rsquo;s full name.']);
            }
            if ($c['phone'] === null) {
                $this->reply(400, ['ok' => false, 'message' => 'Enter an 11-digit mobile number starting 09.']);
            }
            /* REQUIRED (2026-10-04) unless staff tick "Guest has no email" (DB-DECISIONS #22). */
            $c['walkinNoEmail'] = ($request->post('walkin_no_email') ?? '') === '1';
            $c['contactEmail'] = $c['walkinNoEmail'] ? '' : strtolower(trim((string) ($request->post('walkin_email') ?? '')));
            if (!$c['walkinNoEmail'] && $c['contactEmail'] === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Enter the guest&rsquo;s email address, or tick &ldquo;Guest has no email&rdquo; and print the receipt.']);
            }
            if (!$c['walkinNoEmail'] && (mb_strlen($c['contactEmail']) > 190 || !filter_var($c['contactEmail'], FILTER_VALIDATE_EMAIL))) {
                $this->reply(400, ['ok' => false, 'message' => 'That email address doesn&rsquo;t look right. Check it with the guest.']);
            }
            /* No account: the money has to change hands at the counter. */
            $c['method'] = 'cash';
            $c['isNewWalkIn'] = true;
        }

        /* ---- the room, and the rate THIS server believes in ---- */
        try {
            $roomStmt = $pdo->prepare(
                "SELECT r.id, r.venue_id, r.room_type, r.is_active, erd.attendee_capacity, erd.fee_per_day,
                        hrd.rate_per_head_per_night
                   FROM rooms r
                   LEFT JOIN event_room_details erd  ON erd.room_id = r.id
                   LEFT JOIN hostel_room_details hrd ON hrd.room_id = r.id
                  WHERE r.room_code = :c"
            );
            $roomStmt->execute([':c' => $roomCode]);
            $room = $roomStmt->fetch();
        } catch (PDOException $e) {
            error_log('VENUSeP booking-create (room): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
        }
        if (!$room || !$room['is_active'] || $room['room_type'] !== ($type === 'hostel' ? 'hostel' : 'event')) {
            $this->reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
        }
        /* Staff take counter bookings for THEIR venue only (2026-10-27); admins for any. */
        if (!staff_may_handle_venue($room['venue_id'])) {
            $this->reply(403, ['ok' => false, 'message' => 'This room belongs to another venue. Only that venue\'s staff or an administrator can book it at the counter.']);
        }
        $c['room'] = $room;

        $c['refundsAllowed'] = refunds_enabled() ? 1 : 0;
        if (!$c['refundsAllowed'] && empty($request->post('agree_no_refund'))) {
            $this->reply(400, ['ok' => false, 'message' => 'The customer must confirm they understand the booking is non-refundable.']);
        }

        legacy('demo-mode');
        $type === 'hostel' ? $this->hostel($request, $pdo, $c) : $this->venue($request, $pdo, $c);
    }

    /* The identity proof is spent: it confirmed one person for one booking. */
    private function spendProof(array $c): void
    {
        if ($c['bookerMode'] === 'account') {
            $proofs = session('counter_proof');
            if (is_array($proofs)) {
                unset($proofs[$c['customerId']]);
                session(['counter_proof' => $proofs]);
            }
        }
    }

    /* A walk-in with no account is a customers row with user_id NULL (DB-DECISIONS #4). */
    private function insertWalkIn(PDO $pdo, array &$c): void
    {
        $pdo->prepare('INSERT INTO customers (user_id, full_name, phone, contact_email, address) VALUES (NULL, :n, :p, :e, :a)')
            ->execute([':n' => $c['bookerName'], ':p' => $c['phone'], ':e' => $c['contactEmail'] !== '' ? $c['contactEmail'] : null,
                       ':a' => $c['address'] !== '' ? $c['address'] : null]);
        $c['customerId'] = (int) $pdo->lastInsertId();
    }

    /* THE EVIDENCE — each row is a judgement a person made, with their name on it. */
    private function timeline(PDO $pdo, int $bookingId, array $c, float $pct, string $createdNote): void
    {
        $tl = $pdo->prepare('INSERT INTO booking_timeline (booking_id, action_code, performed_by_user_id, note) VALUES (:b, :a, :u, :n)');
        $tl->execute([':b' => $bookingId, ':a' => 'counter_created', ':u' => $c['actor'], ':n' => $createdNote]);
        $tl->execute([':b' => $bookingId, ':a' => 'counter_id_checked', ':u' => $c['actor'],
            ':n' => $c['usepId']
                ? 'Valid USeP ID inspected in person; ' . (int) $pct . '% affiliate discount applied.'
                : 'Valid ID inspected in person; no affiliate discount.']);
        if ($c['bookerMode'] === 'account') {
            $tl->execute([':b' => $bookingId, ':a' => 'counter_identity_confirmed', ':u' => $c['actor'],
                ':n' => 'Customer confirmed the account by entering their password at the counter.']);
        }
        if ($c['walkinNoEmail']) {
            $tl->execute([':b' => $bookingId, ':a' => 'counter_no_email', ':u' => $c['actor'],
                ':n' => 'Guest has no email: nothing is emailed; the System Receipt is printed at the counter.']);
        }
    }

    /* =====================================================================
       HOSTEL AT THE COUNTER (2026-10-27). PAY TO USE (DB-DECISIONS #26): the
       stay is NOT recorded unless it is paid — written approved and
       paid_cash, with its payment row and System Receipt. sp_assign_bed opens
       its own transaction, so the stay is committed first, then the beds,
       then the approval and payment; a failing step removes the stay.
       ===================================================================== */
    private function hostel(Request $request, PDO $pdo, array $c): never
    {
        if (($request->post('cash_received') ?? '') !== '1') {
            $this->reply(400, ['ok' => false, 'message' => 'Take the payment first: a stay is only booked once the cash is received.']);
        }
        $method = 'cash';
        $room = $c['room'];
        $checkIn  = (string) ($request->post('check_in') ?? '');
        $checkOut = (string) ($request->post('check_out') ?? '');
        $occupants = json_decode((string) ($request->post('occupants') ?? '[]'), true);
        if (!BookingRules::isDate($checkIn) || !BookingRules::isDate($checkOut) || $checkOut <= $checkIn) {
            $this->reply(400, ['ok' => false, 'message' => 'Check-out must be after check-in.']);
        }
        if (BookingRules::daysBetween($checkIn, $checkOut) > BookingRules::MAX_DAYS) {
            $this->reply(400, ['ok' => false, 'message' => 'A stay can be at most ' . BookingRules::MAX_DAYS . ' nights.']);
        }
        if ($checkIn < date('Y-m-d')) {
            $this->reply(400, ['ok' => false, 'message' => 'Check-in cannot be in the past.']);
        }
        /* the same advance limit as online (system_settings.hostel_advance_booking_max_days) */
        if ($tooFar = BookingRules::hostelTooFarAhead($pdo, $checkIn)) {
            $this->reply(400, ['ok' => false, 'message' => $tooFar]);
        }
        if (!is_array($occupants) || !$occupants || count($occupants) > 20) {
            $this->reply(400, ['ok' => false, 'message' => 'Name at least one guest.']);
        }
        $guests = [];
        foreach ($occupants as $o) {
            $n = trim((string) ($o['name'] ?? ''));
            $g = (string) ($o['gender'] ?? '');
            if ($n === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Every bed needs a named guest.']);
            }
            $guests[] = ['name' => mb_substr($n, 0, 190), 'gender' => in_array($g, ['male', 'female', 'other'], true) ? $g : 'other'];
        }
        $nights = BookingRules::daysBetween($checkIn, $checkOut);
        /* per head, per night; the discount applies now — staff have seen the ID */
        $roomPrice = (float) $room['rate_per_head_per_night'] * count($guests) * $nights;
        $pct       = $c['usepId'] ? (float) venusep_discount_percent() : 0.0;
        $discount  = round($roomPrice * $pct) / 100;
        $total     = $roomPrice - $discount;
        $stayLabel = count($guests) . ' bed' . (count($guests) === 1 ? '' : 's') . ' · ' . $nights . ' night' . ($nights === 1 ? '' : 's');

        if (demo_mode_on()) {
            $policy = payment_policy_for('pay_to_use', $checkIn, $checkOut);
            $row = demo_add_booking([
                'type' => 'hostel', 'customerId' => $c['customerId'], 'customerName' => $c['bookerName'],
                'customerEmail' => '', 'customerPhone' => (string) ($request->post('walkin_phone') ?? ''),
                'isWalkIn' => true, 'isUsep' => (bool) $c['usepId'], 'usepVerified' => (bool) $c['usepId'],
                'roomCode' => $c['roomCode'], 'roomName' => $c['roomCode'], 'venueName' => '',
                'eventName' => $stayLabel, 'eventDateIso' => $checkIn, 'endDateIso' => $checkOut,
                'roomPrice' => $roomPrice, 'discountPercent' => $pct, 'amount' => $total,
                'method' => 'Cash',
                'reservationCode' => 'approved', 'paymentCode' => 'paid_cash',
                'refundsAllowed' => (bool) $c['refundsAllowed'], 'payToUse' => true, 'refundable' => false,
                'payment' => $policy, 'beds' => count($guests), 'nights' => $nights, 'days' => 0,
            ]);
            $this->reply(200, ['ok' => true, 'demo' => true, 'bookingId' => $row['id'],
                'reference' => $row['bookingId'], 'payBy' => $policy['payByLabel'], 'paid' => true,
                'message' => 'Stay booked and paid (demo mode — nothing was saved).']);
        }

        /* ---- 1. the stay itself ---- */
        $bookingId = 0;
        $removeStay = function () use ($pdo, &$bookingId, &$c) {
            if ($bookingId) {
                $pdo->prepare('DELETE FROM bookings WHERE id = :b')->execute([':b' => $bookingId]);
            }
            if ($c['isNewWalkIn'] && $c['customerId']) {
                $pdo->prepare('DELETE FROM customers WHERE id = :c AND user_id IS NULL')->execute([':c' => $c['customerId']]);
            }
        };
        try {
            /* The deadline housekeeping runs BEFORE the transaction (migration 15):
               its UPDATEs lock every bookings row they scan, so inside the
               transaction two submits a moment apart deadlocked on each other. */
            $pdo->exec('CALL sp_expire_due_bookings()');
            $pdo->beginTransaction();
            if ($c['isNewWalkIn']) {
                $this->insertWalkIn($pdo, $c);
            }
            $pdo->prepare(
                "INSERT INTO bookings
                    (customer_id, room_id, booking_type, reservation_status, payment_status,
                     payment_method, is_usep_affiliated, affiliation_verified,
                     room_price, discount_percent, discount_amount, total_amount,
                     refunds_allowed, updated_by_user_id)
                 VALUES (:c, :r, 'hostel', 'pending', 'locked', :m, :a, :av, :rp, :dp, :da, :t, :ra, :u)"
            )->execute([
                ':c' => $c['customerId'], ':r' => $room['id'], ':m' => $method, ':a' => $c['usepId'], ':av' => $c['usepId'],
                ':rp' => $roomPrice, ':dp' => $pct, ':da' => $discount, ':t' => $total,
                ':ra' => $c['refundsAllowed'], ':u' => $c['actor'],
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in) VALUES (:b, :i, :o, 0)')
                ->execute([':b' => $bookingId, ':i' => $checkIn, ':o' => $checkOut]);
            $occIns = $pdo->prepare('INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest) VALUES (:b, :n, :g, :p)');
            $occIds = [];
            foreach ($guests as $i => $o) {
                $occIns->execute([':b' => $bookingId, ':n' => $o['name'], ':g' => $o['gender'], ':p' => $i === 0 ? 1 : 0]);
                $occIds[] = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')
                ->execute([':b' => $bookingId, ':b2' => $bookingId]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP booking-create (hostel): ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
        }

        /* ---- 2. one exact bed per guest, free on EVERY night ---- */
        try {
            BookingRules::assignBeds($pdo, (int) $room['id'], $bookingId, $occIds, $checkIn, $checkOut);
        } catch (Throwable $e) {
            $removeStay();
            $this->reply(409, ['ok' => false,
                'message' => ($e instanceof PDOException && stripos($e->getMessage(), 'maintenance') !== false)
                    ? 'The room is closed for maintenance during that stay. Pick other dates.'
                    : 'There are not enough free beds for every night of that stay. Pick other dates or fewer beds.']);
        }

        /* ---- 3. the evidence, the approval, the cash, the receipt email ---- */
        $queued = null;
        try {
            $pdo->beginTransaction();
            $this->timeline($pdo, $bookingId, $c, $pct, 'Walk-in stay booked at the front desk for ' . $c['bookerName'] . '.');
            $ap = $pdo->prepare('CALL sp_approve_booking(:b, :u)');
            $ap->execute([':b' => $bookingId, ':u' => $c['actor']]);
            $ap->closeCursor();
            /* the cash staff took before pressing the button: paid, with its
               payment row and System Receipt, in this same transaction */
            $receiptId = payment_confirm($pdo, $bookingId, $c['actor'], 'Cash received at the counter.', true);
            if ($receiptId === null) {
                throw new RuntimeException('no receipt was issued');
            }
            /* One email, not two: the System Receipt already names the stay. */
            if ($c['contactEmail'] !== '') {
                try {
                    $queued = queue_walkin_receipt($pdo, $bookingId, $receiptId, $c['actor']);
                } catch (Throwable $mailErr) {
                    if ($mailErr instanceof PDOException) {
                        throw $mailErr;
                    }
                    error_log('VENUSeP walk-in receipt email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP booking-create (hostel approve + payment): ' . $e->getMessage());
            $removeStay();
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created and nothing was charged.']);
        }

        $this->spendProof($c);
        if ($queued !== null) {
            mail_send($pdo, $queued);
        }
        $after = $pdo->prepare('SELECT submitted_at, current_deadline_at FROM bookings WHERE id = :b');
        $after->execute([':b' => $bookingId]);
        $row = $after->fetch();
        $this->reply(200, [
            'ok'        => true,
            'bookingId' => $bookingId,
            'reference' => booking_reference($bookingId, $row['submitted_at']),
            'payBy'     => $row['current_deadline_at'] ? date('M j, Y', strtotime($row['current_deadline_at'])) : '—',
            'paid'      => true,
            'message'   => 'Stay booked and paid.',
        ]);
    }

    /* ---- VENUE AT THE COUNTER: approved on the spot, paid later as usual. ---- */
    private function venue(Request $request, PDO $pdo, array $c): never
    {
        $room = $c['room'];
        $eventName = trim((string) ($request->post('event_name') ?? ''));
        $startIso  = (string) ($request->post('date_start') ?? '');
        $endIso    = (string) ($request->post('date_end') ?? '') ?: $startIso;
        $attendees = (int) ($request->post('attendees') ?? 0);
        $times     = json_decode((string) ($request->post('times') ?? '[]'), true);
        if (!is_array($times)) { $times = []; }

        if ($eventName === '' || mb_strlen($eventName) > 190) {
            $this->reply(400, ['ok' => false, 'message' => 'Enter an event name.']);
        }
        if (!BookingRules::isDate($startIso) || !BookingRules::isDate($endIso) || $endIso < $startIso) {
            $this->reply(400, ['ok' => false, 'message' => 'Pick valid reservation dates.']);
        }
        if (BookingRules::daysBetween($startIso, $endIso) + 1 > BookingRules::MAX_DAYS) {
            $this->reply(400, ['ok' => false, 'message' => 'A booking can cover at most ' . BookingRules::MAX_DAYS . ' days.']);
        }
        if ($attendees <= 0 || $attendees > (int) $room['attendee_capacity']) {
            $this->reply(400, ['ok' => false, 'message' => 'Enter an attendee count within the room capacity.']);
        }

        $blockStmt = $pdo->prepare('SELECT fn_room_hard_blocked(:r, :d) AS blocked');
        $slots = [];
        for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $blockStmt->execute([':r' => $room['id'], ':d' => $d]);
            if ((int) $blockStmt->fetchColumn() === 1) {
                continue;                       // a venue booking books AROUND a closed day
            }
            $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
            $s = isset($t['start']) ? (string) $t['start'] : '';
            $e = isset($t['end'])   ? (string) $t['end']   : '';
            if (!BookingRules::validHours($s, $e)) {
                $this->reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
            }
            $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
        }
        if (!$slots) {
            $this->reply(409, ['ok' => false, 'message' => 'Every day picked is closed for maintenance.']);
        }

        /* THE 12-HOUR RULE APPLIES TO WALK-INS TOO (time for the Official Receipt). */
        $LEAD_HOURS = 12;
        if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
            $this->reply(409, ['ok' => false,
                'message' => 'Venue bookings must start at least ' . $LEAD_HOURS . ' hours from now — there has to be time for the Official Receipt.']);
        }

        /* Priced per day for the days actually held; the discount applies HERE. */
        $roomPrice = (float) $room['fee_per_day'] * count($slots);
        $pct       = $c['usepId'] ? (float) venusep_discount_percent() : 0.0;
        $discount  = round($roomPrice * $pct) / 100;
        $total     = $roomPrice - $discount;
        $method    = $c['method'];

        /* DEMO MODE — validated exactly as above, then recorded in the session. */
        if (demo_mode_on()) {
            $days   = count($slots);
            $policy = payment_policy_for($c['refundsAllowed'], $slots[0]['date'], $slots[count($slots) - 1]['date']);
            $row = demo_add_booking([
                'type' => 'venue', 'customerId' => $c['customerId'], 'customerName' => $c['bookerName'],
                'customerEmail' => '', 'customerPhone' => (string) ($request->post('walkin_phone') ?? ''),
                'isWalkIn' => true, 'isUsep' => (bool) $c['usepId'], 'usepVerified' => (bool) $c['usepId'],
                'roomCode' => $c['roomCode'], 'roomName' => $c['roomCode'], 'venueName' => '',
                'eventName' => $eventName, 'eventDateIso' => $slots[0]['date'],
                'endDateIso' => $slots[count($slots) - 1]['date'], 'days' => $days,
                'attendees' => $attendees, 'capacity' => (int) $room['attendee_capacity'],
                'roomPrice' => $roomPrice, 'discountPercent' => $pct, 'amount' => $total,
                'method' => $method === 'cash' ? 'Cash' : 'GCash',
                'reservationCode' => 'approved',
                'paymentCode' => $c['refundsAllowed'] ? ($method === 'cash' ? 'await_cash' : 'await_gcash') : 'await_event',
                'refundsAllowed' => (bool) $c['refundsAllowed'], 'refundable' => false,
                'payment' => $policy, 'beds' => 0, 'nights' => 0,
            ]);
            $this->reply(200, ['ok' => true, 'demo' => true, 'bookingId' => $row['id'],
                'reference' => $row['bookingId'], 'payBy' => $policy['payByLabel'],
                'message' => 'Booking created (demo mode — nothing was saved).']);
        }

        /* ---- the write ---- */
        $queued = null;   /* the walk-in confirmation email, sent after commit */
        try {
            /* The deadline housekeeping runs BEFORE the transaction (migration 15):
               its UPDATEs lock every bookings row they scan, so inside the
               transaction two submits a moment apart deadlocked on each other. */
            $pdo->exec('CALL sp_expire_due_bookings()');
            $pdo->beginTransaction();
            if ($c['isNewWalkIn']) {
                $this->insertWalkIn($pdo, $c);
            }
            $ins = $pdo->prepare(
                "INSERT INTO bookings
                    (customer_id, room_id, booking_type, reservation_status, payment_status,
                     payment_method, is_usep_affiliated, affiliation_verified,
                     room_price, discount_percent, discount_amount, total_amount,
                     refunds_allowed, updated_by_user_id)
                 VALUES (:c, :r, 'venue', 'pending', 'locked', :m, :a, :av, :rp, :dp, :da, :t, :ra, :u)"
            );
            $ins->execute([
                ':c' => $c['customerId'], ':r' => $room['id'], ':m' => $method,
                ':a' => $c['usepId'], ':av' => $c['usepId'],
                ':rp' => $roomPrice, ':dp' => $pct, ':da' => $discount, ':t' => $total,
                ':ra' => $c['refundsAllowed'], ':u' => $c['actor'],
            ]);
            $bookingId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
                 VALUES (:b, :n, :s, :e, :a)'
            )->execute([':b' => $bookingId, ':n' => $eventName, ':s' => $startIso, ':e' => $endIso, ':a' => $attendees]);

            /* The same concurrency guard the customer flow goes through. */
            $slotCall = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
            foreach ($slots as $s) {
                $slotCall->execute([':b' => $bookingId, ':d' => $s['date'], ':s' => $s['start'], ':e' => $s['end']]);
                $slotCall->closeCursor();
            }

            $this->timeline($pdo, $bookingId, $c, $pct, 'Walk-in booking taken at the counter for ' . $c['bookerName'] . '.');

            /* Approved on the spot, through the one procedure that knows what "approved" means. */
            $ap = $pdo->prepare('CALL sp_approve_booking(:b, :u)');
            $ap->execute([':b' => $bookingId, ':u' => $c['actor']]);
            $ap->closeCursor();

            /* The walk-in's confirmation email, queued now, sent after commit. */
            if ($c['contactEmail'] !== '') {
                try {
                    $queued = queue_walkin_booking($pdo, $bookingId, $c['actor']);
                } catch (Throwable $mailErr) {
                    if ($mailErr instanceof PDOException) {
                        throw $mailErr;
                    }
                    error_log('VENUSeP walk-in confirmation email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            /* 40001 = a deadlock with a booking made at the same moment (MySQL
               1213, nothing was saved) — told like a taken slot. */
            $refused = in_array($e->getCode(), ['45000', '40001'], true);
            if (!$refused) {
                error_log('VENUSeP booking-create: ' . $e->getMessage());
            }
            $this->reply($refused ? 409 : 500, ['ok' => false,
                'message' => $refused
                    ? venue_slot_refusal_message($e)
                    : 'Something went wrong, so nothing was created.']);
        }

        $this->spendProof($c);
        /* Committed — now the email. */
        if ($queued !== null) {
            mail_send($pdo, $queued);
        }

        try {
            $after = $pdo->prepare('SELECT submitted_at, current_deadline_at FROM bookings WHERE id = :b');
            $after->execute([':b' => $bookingId]);
            $row = $after->fetch();
        } catch (PDOException $e) {
            $row = ['submitted_at' => null, 'current_deadline_at' => null];
        }

        $this->reply(200, [
            'ok'        => true,
            'bookingId' => $bookingId,
            'reference' => booking_reference($bookingId, $row['submitted_at']),
            'payBy'     => $row['current_deadline_at']
                            ? date('M j, Y', strtotime($row['current_deadline_at']))
                            : '—',
            'message'   => 'Booking created and approved.',
        ]);
    }
}
