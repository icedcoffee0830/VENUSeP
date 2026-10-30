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
    /* The limits the booking pages draw: hours 7:00 AM - 10:00 PM (DB-DECISIONS #9),
       and a range of at most 366 days or nights (rangeDates() / nightsOf()). */
    private const OPENS = '07:00';
    private const CLOSES = '22:00';
    public const MAX_DAYS = 366;
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

    /* One day's hours: real HH:MM times, end after start, inside opening hours. */
    public static function validHours(string $start, string $end): bool
    {
        return preg_match(self::HHMM, $start) && preg_match(self::HHMM, $end)
            && $end > $start && $start >= self::OPENS && $end <= self::CLOSES;
    }

    /* system_settings.hostel_advance_booking_max_days (DB-DECISIONS: a stay can't be
       reserved more than this many days before check-in). null = no limit set. */
    public static function hostelAdvanceDays(PDO $pdo): ?int
    {
        try {
            $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'hostel_advance_booking_max_days'")->fetchColumn();
        } catch (PDOException $e) {
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

    /* POST-PAY OVERDUE BLOCK (Aron, 2026-10-30): a customer with a booking whose
       payment is Overdue cannot book online until it is paid; it unblocks by itself
       once the payment status moves on. Staff at the counter see it but may book.

       customer id => "Room, Oct 1, 2026 — ₱1,800" naming that customer's oldest
       overdue booking; every such customer when $customerId is null. */
    public static function overdueBookings(PDO $pdo, ?int $customerId = null): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT b.customer_id, r.name, fn_booking_first_day(b.id) AS first_day, b.total_amount
                   FROM bookings b JOIN rooms r ON r.id = b.room_id
                  WHERE b.payment_status = 'overdue'" . ($customerId === null ? '' : ' AND b.customer_id = :c') . '
                  ORDER BY b.current_deadline_at, b.id'
            );
            $stmt->execute($customerId === null ? [] : [':c' => $customerId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('VENUSeP overdue check: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $amount = (float) $r['total_amount'];
            $out[(int) $r['customer_id']] ??= $r['name'] . ', '
                . ($r['first_day'] ? date('M j, Y', strtotime($r['first_day'])) : '—')
                . ' — ₱' . number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);   /* = usep_peso() */
        }
        return $out;
    }

    /* The refusal booking-submit gives, and the notice the booking pages show up front. */
    public static function overdueNotice(PDO $pdo, int $customerId): ?string
    {
        $which = self::overdueBookings($pdo, $customerId)[$customerId] ?? null;
        return $which === null ? null
            : 'You have an unpaid booking (' . $which . '). Please settle it before making a new booking.';
    }

    /* For a booking page: the signed-in customer's notice — never at the counter. */
    public static function overdueNoticeForPage(): ?string
    {
        if (counter_mode() || !customer_logged_in() || !($pdo = venusep_db())) {
            return null;
        }
        return self::overdueNotice($pdo, (int) session('customer_id'));
    }

    /* One exact bed per guest, free on EVERY night of the stay; the
       UNIQUE(active_bed_id, night_date) index is the last-bed race guard. Two stays
       submitted at once can pick the same free bed: the second one's insert hits
       that index (1062), so it takes the next free bed instead (2026-10-30).
       Throws RuntimeException('no_bed') when no bed is left, PDOException otherwise
       (a hard closure inside the stay is sp_assign_bed's SQLSTATE 45000). */
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
                try {
                    $assign->execute([':b' => $bookingId, ':o' => $occId, ':bed' => (int) $bedId]);
                    $assign->closeCursor();
                    break;
                } catch (PDOException $e) {
                    $assign->closeCursor();
                    if (($e->errorInfo[1] ?? null) !== 1062 || $try >= 5) {
                        throw $e;
                    }
                }
            }
        }
    }
}
