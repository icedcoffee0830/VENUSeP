<?php

namespace App\Http\Controllers\Customer;

use PDO;
use PDOException;
use RuntimeException;

/* The rules a new booking must pass (and the bed step), shared by the two places that create one:
   customer.booking-submit (online) and admin.booking-create (the counter).
   One copy, so the counter cannot accept what the website refuses. */
final class BookingRules
{
    /* A booking covers at most about 6 months; a venue can be booked 1 year ahead; one
       customer holds at most 5 unpaid upcoming bookings per office online (2026-11-01).
       Hours are each office's own (legacy offices.php). */
    public const MAX_DAYS = 183;
    private const HHMM = '/^([01]\d|2[0-3]):[0-5]\d$/';   /* a real time of day, "00:00".."23:59" */

    /* A real calendar date in yyyy-mm-dd: the pattern alone let "2030-02-30" through. */
    public static function isDate(string $iso): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* Whole days from one date to another. */
    public static function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }

    /* One day's hours: real HH:MM times, end after start, inside this office's hours. */
    public static function validHours(string $start, string $end, array $office): bool
    {
        return preg_match(self::HHMM, $start) && preg_match(self::HHMM, $end)
            && $end > $start && $start >= $office['opens'] && $end <= $office['closes'];
    }

    /* The refusal for a venue date beyond the year ahead, or null. */
    public static function venueTooFarAhead(string $firstDay): ?string
    {
        $max = (int) config('venusep.max_days_ahead');
        return self::daysBetween(date('Y-m-d'), $firstDay) <= $max ? null
            : 'Venues can be booked at most 1 year ahead. Pick a date on or before ' . date('M j, Y', strtotime('+' . $max . ' days')) . '.';
    }

    /* A stay cannot be split around a night its office is closed: the refusal, or null. */
    public static function stayClosed(array $office, string $checkIn, string $checkOut): ?string
    {
        for ($d = $checkIn; $d < $checkOut; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($why = office_closed_reason($office, $d)) {
                return 'The hostel is closed on ' . date('M j, Y', strtotime($d)) . ' (' . $why . '). A stay cannot be split around a closure. Please pick different dates.';
            }
        }
        return null;
    }

    /* The refusal once a customer holds MAX_UNPAID unpaid upcoming bookings at this office. */
    public static function tooManyUnpaid(PDO $pdo, int $customerId, int $venueId): ?string
    {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM bookings b JOIN rooms r ON r.id = b.room_id
              WHERE b.customer_id = :c AND r.venue_id = :v
                AND b.reservation_status IN ('pending', 'approved')
                AND b.payment_status IN ('locked', 'await_event', 'await_gcash', 'await_cash', 'await_pos')
                AND fn_booking_last_day(b.id) >= CURDATE()"
        );
        $st->execute([':c' => $customerId, ':v' => $venueId]);
        $max = (int) config('venusep.max_unpaid');
        return (int) $st->fetchColumn() < $max ? null
            : 'You already have ' . $max . ' unpaid bookings with this office. Please pay or cancel one before booking again.';
    }

    /* Has the event begun? A venue booking at its first slot; a hostel stay once its first
       day has passed or the guest is in. */
    public static function eventStarted(PDO $pdo, int $bookingId): bool
    {
        $st = $pdo->prepare(
            "SELECT COALESCE(
                (SELECT TIMESTAMP(s.slot_date, s.start_time) FROM venue_booking_slots s WHERE s.booking_id = :b ORDER BY s.slot_date, s.start_time LIMIT 1) <= NOW(),
                (SELECT h.checked_in = 1 OR h.check_in_date < CURDATE() FROM hostel_booking_details h WHERE h.booking_id = :b2), 0)"
        );
        $st->execute([':b' => $bookingId, ':b2' => $bookingId]);
        return (bool) $st->fetchColumn();
    }

    /* What the new booking keeps for later: the rate shown right now, and who it is for. */
    public static function stamp(PDO $pdo, int $bookingId, int $discount, $organization): void
    {
        $org = mb_substr(trim((string) $organization), 0, 190);
        $pdo->prepare('UPDATE bookings SET quoted_discount_percent = :d, organization_name = :o WHERE id = :b')
            ->execute([':d' => $discount, ':o' => $org !== '' ? $org : null, ':b' => $bookingId]);
    }

    /* system_settings.hostel_advance_booking_max_days (DB-DECISIONS: a stay can't be
       reserved more than this many days before check-in). null = no limit set. */
    public static function hostelAdvanceDays(PDO $pdo): ?int
    {
        try {
            $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hostel_advance_booking_max_days'")->fetchColumn();
        } catch (PDOException $e) {
            sql_failed($e);
            return null;
        }
        return ($v === false || (int) $v <= 0) ? null : (int) $v;
    }

    /* The refusal for a check-in beyond the advance limit, or null when it is within it. */
    public static function hostelTooFarAhead(PDO $pdo, string $checkIn): ?string
    {
        $max = self::hostelAdvanceDays($pdo);
        if ($max === null || self::daysBetween(date('Y-m-d'), $checkIn) <= $max) {
            return null;
        }
        return 'Hostel stays can be booked at most ' . $max . ' days ahead. Pick a check-in on or before '
            . date('M j, Y', strtotime('+' . $max . ' days')) . '.';
    }

    /* THE UNPAID-BOOKING BLOCK (Aron, 2026-11-01). The offices (one `venues` row each) are
       separate: a booking officially Overdue (its event is over and the grace days have
       passed) stops new online bookings at THAT office only. Anything earlier blocks
       nothing, and it unblocks by itself once the payment moves on.
       Staff at the counter see it but may book.

       $roomCode: only the office that room belongs to; null = every office.
       customer id => "Room at Office, Oct 1, 2026 — ₱1,800" naming that customer's oldest
       unpaid booking per office; every such customer when $customerId is null. */
    public static function overdueBookings(PDO $pdo, ?string $roomCode = null, ?int $customerId = null): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT b.customer_id, r.venue_id, r.name, v.name AS office, fn_booking_first_day(b.id) AS first_day, b.total_amount
                   FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN venues v ON v.id = r.venue_id
                  WHERE b.payment_status = 'overdue'"
                  . ($roomCode === null ? '' : ' AND r.venue_id = (SELECT venue_id FROM rooms WHERE room_code = :room)')
                  . ($customerId === null ? '' : ' AND b.customer_id = :c') . '
                  ORDER BY b.current_deadline_at, b.id'
            );
            $stmt->execute(($roomCode === null ? [] : [':room' => $roomCode]) + ($customerId === null ? [] : [':c' => $customerId]));
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            sql_failed($e);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $amount = (float) $r['total_amount'];
            $out[(int) $r['customer_id']][(int) $r['venue_id']] ??= $r['name'] . ' at ' . $r['office'] . ', '
                . ($r['first_day'] ? date('M j, Y', strtotime($r['first_day'])) : '—')
                . ' — ₱' . number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);   /* = usep_peso() */
        }
        return array_map(fn ($offices) => implode('; ', $offices), $out);
    }

    /* The refusal booking-submit gives, and the notice the booking pages show up front. */
    public static function overdueNotice(PDO $pdo, int $customerId, ?string $roomCode = null): ?string
    {
        $which = self::overdueBookings($pdo, $roomCode, $customerId)[$customerId] ?? null;
        return $which === null ? null
            : 'You have an unpaid booking (' . $which . '). Please settle it before booking with that office again. Other offices are not affected.';
    }

    /* For a booking page: the signed-in customer's notice — never at the counter.
       $room: the room on the page; the landing page has none and lists every office. */
    public static function overdueNoticeForPage(mixed $room = null): ?string
    {
        if (counter_mode() || !customer_logged_in() || !($pdo = venusep_db())) {
            return null;
        }
        return self::overdueNotice($pdo, (int) session('customer_id'), is_string($room) ? $room : null);
    }

    /* For the counter's "who" step: every customer who owes THIS room's office. */
    public static function overdueForCounter(mixed $room): array
    {
        return (counter_mode() && is_string($room) && ($pdo = venusep_db())) ? self::overdueBookings($pdo, $room) : [];
    }

    /* Opens a new booking's transaction (booking-submit and the counter). The
       deadline housekeeping runs just BEFORE it, never inside (migration 15): its
       UPDATEs lock every bookings row they scan, so inside the transaction two
       submits a moment apart deadlocked on each other's new booking row. */
    public static function beginBooking(PDO $pdo): void
    {
        $pdo->exec('CALL sp_expire_due_bookings()');
        $pdo->beginTransaction();
    }

    /* One exact bed per guest, free on EVERY night of the stay; the
       UNIQUE(active_bed_id, night_date) index is the last-bed race guard. Two stays
       submitted at once can pick the same free bed: the second one's insert hits
       that index (1062), so it takes the next free bed instead (2026-10-30).
       Throws RuntimeException('no_bed') when no bed is left, PDOException otherwise
       (a hard closure inside the stay is sp_assign_bed's SQLSTATE 45000).
       Call it OUTSIDE a transaction: each bed is its own transaction (since
       migration 15 sp_assign_bed leaves that to its caller), so a lost bed leaves
       no half-written row behind and the retry reads the beds afresh. */
    public static function assignBeds(PDO $pdo, int $roomId, int $bookingId, array $occupantIds, string $checkIn, string $checkOut): void
    {
        $free = $pdo->prepare(
            'SELECT hb.id FROM hostel_beds hb
              WHERE hb.room_id = :r AND hb.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM bed_reservation_nights n
                                 WHERE n.bed_id = hb.id AND n.released_at IS NULL
                                   AND n.night_date >= :i AND n.night_date < :o)
              ORDER BY hb.display_order, hb.id LIMIT 1'
        );
        $assign = $pdo->prepare('CALL sp_assign_bed(:b, :o, :bed)');
        foreach ($occupantIds as $occId) {
            for ($try = 1; ; $try++) {
                $free->execute([':r' => $roomId, ':i' => $checkIn, ':o' => $checkOut]);
                $bedId = $free->fetchColumn();
                $free->closeCursor();
                if ($bedId === false) {
                    throw new RuntimeException('no_bed');
                }
                /* inTransaction() asks the server: a database without migration 15
                   has already committed or rolled back inside the procedure. */
                $pdo->beginTransaction();
                try {
                    $assign->execute([':b' => $bookingId, ':o' => $occId, ':bed' => (int) $bedId]);
                    $assign->closeCursor();
                    if ($pdo->inTransaction()) { $pdo->commit(); }
                    break;
                } catch (PDOException $e) {
                    $assign->closeCursor();
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    if (($e->errorInfo[1] ?? null) !== 1062 || $try >= 5) {
                        throw $e;
                    }
                }
            }
        }
    }
}
