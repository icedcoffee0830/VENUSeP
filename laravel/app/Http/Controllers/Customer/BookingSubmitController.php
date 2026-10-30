<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDOException;
use RuntimeException;
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

   THE POLICY IS SNAPSHOTTED. refunds_allowed copies the live switch at the
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

    public function __invoke(Request $request)
    {
        extract(legacy('auth', 'documents', 'bookings', 'demo-mode', 'notifications'));

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

        /* ---- the room, and the rate THIS server believes in ---- */
        $roomStmt = $pdo->prepare(
            "SELECT r.id, r.name, r.room_type, r.is_active, v.name AS venue_name,
                    erd.attendee_capacity, erd.fee_per_day,
                    hrd.rate_per_head_per_night
               FROM rooms r
               JOIN venues v                     ON v.id = r.venue_id
               LEFT JOIN event_room_details erd  ON erd.room_id = r.id
               LEFT JOIN hostel_room_details hrd ON hrd.room_id = r.id
              WHERE r.room_code = :c"
        );
        $roomStmt->execute([':c' => $roomCode]);
        $room = $roomStmt->fetch();
        if (!$room || !$room['is_active']) {
            return self::reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
        }
        $wantType = $type === 'venue' ? 'event' : 'hostel';
        if ($room['room_type'] !== $wantType) {
            return self::reply(409, ['ok' => false, 'message' => 'That room does not take this kind of booking.']);
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

        /* The policy snapshot — read once, stored on the booking, never re-read. */
        $refundsAllowed = refunds_enabled() ? 1 : 0;

        /* -----------------------------------------------------------------
           DEMO MODE — everything above still ran: the room is real, the ID was
           required, the policy was read. The booking is recorded in the SESSION
           instead of the database, so the showcase leaves nothing behind.
           Placed AFTER validation on purpose: rehearse the real thing, minus
           the write.
           ----------------------------------------------------------------- */
        if (demo_mode_on()) {
            if ($type === 'venue') {
                $startIso  = (string) ($request->post('date_start') ?? '');
                $endIso    = (string) ($request->post('date_end') ?? '') ?: $startIso;
                $eventName = trim((string) ($request->post('event_name') ?? ''));
                $days      = max(1, (int) round((strtotime($endIso) - strtotime($startIso)) / 86400) + 1);
                $price     = (float) $room['fee_per_day'] * $days;
                $first = $startIso; $last = $endIso;
            } else {
                $checkIn  = (string) ($request->post('check_in') ?? '');
                $checkOut = (string) ($request->post('check_out') ?? '');
                $occ      = json_decode((string) ($request->post('occupants') ?? '[]'), true);
                $beds     = is_array($occ) ? count($occ) : 1;
                $nights   = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
                $price    = (float) $room['rate_per_head_per_night'] * $beds * $nights;
                $eventName = $beds . ' bed' . ($beds == 1 ? '' : 's') . ' · ' . $nights . ' night' . ($nights == 1 ? '' : 's');
                $first = $checkIn; $last = $checkOut;
            }
            /* Priced by the SAME rule the real path uses, from the room's own rate. */
            $policy = payment_policy_for($type === 'hostel' ? 'pay_to_use' : $refundsAllowed, $first, $last);
            $row = demo_add_booking([
                'type' => $type, 'roomCode' => $roomCode, 'roomName' => $room['name'] ?? $roomCode,
                'venue' => $room['name'] ?? $roomCode, 'venueName' => $room['venue_name'] ?? '',
                'customerId' => $customerId, 'customerName' => (string) (session('customer_name') ?? ''),
                'eventName' => $eventName,
                'eventDate' => $first ? date('F j, Y', strtotime($first)) : '—',
                'eventDateIso' => $first, 'endDateIso' => $last,
                'bookingDate' => date('F j, Y'), 'bookingDateIso' => date('Y-m-d'),
                'roomPrice' => $price, 'discountPercent' => 0.0, 'discountAmount' => 0.0,
                'amountValue' => $price, 'amount' => '₱' . number_format($price),
                'reservationCode' => 'pending', 'paymentCode' => 'locked',
                'bookingStatus' => 'Pending', 'paymentStatus' => 'Locked',
                'bookingStatusStaff' => 'Reservation pending review', 'paymentStatusStaff' => 'Payment locked',
                'method' => $method === 'cash' ? 'Cash' : 'GCash',
                'refundsAllowed' => (bool) $refundsAllowed, 'payToUse' => $type === 'hostel', 'refundable' => false,
                'refundStatus' => null, 'receiptVerdict' => null, 'payment' => $policy,
                'beds' => $type === 'hostel' ? ($beds ?? 0) : 0,
                'nights' => $type === 'hostel' ? ($nights ?? 0) : 0,
                'days' => $type === 'venue' ? ($days ?? 1) : 0,
            ]);
            return self::reply(200, [
                'ok' => true, 'demo' => true,
                'bookingId' => $row['id'], 'reference' => $row['bookingId'], 'idStored' => false,
                'message' => 'Booking submitted (demo mode — nothing was saved).',
            ]);
        }

        /* While refunds are OFF the customer must acknowledge the booking is final. */
        if (!$refundsAllowed && empty($request->post('agree_no_refund'))) {
            return self::reply(400, ['ok' => false, 'message' => 'Please confirm you understand this booking is non-refundable.']);
        }

        /* USeP Venues has no advance-notice requirement; Bahay Alumni keeps the
           12-hour rule. Mirrors leadMsFor() on the room page. Venues only. */
        $LEAD_HOURS = ($room['venue_name'] === 'USeP Venues') ? 0 : 12;

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
            $blockStmt = $pdo->prepare('SELECT fn_room_hard_blocked(:r, :d) AS blocked');
            $slots = [];
            for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $blockStmt->execute([':r' => $room['id'], ':d' => $d]);
                if ((int) $blockStmt->fetchColumn() === 1) {
                    continue;
                }
                $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
                $s = isset($t['start']) ? (string) $t['start'] : '';
                $e = isset($t['end'])   ? (string) $t['end']   : '';
                if (!BookingRules::validHours($s, $e)) {
                    return self::reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
                }
                $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
            }
            if (!$slots) {
                return self::reply(409, ['ok' => false, 'message' => 'Every day you picked is closed for maintenance.']);
            }
            /* Lead time, measured from the FIRST day actually held. */
            if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
                return self::reply(409, ['ok' => false, 'message' => 'Bookings must start at least ' . $LEAD_HOURS . ' hours from now.']);
            }

            /* Priced per DAY (inclusive), only days actually held are charged. */
            $price = (float) $room['fee_per_day'] * count($slots);

            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    "INSERT INTO bookings
                        (customer_id, room_id, booking_type, reservation_status, payment_status,
                         payment_method, is_usep_affiliated, affiliation_verified,
                         room_price, discount_percent, discount_amount, total_amount, refunds_allowed)
                     VALUES (:c, :r, 'venue', 'pending', 'locked', :m, :a, 0, :p, 0, 0, :p2, :ra)"
                );
                $ins->execute([
                    ':c' => $customerId, ':r' => $room['id'], ':m' => $method,
                    ':a' => $affiliated, ':p' => $price, ':p2' => $price, ':ra' => $refundsAllowed,
                ]);
                $bookingId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
                     VALUES (:b, :n, :s, :e, :a)'
                )->execute([':b' => $bookingId, ':n' => $eventName, ':s' => $startIso, ':e' => $endIso, ':a' => $attendees]);

                /* sp_add_venue_slot takes a room+date lock, re-checks maintenance AND
                   overlapping live holds, and SIGNALs if the slot is gone — the real
                   concurrency guard. */
                $slotCall = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
                foreach ($slots as $s) {
                    $slotCall->execute([':b' => $bookingId, ':d' => $s['date'], ':s' => $s['start'], ':e' => $s['end']]);
                    $slotCall->closeCursor();
                }

                $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')
                    ->execute([':b' => $bookingId, ':b2' => $bookingId]);
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                /* 45000 = a real conflict raised by the procedures (a taken slot or a
                   closed day). Anything else goes to the SERVER LOG. */
                if ($e->getCode() !== '45000') {
                    error_log('VENUSeP booking-submit (venue): ' . $e->getMessage());
                }
                $msg = $e->getCode() === '45000'
                    ? venue_slot_refusal_message($e)
                    : 'Something went wrong, so your booking was not submitted.';
                return self::reply($e->getCode() === '45000' ? 409 : 500, ['ok' => false, 'message' => $msg]);
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
            /* The advance limit (system_settings, Aron 2026-10-30): no further ahead than N days. */
            if ($tooFar = BookingRules::hostelTooFarAhead($pdo, $checkIn)) {
                return self::reply(400, ['ok' => false, 'message' => $tooFar]);
            }
            /* PAY TO USE (DB-DECISIONS #26): no method is chosen now (NULL). */
            $method = null;

            /* Priced per HEAD per NIGHT: beds x rate x nights. */
            $price = (float) $room['rate_per_head_per_night'] * count($clean) * $nights;

            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    "INSERT INTO bookings
                        (customer_id, room_id, booking_type, reservation_status, payment_status,
                         payment_method, is_usep_affiliated, affiliation_verified,
                         room_price, discount_percent, discount_amount, total_amount, refunds_allowed)
                     VALUES (:c, :r, 'hostel', 'pending', 'locked', :m, :a, 0, :p, 0, 0, :p2, :ra)"
                );
                $ins->execute([
                    ':c' => $customerId, ':r' => $room['id'], ':m' => $method,
                    ':a' => $affiliated, ':p' => $price, ':p2' => $price, ':ra' => $refundsAllowed,
                ]);
                $bookingId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in)
                     VALUES (:b, :i, :o, 0)'
                )->execute([':b' => $bookingId, ':i' => $checkIn, ':o' => $checkOut]);

                $occIns = $pdo->prepare(
                    'INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest)
                     VALUES (:b, :n, :g, :p)'
                );
                $occIds = [];
                foreach ($clean as $i => $o) {
                    $occIns->execute([':b' => $bookingId, ':n' => $o['name'], ':g' => $o['gender'], ':p' => $i === 0 ? 1 : 0]);
                    $occIds[] = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')
                    ->execute([':b' => $bookingId, ':b2' => $bookingId]);
                /* Committed BEFORE the beds are assigned: sp_assign_bed opens its own
                   transaction. A booking with no beds holds nothing, and the catch
                   below removes it if a bed is lost. */
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('VENUSeP booking-submit (hostel): ' . $e->getMessage());
                return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so your booking was not submitted.']);
            }

            /* One exact bed per guest, for every night of the stay; the
               UNIQUE(active_bed_id, night_date) index is the last-bed race guard. */
            try {
                $free = $pdo->prepare(
                    'SELECT hb.id FROM hostel_beds hb
                      WHERE hb.room_id = :r AND hb.is_active = 1
                        AND NOT EXISTS (SELECT 1 FROM bed_reservation_nights n
                                         WHERE n.bed_id = hb.id AND n.released_at IS NULL
                                           AND n.night_date >= :i AND n.night_date < :o)
                      ORDER BY hb.display_order, hb.id LIMIT 1'
                );
                $assign = $pdo->prepare('CALL sp_assign_bed(:b, :o, :bed)');
                foreach ($occIds as $occId) {
                    $free->execute([':r' => $room['id'], ':i' => $checkIn, ':o' => $checkOut]);
                    $bedId = $free->fetchColumn();
                    if ($bedId === false) {
                        throw new RuntimeException('no_bed');
                    }
                    $assign->execute([':b' => $bookingId, ':o' => $occId, ':bed' => (int) $bedId]);
                    $assign->closeCursor();
                }
            } catch (Throwable $e) {
                /* Not enough beds for the whole stay, or a hard closure inside it.
                   Remove the booking rather than leave a half-held one behind. */
                $pdo->prepare('DELETE FROM bookings WHERE id = :b')->execute([':b' => $bookingId]);
                return self::reply(409, ['ok' => false,
                    'message' => 'There are not enough free beds for every night of that stay. Please pick different dates or fewer beds.']);
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
            notify_new_request($pdo, $bookingId, (int) session('user_id'));
        } catch (PDOException $e) {
            error_log('VENUSeP booking-submit (notify): ' . $e->getMessage());
        }

        $submitted = $pdo->prepare('SELECT submitted_at FROM bookings WHERE id = :b');
        $submitted->execute([':b' => $bookingId]);

        return self::reply(200, [
            'ok'        => true,
            'bookingId' => $bookingId,
            'reference' => booking_reference($bookingId, $submitted->fetchColumn()),
            'idStored'  => $docError === '',
            'message'   => 'Booking submitted.',
        ]);
    }
}
