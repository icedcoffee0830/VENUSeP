<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\BookingRules;
use App\Queries\BlockQueries;
use App\Queries\BookingCreateQueries;
use App\Queries\CustomerQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     agree_no_refund=0|1, shown_rate=<the rate the page showed>,
     booker_mode=account|walkin,
       account: customer_id[, id_identity=1: "ID checked", for an account with no password]
       walkin : walkin_name, walkin_phone, walkin_email (or walkin_no_email=1)[, walkin_address]
     id_checked=1, usep_id=0|1
   Replies with JSON: { ok, reference, bookingId, payBy, overdueAfter (post-pay venue only) }

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
        legacy('auth', 'db', 'bookings', 'refund-policy', 'receipt-emails', 'offices');
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
            'identityByIdCheck' => false,   /* an account with no password: staff ticked "ID checked" */
        ];
        if ($c['bookerMode'] === 'account') {
            $c['customerId'] = (int) ($request->post('customer_id') ?? 0);
            if ($c['customerId'] <= 0) {
                $this->reply(400, ['ok' => false, 'message' => 'Pick the customer&rsquo;s account.']);
            }
            try {
                $row = CustomerQueries::findForCounterBooking($c['customerId']);
            } catch (PDOException $e) {
                sql_failed($e);
                $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
            }
            if (!$row) {
                $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
            }
            /* The identity proof, re-checked server-side: the password checked in this staff
               session, or, for an account with no password, staff ticked "ID checked". */
            $proofs = session('counter_proof');
            $proof = is_array($proofs) && isset($proofs[$c['customerId']]) ? (int) $proofs[$c['customerId']] : 0;
            $c['identityByIdCheck'] = (bool) $row['no_password'];
            if ($c['identityByIdCheck'] && empty($request->post('id_identity'))) {
                $this->reply(403, ['ok' => false, 'message' => 'This account has no password. Look at a valid ID and tick “ID checked” before booking.']);
            }
            if (!$c['identityByIdCheck'] && ($proof <= 0 || (time() - $proof) > self::COUNTER_PROOF_TTL)) {
                $this->reply(403, ['ok' => false, 'message' => 'Ask the customer to confirm their password again before booking.']);
            }
            $c['bookerName'] = (string) $row['full_name'];
        } else {
            $c['bookerName'] = trim((string) ($request->post('walkin_name') ?? ''));
            $c['phone']      = ph_mobile_strict($request->post('walkin_phone') ?? '');
            $c['address']    = trim((string) ($request->post('walkin_address') ?? ''));
            if ($c['bookerName'] === '' || mb_strlen($c['bookerName']) > 190) {
                $this->reply(400, ['ok' => false, 'message' => 'Enter the guest&rsquo;s full name.']);
            }
            if ($c['phone'] === null) {
                $this->reply(400, ['ok' => false, 'message' => PH_MOBILE_MESSAGE]);
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
            $room = BookingCreateQueries::findRoom($roomCode);
        } catch (PDOException $e) {
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
        }
        if (!$room || $room['room_type'] !== ($type === 'hostel' ? 'hostel' : 'event')) {
            $this->reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
        }
        /* Staff take counter bookings for THEIR halls only (DB-DECISIONS 28); admins for their offices. */
        if (!staff_may_handle_room($room['id'], $room['venue_id'])) {
            $this->reply(403, ['ok' => false, 'message' => 'This room is not one you handle. Only its staff or an administrator can book it at the counter.']);
        }
        if (!$room['is_active']) {
            $this->reply(409, BookingRules::roomClosed($room));
        }
        /* New price wins: staff tell the customer, then collect or return the difference. */
        if ($changed = BookingRules::priceChanged($room, $roomCode, $request->post('shown_rate'), 'Please check with the customer and submit again.')) {
            $this->reply(409, $changed);
        }
        /* Blocked at this office (DB-DECISIONS 28); trg_bookings_block_ins refuses it too. */
        if ($c['bookerMode'] === 'account' && BlockQueries::findActive($c['customerId'], (int) $room['venue_id']) !== null) {
            $this->reply(409, ['ok' => false, 'error' => 'blocked', 'message' => 'Blocked at this office. This customer cannot make new bookings here until an admin lifts the block.']);
        }
        $c['room'] = $room;

        $c['refundsAllowed'] = office_by_id($room['venue_id'])['refunds'] ? 1 : 0;   /* this office's switch (DB-DECISIONS 28), snapshotted */
        if (!$c['refundsAllowed'] && empty($request->post('agree_no_refund'))) {
            $this->reply(400, ['ok' => false, 'message' => 'The customer must confirm they understand the booking is non-refundable.']);
        }

        $type === 'hostel' ? $this->hostel($request, $pdo, $c) : $this->venue($request, $c);
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
    private function insertWalkIn(array &$c): void
    {
        $c['customerId'] = CustomerQueries::addWalkIn($c['bookerName'], $c['phone'], $c['contactEmail'], $c['address']);
    }

    /* The booking row of a counter booking: the discount is granted here. */
    private function newBooking(string $type, array $c, string $method, float $roomPrice, float $pct, float $discount, float $total): array
    {
        return ['customer' => $c['customerId'], 'room' => $c['room']['id'], 'type' => $type, 'method' => $method,
                'affiliated' => $c['usepId'], 'verified' => $c['usepId'], 'price' => $roomPrice, 'percent' => $pct,
                'discount' => $discount, 'total' => $total, 'refunds' => $c['refundsAllowed'], 'actor' => $c['actor'],
                'quotedPercent' => (int) $c['room']['discount_percent'], 'organization' => request()->post('organization')];
    }

    /* THE EVIDENCE — each row is a judgement a person made, with their name on it. */
    private function timeline(int $bookingId, array $c, float $pct, string $createdNote): void
    {
        $add = fn (string $code, string $note) => BookingCreateQueries::addHistory($bookingId, $code, $c['actor'], $note);
        $add('counter_created', $createdNote);
        $add('counter_id_checked', $c['usepId']
            ? 'Valid USeP ID inspected in person; ' . (int) $pct . '% affiliate discount applied.'
            : 'Valid ID inspected in person; no affiliate discount.');
        if ($c['bookerMode'] === 'account' && ($owes = BookingRules::overdueBookings(null, (int) $c['customerId'])[(int) $c['customerId']] ?? null)) {
            $add('counter_overdue_booked', 'The customer has an unpaid booking: ' . $owes . '.');
        }
        if ($c['bookerMode'] === 'account') {
            $c['identityByIdCheck']
                ? $add('counter_identity_id_checked', 'ID checked: the account has no password, so staff confirmed who the customer is from a valid ID seen in person.')
                : $add('counter_identity_confirmed', 'Customer confirmed the account by entering their password at the counter.');
        }
        if ($c['walkinNoEmail']) {
            $add('counter_no_email', 'Guest has no email: nothing is emailed; the Transaction Receipt is printed at the counter.');
        }
    }

    /* =====================================================================
       HOSTEL AT THE COUNTER (2026-10-27). PAY TO USE (DB-DECISIONS #26): the
       stay is NOT recorded unless it is paid — written approved and
       paid_cash, with its payment row and System Receipt. Each bed is its own
       transaction (BookingRules::assignBeds), so the stay is committed first,
       then the beds, then the approval and payment; a failing step removes the stay.
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
        if ($closed = BookingRules::stayClosed(office_by_id($room['venue_id']), $checkIn, $checkOut)) {
            $this->reply(409, ['ok' => false, 'message' => $closed]);
        }
        if ($tooFar = BookingRules::tooFarAhead($checkOut)) {
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
        $pct       = $c['usepId'] ? (float) $room['discount_percent'] : 0.0;
        $discount  = round($roomPrice * $pct) / 100;
        $total     = $roomPrice - $discount;
        $stayLabel = count($guests) . ' bed' . (count($guests) === 1 ? '' : 's') . ' · ' . $nights . ' night' . ($nights === 1 ? '' : 's');

        /* ---- 1. the stay itself ---- */
        $bookingId = 0;
        $removeStay = function () use (&$bookingId, &$c) {
            if ($bookingId) {
                BookingCreateQueries::remove($bookingId);
            }
            if ($c['isNewWalkIn'] && $c['customerId']) {
                CustomerQueries::removeWalkIn($c['customerId']);
            }
        };
        try {
            $occIds = BookingCreateQueries::saveNew(function () use (&$bookingId, &$c, $method, $roomPrice, $pct, $discount, $total, $checkIn, $checkOut, $guests) {
                if ($c['isNewWalkIn']) {
                    $this->insertWalkIn($c);
                }
                $bookingId = BookingCreateQueries::addBooking($this->newBooking('hostel', $c, $method, $roomPrice, $pct, $discount, $total));
                $ids = BookingCreateQueries::addStay($bookingId, $checkIn, $checkOut, $guests);
                BookingCreateQueries::setPayDeadline($bookingId);
                return $ids;
            });
        } catch (PDOException $e) {
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
        }

        /* ---- 2. one exact bed per guest, free on EVERY night ---- */
        try {
            BookingRules::assignBeds($pdo, (int) $room['id'], $bookingId, $occIds, $checkIn, $checkOut);
        } catch (Throwable $e) {
            $removeStay();
            if ($e instanceof PDOException) {
                sql_failed($e);
            }
            $this->reply(409, ['ok' => false,
                'message' => ($e instanceof PDOException && stripos($e->getMessage(), 'maintenance') !== false)
                    ? 'The room is closed for maintenance during that stay. Pick other dates.'
                    : 'There are not enough free beds for every night of that stay. Pick other dates or fewer beds.']);
        }

        /* ---- 3. the evidence, the approval, the cash, the receipt email ---- */
        $queued = null;
        try {
            DB::transaction(function () use ($bookingId, $c, $pct, &$queued) {
                $this->timeline($bookingId, $c, $pct, 'Walk-in stay booked at the front desk for ' . $c['bookerName'] . '.');
                BookingCreateQueries::approveNow($bookingId, $c['actor']);
                /* the cash staff took before pressing the button: paid, with its
                   payment row and System Receipt, in this same transaction */
                $receiptId = payment_confirm($bookingId, $c['actor'], 'Cash received at the counter.', true);
                if ($receiptId === null) {
                    throw new RuntimeException('no receipt was issued');
                }
                /* One email, not two: the System Receipt already names the stay. */
                if ($c['contactEmail'] !== '') {
                    try {
                        $queued = queue_walkin_receipt($bookingId, $receiptId, $c['actor']);
                    } catch (Throwable $mailErr) {
                        if ($mailErr instanceof PDOException) {
                            throw $mailErr;
                        }
                        error_log('VENUSeP walk-in receipt email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
                    }
                }
            });
        } catch (Throwable $e) {
            $removeStay();
            sql_failed($e);
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created and nothing was charged.']);
        }

        $this->spendProof($c);
        if ($queued !== null) {
            mail_send($queued);
        }
        $row = BookingCreateQueries::findDates($bookingId);
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
    private function venue(Request $request, array $c): never
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

        $slots = [];
        for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (BookingCreateQueries::isHardBlocked((int) $room['id'], $d)) {
                continue;                       // a venue booking books AROUND a closed day
            }
            $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
            $s = isset($t['start']) ? (string) $t['start'] : '';
            $e = isset($t['end'])   ? (string) $t['end']   : '';
            if (!BookingRules::validHours($s, $e, office_by_id($room['venue_id']))) {
                $this->reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
            }
            $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
        }
        if (!$slots) {
            $this->reply(409, ['ok' => false, 'message' => 'Every day picked is closed for maintenance.']);
        }

        if ($far = BookingRules::tooFarAhead($endIso)) {
            $this->reply(409, ['ok' => false, 'message' => $far]);
        }
        /* The office's lead time applies to walk-ins too (time for the Official Receipt). */
        $LEAD_HOURS = office_by_id($room['venue_id'])['lead'];
        if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
            $this->reply(409, ['ok' => false,
                'message' => 'Venue bookings must start at least ' . $LEAD_HOURS . ' hours from now — there has to be time for the Official Receipt.']);
        }

        /* Priced per day for the days actually held; the discount applies HERE. */
        $roomPrice = (float) $room['fee_per_day'] * count($slots);
        $pct       = $c['usepId'] ? (float) $room['discount_percent'] : 0.0;
        $discount  = round($roomPrice * $pct) / 100;
        $total     = $roomPrice - $discount;
        $method    = $c['method'];
        $policy    = payment_policy_for($c['refundsAllowed'], $slots[0]['date'], $slots[count($slots) - 1]['date']);
        $postpay   = $policy['policy'] === 'postpay';

        /* ---- the write ---- */
        $queued = null;   /* the walk-in confirmation email, sent after commit */
        try {
            $bookingId = BookingCreateQueries::saveNew(function () use (&$c, &$queued, $method, $roomPrice, $pct, $discount, $total, $eventName, $startIso, $endIso, $attendees, $slots) {
                if ($c['isNewWalkIn']) {
                    $this->insertWalkIn($c);
                }
                $id = BookingCreateQueries::addBooking($this->newBooking('venue', $c, $method, $roomPrice, $pct, $discount, $total));
                /* The same concurrency guard the customer flow goes through. */
                BookingCreateQueries::addEvent($id, $eventName, $startIso, $endIso, $attendees, $slots);

                $this->timeline($id, $c, $pct, 'Walk-in booking taken at the counter for ' . $c['bookerName'] . '.');
                BookingCreateQueries::approveNow($id, $c['actor']);

                /* The walk-in's confirmation email, queued now, sent after commit. */
                if ($c['contactEmail'] !== '') {
                    try {
                        $queued = queue_walkin_booking($id, $c['actor']);
                    } catch (Throwable $mailErr) {
                        if ($mailErr instanceof PDOException) {
                            throw $mailErr;
                        }
                        error_log('VENUSeP walk-in confirmation email for booking ' . $id . ': ' . $mailErr->getMessage());
                    }
                }
                return $id;
            });
        } catch (PDOException $e) {
            /* 40001 = a deadlock with a booking made at the same moment (MySQL
               1213, nothing was saved) — told like a taken slot. */
            $refused = in_array($e->getCode(), ['45000', '40001'], true);
            if (!$refused) {
                sql_failed($e);
            }
            $this->reply($refused ? 409 : 500, ['ok' => false,
                'message' => $refused
                    ? venue_slot_refusal_message($e)
                    : 'Something went wrong, so nothing was created.']);
        }

        $this->spendProof($c);
        /* Committed — now the email. */
        if ($queued !== null) {
            mail_send($queued);
        }

        try {
            $row = BookingCreateQueries::findDates($bookingId);
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
            $row = ['submitted_at' => null, 'current_deadline_at' => null];
        }

        /* Read out at the counter to staff AND customer: post-pay is due the
           day after the event (customer_pay_by_label); the stored deadline is
           when it turns Overdue. */
        $deadline = $row['current_deadline_at'] ? date('M j, Y', strtotime($row['current_deadline_at'])) : '—';
        $this->reply(200, [
            'ok'           => true,
            'bookingId'    => $bookingId,
            'reference'    => booking_reference($bookingId, $row['submitted_at']),
            'payBy'        => $postpay ? customer_pay_by_label($policy) : $deadline,
            'overdueAfter' => $postpay ? $deadline : null,
            'message'      => 'Booking created and approved.',
        ]);
    }
}
