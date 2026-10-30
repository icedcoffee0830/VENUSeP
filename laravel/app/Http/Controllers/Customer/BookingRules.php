<?php

namespace App\Http\Controllers\Customer;

/* The rules a new booking must pass, shared by the two places that create one:
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
}
