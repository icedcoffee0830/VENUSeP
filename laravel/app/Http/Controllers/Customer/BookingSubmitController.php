<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Queries\BookingCreateQueries;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDOException;
use Throwable;

/* =====================================================================
   BOOKING SUBMIT (was customer/booking-submit.php) — the ONLY way a
   customer creates a booking.

   POST (multipart, because the ID comes with it)
     _token (Laravel's CSRF check), type=venue|hostel, room=<room_code>,
     affiliated=0|1, method=gcash|cash, agree_no_refund=0|1
     venue:  event_name, date_start, date_end, attendees, times=<json>
     hostel: check_in, check_out, occupants=<json [{name,gender}]>
     FILES:  id_document
   Replies with JSON: { ok, reference, bookingId }

   NOTHING THE BROWSER SENDS ABOUT MONEY OR AVAILABILITY IS TRUSTED.
   The price is recomputed from the room's own rate, and availability is
   decided by sp_add_venue_slot() / sp_assign_bed() — the same procedures
   staff and the seed go through.

   THE DISCOUNT IS NOT APPLIED HERE. `affiliated` is the customer's CLAIM;
   a new booking stores the full price with affiliation_verified = 0, and
   approval applies the rate (DB-DECISIONS #3).

   THE POLICY IS SNAPSHOTTED. refunds_allowed copies the office's switch at the
   moment of booking and never changes again (DB-DECISIONS #18).

   An expired token is answered by Laravel's CSRF check, which bootstrap/app.php
   turns into the old 400 "page has expired" JSON (401 when signed out).
   ===================================================================== */
class BookingSubmitController extends Controller
{
    private static function reply(int $status, array $data, array $headers = [])
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'] + $headers);
    }

    /* The online booking row: the full price, the affiliation only claimed (DB-DECISIONS #3). */
    private static function newBooking(string $type, int $customerId, array $room, ?string $method, int $affiliated, float $price, int $refundsAllowed, Request $request): array
    {
        return ['customer' => $customerId, 'room' => $room['id'], 'type' => $type, 'method' => $method,
                'affiliated' => $affiliated, 'verified' => 0, 'price' => $price, 'percent' => 0, 'discount' => 0,
                'total' => $price, 'refunds' => $refundsAllowed, 'actor' => null,
                'quotedPercent' => (int) $room['discount_percent'], 'organization' => $request->post('organization')];
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'documents', 'bookings', 'notifications', 'offices');

        if (!$request->isMethod('POST')) {
            return self::reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        if (!customer_logged_in()) {
            return self::reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }

        $customerId = (int) session('customer_id');
        $type       = (string) $request->post('type', '');
        $roomCode   = (string) $request->post('room', '');
        $affiliated = !empty($request->post('affiliated')) ? 1 : 0;
        $method     = $request->post('method') === 'cash' ? 'cash' : 'gcash';

        if (!in_array($type, ['venue', 'hostel'], true)) {
            return self::reply(400, ['ok' => false, 'message' => 'Invalid request.']);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode)) {
            return self::reply(400, ['ok' => false, 'message' => 'Invalid room.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return self::reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your booking was not submitted.']);
        }

        /* An Overdue booking blocks new online bookings at its own office (2026-11-01). */
        venusep_expire_due_bookings();
        if ($overdue = BookingRules::overdueNotice($customerId, $roomCode)) {
            return self::reply(409, ['ok' => false, 'error' => 'overdue', 'message' => $overdue]);
        }

        /* ---- the room, and the rate THIS server believes in ---- */
        $room = BookingCreateQueries::findRoom($roomCode);
        if (!$room) {
            return self::reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
        }
        if (!$room['is_active']) {
            return self::reply(409, BookingRules::roomClosed($room));
        }
        $wantType = $type === 'venue' ? 'event' : 'hostel';
        if ($room['room_type'] !== $wantType) {
            return self::reply(409, ['ok' => false, 'message' => 'That room does not take this kind of booking.']);
        }

        if ($changed = BookingRules::priceChanged($room, $roomCode, $request->post('shown_rate'), 'Please check and submit again.')) {
            return self::reply(409, $changed);
        }

        $office = office_by_id($room['venue_id']);
        /* Blocked at this office (DB-DECISIONS 28): the notice, never the reason. */
        if ($blocked = BookingRules::blockNotice($customerId, (int) $room['venue_id'])) {
            return self::reply(409, ['ok' => false, 'error' => 'blocked', 'message' => $blocked['message'] . ' ' . $blocked['contact']]);
        }
        if ($unpaid = BookingRules::tooManyUnpaid($customerId, (int) $room['venue_id'])) {
            return self::reply(409, ['ok' => false, 'error' => 'too_many_unpaid', 'message' => $unpaid]);
        }

        /* The ID is required for EVERY booking (PROJECT-HANDOFF 4.7) — checked here
           and not merely in the page, because the page's check is a courtesy. */
        $idFile = $request->file('id_document');
        if (!$idFile instanceof UploadedFile || !$idFile->isValid()) {
            return self::reply(400, ['ok' => false, 'message' => 'A valid ID is required to submit a booking.']);
        }
        /* ...and it must be a file doc_store() will keep (type, size, a real image),
           checked BEFORE anything is written — else the slot was held with no ID. */
        $idError = '';
        if (doc_validate($idFile->getPathname(), $idFile->getClientOriginalName(), $idError) === null) {
            return self::reply(400, ['ok' => false, 'message' => $idError]);
        }

        /* The policy snapshot — this office's switch (DB-DECISIONS 28), read once, stored on the booking, never re-read. */
        $refundsAllowed = $office['refunds'] ? 1 : 0;

        /* While refunds are OFF the customer must acknowledge the booking is final. */
        if (!$refundsAllowed && empty($request->post('agree_no_refund'))) {
            return self::reply(400, ['ok' => false, 'message' => 'Please confirm you understand this booking is non-refundable.']);
        }

        $LEAD_HOURS = $office['lead'];

        /* ============================ VENUE ============================ */
        if ($type === 'venue') {
            $eventName = trim((string) ($request->post('event_name') ?? ''));
            $startIso  = (string) ($request->post('date_start') ?? '');
            $endIso    = (string) ($request->post('date_end') ?? '') ?: $startIso;
            $attendees = (int) ($request->post('attendees') ?? 0);
            $times     = json_decode((string) ($request->post('times') ?? '[]'), true);
            if (!is_array($times)) { $times = []; }

            if ($eventName === '' || mb_strlen($eventName) > 190) {
                return self::reply(400, ['ok' => false, 'message' => 'Enter an event name.']);
            }
            if (!BookingRules::isDate($startIso) || !BookingRules::isDate($endIso) || $endIso < $startIso) {
                return self::reply(400, ['ok' => false, 'message' => 'Pick valid reservation dates.']);
            }
            if (BookingRules::daysBetween($startIso, $endIso) + 1 > BookingRules::MAX_DAYS) {
                return self::reply(400, ['ok' => false, 'message' => 'A booking can cover at most ' . BookingRules::MAX_DAYS . ' days.']);
            }
            if ($attendees <= 0 || $attendees > (int) $room['attendee_capacity']) {
                return self::reply(400, ['ok' => false, 'message' => 'Enter an attendee count within the room capacity.']);
            }

            /* Per-day slots. Days under a HARD closure are skipped (a venue booking
               "books around" a blocked day) — fn_room_hard_blocked() decides. */
            $slots = [];
            for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (BookingCreateQueries::isHardBlocked((int) $room['id'], $d)) {
                    continue;
                }
                $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
                $s = isset($t['start']) ? (string) $t['start'] : '';
                $e = isset($t['end'])   ? (string) $t['end']   : '';
                if (!BookingRules::validHours($s, $e, $office)) {
                    return self::reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
                }
                $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
            }
            if (!$slots) {
                return self::reply(409, ['ok' => false, 'message' => 'Every day you picked is closed for maintenance.']);
            }
            if ($far = BookingRules::tooFarAhead($endIso)) {
                return self::reply(409, ['ok' => false, 'message' => $far]);
            }
            /* Lead time, measured from the FIRST day actually held. */
            if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
                return self::reply(409, ['ok' => false, 'message' => 'Bookings must start at least ' . $LEAD_HOURS . ' hours from now.']);
            }

            /* Priced per DAY (inclusive), only days actually held are charged. */
            $price = (float) $room['fee_per_day'] * count($slots);

            $booking = self::newBooking('venue', $customerId, $room, $method, $affiliated, $price, $refundsAllowed, $request);
            try {
                $bookingId = BookingCreateQueries::saveNew(function () use ($booking, $eventName, $startIso, $endIso, $attendees, $slots) {
                    $id = BookingCreateQueries::addBooking($booking);
                    BookingCreateQueries::addEvent($id, $eventName, $startIso, $endIso, $attendees, $slots);
                    BookingCreateQueries::setPayDeadline($id);
                    return $id;
                });
            } catch (PDOException $e) {
                /* 45000 = a real conflict raised by the procedures (a taken slot or a
                   closed day); 40001 = a deadlock with a booking made at the same
                   moment (MySQL 1213, nothing was saved) — told like a taken slot.
                   Anything else goes to the SERVER LOG. */
                $refused = in_array($e->getCode(), ['45000', '40001'], true);
                if (!$refused) {
                    sql_failed($e);
                }
                $msg = $refused
                    ? venue_slot_refusal_message($e)
                    : 'Something went wrong, so your booking was not submitted.';
                return self::reply($refused ? 409 : 500, ['ok' => false, 'message' => $msg]);
            }

        /* ============================ HOSTEL ============================ */
        } else {
            $checkIn  = (string) ($request->post('check_in') ?? '');
            $checkOut = (string) ($request->post('check_out') ?? '');
            $occupants = json_decode((string) ($request->post('occupants') ?? '[]'), true);

            if (!BookingRules::isDate($checkIn) || !BookingRules::isDate($checkOut) || $checkOut <= $checkIn) {
                return self::reply(400, ['ok' => false, 'message' => 'Check-out must be after check-in.']);
            }
            if (BookingRules::daysBetween($checkIn, $checkOut) > BookingRules::MAX_DAYS) {
                return self::reply(400, ['ok' => false, 'message' => 'A stay can be at most ' . BookingRules::MAX_DAYS . ' nights.']);
            }
            if (!is_array($occupants) || !$occupants) {
                return self::reply(400, ['ok' => false, 'message' => 'Name at least one guest.']);
            }
            $clean = [];
            foreach ($occupants as $o) {
                $n = trim((string) ($o['name'] ?? ''));
                $g = (string) ($o['gender'] ?? '');
                if ($n === '') {
                    return self::reply(400, ['ok' => false, 'message' => 'Every bed needs a named guest.']);
                }
                $clean[] = ['name' => mb_substr($n, 0, 190), 'gender' => in_array($g, ['male', 'female', 'other'], true) ? $g : 'other'];
            }
            /* Nights are EXCLUSIVE of check-out: Aug 1 -> Aug 4 is 3 nights. */
            $nights = BookingRules::daysBetween($checkIn, $checkOut);
            /* SAME-DAY CHECK-IN IS ALLOWED (2026-10-27): only a past date is refused. */
            if ($checkIn < date('Y-m-d')) {
                return self::reply(400, ['ok' => false, 'message' => 'Check-in cannot be in the past.']);
            }
            if ($closed = BookingRules::stayClosed($office, $checkIn, $checkOut)) {
                return self::reply(409, ['ok' => false, 'message' => $closed]);
            }
            if ($tooFar = BookingRules::tooFarAhead($checkOut)) {
                return self::reply(400, ['ok' => false, 'message' => $tooFar]);
            }
            /* PAY TO USE (DB-DECISIONS #26): no method is chosen now (NULL). */
            $method = null;

            /* Priced per HEAD per NIGHT: beds x rate x nights. */
            $price = (float) $room['rate_per_head_per_night'] * count($clean) * $nights;

            $booking = self::newBooking('hostel', $customerId, $room, $method, $affiliated, $price, $refundsAllowed, $request);
            try {
                /* Committed BEFORE the beds are assigned: each bed is its own
                   transaction (BookingRules::assignBeds), so a bed lost to a
                   simultaneous stay is retried with fresh reads. A booking with no
                   beds holds nothing, and the catch below removes it if a bed is lost. */
                [$bookingId, $occIds] = BookingCreateQueries::saveNew(function () use ($booking, $checkIn, $checkOut, $clean) {
                    $id = BookingCreateQueries::addBooking($booking);
                    $guests = BookingCreateQueries::addStay($id, $checkIn, $checkOut, $clean);
                    BookingCreateQueries::setPayDeadline($id);
                    return [$id, $guests];
                });
            } catch (PDOException $e) {
                sql_failed($e);
                return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so your booking was not submitted.']);
            }

            /* One exact bed per guest, for every night of the stay; the
               UNIQUE(active_bed_id, night_date) index is the last-bed race guard;
               a bed lost to a simultaneous stay is retried with the next one. */
            try {
                BookingRules::assignBeds($pdo, (int) $room['id'], $bookingId, $occIds, $checkIn, $checkOut);
            } catch (Throwable $e) {
                /* Not enough beds for the whole stay, or a hard closure inside it.
                   Remove the booking rather than leave a half-held one behind. */
                BookingCreateQueries::remove($bookingId);
                if ($e instanceof PDOException) {
                    sql_failed($e);
                }
                return self::reply(409, ['ok' => false,
                    'message' => ($e instanceof PDOException && stripos($e->getMessage(), 'maintenance') !== false)
                        ? 'The room is closed for maintenance during that stay. Please pick different dates.'
                        : 'There are not enough free beds for every night of that stay. Please pick different dates or fewer beds.']);
            }
        }

        /* ---- the ID, stored outside the web root (legacy documents.php) ---- */
        $docError = '';
        doc_store($bookingId, 'customer_id', $idFile->getPathname(),
                  $idFile->getClientOriginalName(), (int) session('user_id'), $docError);
        /* A booking whose ID failed to store is NOT rejected: the slot is held and
           staff see a booking with no ID document, which they can chase. */

        /* Tell the venue's staff and the admins (2026-10-28). A failure costs a
           notification, never the booking. */
        try {
            notify_new_request($bookingId, (int) session('user_id'));
        } catch (PDOException $e) {
            sql_failed($e, optional: true);
        }

        return self::reply(200, [
            'ok'        => true,
            'bookingId' => $bookingId,
            'reference' => booking_reference($bookingId, BookingCreateQueries::findDates($bookingId)['submitted_at']),
            'idStored'  => $docError === '',
            'message'   => 'Booking submitted.',
        ]);
    }
}
