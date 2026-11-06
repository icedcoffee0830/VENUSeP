<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for what each office may change about itself: Office Settings, its closures, its
   refund switch, and the GCash account it is paid into.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   venues: the settings columns (opens_at, closes_at, closed_weekdays, discount_percent,
     postpay_grace_days, lead_hours, cleanup_gap_minutes, contact details, refunds_enabled).
   venue_settings_history: one row per changed setting: old value, new value, who, why.
   venue_closures: the dates an office is closed. Saving one cancels nothing.
   gcash_accounts: an office's GCash accounts; the current one is is_active = 1 with no
     valid_until, older ones stay so past receipts can be read.
   Read only: bookings, rooms, refunds, users, venue_booking_slots, bed_reservation_nights. */
class OfficeSettingsQueries
{
    /* $column comes from the controller's own list of fields, never from the page. */
    public static function saveSetting(int $venueId, string $column, mixed $value): void
    {
        DB::update("UPDATE venues SET $column = ? WHERE id = ?", [$value, $venueId]);
    }

    /* One line of the settings history. */
    public static function addHistory(int $venueId, string $field, string $old, string $new, ?string $note, int $actor): void
    {
        DB::insert("
            INSERT INTO venue_settings_history (venue_id, field, old_value, new_value, note, changed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$venueId, $field, $old, $new, $note, $actor]);
    }

    public static function addClosure(int $venueId, string $from, string $until, string $reason, int $actor): void
    {
        DB::insert("
            INSERT INTO venue_closures (venue_id, from_date, until_date, reason, created_by_user_id)
            VALUES (?, ?, ?, ?, ?)
        ", [$venueId, $from, $until, $reason, $actor]);
    }

    public static function removeClosure(int $closureId, int $venueId): void
    {
        DB::delete('DELETE FROM venue_closures WHERE id = ? AND venue_id = ?', [$closureId, $venueId]);
    }

    /* The live bookings of an office with a day or night inside these dates: id, submitted_at. */
    public static function bookingsInside(int $venueId, string $from, string $until): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT DISTINCT b.id, b.submitted_at
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            LEFT JOIN venue_booking_slots s ON s.booking_id = b.id AND s.released_at IS NULL
            LEFT JOIN bed_reservation_nights n ON n.booking_id = b.id AND n.released_at IS NULL
            WHERE r.venue_id = ?
              AND b.reservation_status IN ('pending', 'approved')
              AND ((s.slot_date BETWEEN ? AND ?) OR (n.night_date BETWEEN ? AND ?))
        ", [$venueId, $from, $until, $from, $until]));
    }

    /* The office's refund switch, locked until the caller's transaction ends; null when there is no such office. */
    public static function lockRefundSwitch(int $venueId): ?int
    {
        $row = DB::selectOne('SELECT refunds_enabled FROM venues WHERE id = ? FOR UPDATE', [$venueId]);
        return $row ? (int) $row->refunds_enabled : null;
    }

    public static function setRefundSwitch(int $venueId, int $enabled): void
    {
        DB::update('UPDATE venues SET refunds_enabled = ? WHERE id = ?', [$enabled, $venueId]);
    }

    /* Refund requests still open, by office: venue id => how many. */
    public static function openRefundsByOffice(): array
    {
        return array_column(DB::select("
            SELECT r.venue_id, COUNT(*) AS n
            FROM refunds f
            JOIN bookings b ON b.id = f.booking_id
            JOIN rooms r ON r.id = b.room_id
            WHERE f.refund_status IN ('requested', 'under_review', 'returned_for_correction', 'approved')
            GROUP BY r.venue_id
        "), 'n', 'venue_id');
    }

    /* Every change of a refund switch, oldest first. */
    public static function refundSwitchChanges(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT h.venue_id, h.changed_at, u.email
            FROM venue_settings_history h
            LEFT JOIN users u ON u.id = h.changed_by_user_id
            WHERE h.field = 'refunds'
            ORDER BY h.id
        "));
    }

    /* The names of the open offices, of this person's, with no current GCash account. */
    public static function officesWithoutGcash(): array
    {
        return array_column(DB::select("
            SELECT v.name
            FROM venues v
            WHERE v.is_active = 1" . staff_venue_sql('v.id IN (%s)', 'AND') . "
              AND NOT EXISTS (SELECT 1 FROM gcash_accounts g
                               WHERE g.venue_id = v.id AND g.is_active = 1 AND g.valid_until IS NULL)
            ORDER BY v.id
        "), 'name');
    }

    public static function hostelNames(): array
    {
        return array_column(DB::select("SELECT name FROM venues WHERE venue_type = 'hostel'"), 'name');
    }

    /* The office's current GCash account, or null. */
    public static function findCurrentGcash(int $venueId): ?array
    {
        $row = DB::selectOne("
            SELECT id, account_name, mobile_number, note
            FROM gcash_accounts
            WHERE venue_id = ? AND is_active = 1 AND valid_until IS NULL
        ", [$venueId]);
        return $row ? (array) $row : null;
    }

    /* Supersede, then add: the caller's one transaction, so the office is never left with no
       account or two. $current: the account it replaces, or null. */
    public static function replaceGcash(?array $current, int $venueId, string $name, string $mobile, ?string $note, int $actor): void
    {
        if ($current) {
            DB::update('UPDATE gcash_accounts SET is_active = 0, valid_until = NOW() WHERE id = ?', [$current['id']]);
        }
        DB::insert("
            INSERT INTO gcash_accounts (venue_id, account_name, mobile_number, note, created_by_user_id)
            VALUES (?, ?, ?, ?, ?)
        ", [$venueId, $name, $mobile, $note, $actor]);
    }
}
