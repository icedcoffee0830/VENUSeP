<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL that reads bookings for every page: lists, the queue, the details, the History.
   Read only. legacy/includes/bookings.php turns these rows into the shape pages use.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   bookings, venue_booking_details, hostel_booking_details: notes in BookingQueries.
   venue_booking_slots: one row per day a venue booking holds; released_at set = let go.
   hostel_occupants, bed_reservations, hostel_beds: who stays in which bed.
   booking_documents: stored papers; gcash_receipts: uploaded receipts, every attempt.
   booking_timeline: the History. reservation_statuses, payment_statuses: the words for a status.
   fn_customer_blocked: is the customer blocked at this booking's office. */
class BookingListQueries
{
    private static function rows(array $objects): array
    {
        return array_map(fn ($r) => (array) $r, $objects);
    }

    /* THE read: every column a page may show. $where: 'WHERE ...' or nothing, with its named
       $args; $limit 0 = every row. */
    public static function find(string $where, array $args, int $limit): array
    {
        return self::rows(DB::select("
        SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
               b.is_usep_affiliated, b.affiliation_verified,
               b.room_price, b.discount_percent, b.discount_amount, b.total_amount,
               b.refunds_allowed, b.pay_to_use, b.current_deadline_at, b.receipt_attempts_allowed, b.submitted_at, b.approved_at,
               b.completed_at, b.cancelled_at, b.customer_notes, b.staff_notes,
               c.id AS customer_id, c.full_name AS customer_name, c.phone AS customer_phone,
               c.university_id_no, u.email AS customer_email, c.contact_email,
               r.room_code, r.name AS room_name,
               v.name AS venue_name, v.postpay_grace_days AS office_grace,
               COALESCE(b.quoted_discount_percent, v.discount_percent) AS quoted_discount, b.organization_name, b.write_off_reason, hd.checked_out,
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
               /* The uploaded GCash screenshot's own row id — document-view.php's
                  ?receipt= param. Not the System Receipt (#22); this is the proof
                  of payment the customer sent, which staff review it against.
                  Rejected uploads are on record too (DB-DECISIONS #6), so the one
                  that went through is preferred; a rejected one only when there
                  is nothing else. */
               (SELECT gr.id FROM gcash_receipts gr WHERE gr.booking_id = b.id
                 ORDER BY gr.verdict = 'rejected', gr.id DESC LIMIT 1) AS gcash_receipt_id,
               (SELECT COALESCE(JSON_LENGTH(gr.flags_json), 0) FROM gcash_receipts gr
                 WHERE gr.booking_id = b.id ORDER BY gr.id DESC LIMIT 1) AS receipt_flags,
               /* The 5-attempt rule: every upload counts, rejected ones too. */
               (SELECT COUNT(*) FROM gcash_receipts gr WHERE gr.booking_id = b.id) AS receipt_attempts,
               /* the customer is blocked at this booking's office (DB-DECISIONS 28) */
               fn_customer_blocked(b.customer_id, b.room_id) AS office_blocked
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
        " . $where . "
        ORDER BY COALESCE(vd.start_date, hd.check_in_date) DESC, b.id DESC
        " . ($limit ? 'LIMIT ' . $limit : ''), $args));
    }

    /* The papers filed with a refund request. */
    public static function refundDocuments(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT id, original_filename, file_path
            FROM booking_documents
            WHERE booking_id = ? AND document_type = 'refund_support'
            ORDER BY id
        ", [$bookingId]));
    }

    /* The guests of a stay, with the exact bed each one holds; the primary guest first. */
    public static function occupants(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT o.full_name, o.gender, o.is_primary_guest, hb.bed_label, hb.bed_code
            FROM hostel_occupants o
            LEFT JOIN bed_reservations br ON br.occupant_id = o.id
            LEFT JOIN hostel_beds hb ON hb.id = br.bed_id
            WHERE o.booking_id = ?
            ORDER BY o.is_primary_guest DESC, o.id
        ", [$bookingId]));
    }

    /* Every slot of every booking, in day order: the calendar reads each booking's first. */
    public static function allSlots(): array
    {
        return self::rows(DB::select('SELECT booking_id, slot_date, start_time, end_time FROM venue_booking_slots ORDER BY slot_date, start_time'));
    }

    /* The per-day schedule of a venue booking. */
    public static function slots(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT slot_date, start_time, end_time, released_at
            FROM venue_booking_slots
            WHERE booking_id = ?
            ORDER BY slot_date, start_time
        ", [$bookingId]));
    }

    /* When the customer's ID was removed, or null. */
    public static function idRemovedOn(int $bookingId): ?string
    {
        return DB::selectOne("
            SELECT MAX(removed_at) AS removed_on
            FROM booking_documents
            WHERE booking_id = ? AND document_type = 'customer_id'
        ", [$bookingId])->removed_on;
    }

    /* The newest document of each kind on a booking. */
    public static function latestDocuments(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT d.document_type, d.id, d.file_path
            FROM booking_documents d
            JOIN (SELECT document_type, MAX(id) AS id
                    FROM booking_documents
                   WHERE booking_id = ?
                   GROUP BY document_type) latest ON latest.id = d.id
        ", [$bookingId]));
    }

    /* Every GCash upload of a booking, attempt by attempt. */
    public static function receiptAttempts(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT id, attempt_no, reference_number, receiver_number, amount_centavos,
                   sha256_hash, verdict, flags_json, reviewed_at, override_note, created_at
            FROM gcash_receipts
            WHERE booking_id = ?
            ORDER BY attempt_no, id
        ", [$bookingId]));
    }

    /* The latest GCash upload of a booking, or null. */
    public static function latestReceipt(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT id, reference_number, receiver_name, receiver_number, amount_centavos,
                   receipt_datetime, ocr_confidence, verdict, flags_json
            FROM gcash_receipts
            WHERE booking_id = ?
            ORDER BY id DESC
            LIMIT 1
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* The booking's History, oldest first, with who did each thing. */
    public static function timeline(int $bookingId): array
    {
        return self::rows(DB::select("
            SELECT t.occurred_at, t.action_code, t.old_reservation_status, t.new_reservation_status,
                   t.old_payment_status, t.new_payment_status, t.note,
                   COALESCE(s.full_name, u.email, 'System') AS actor
            FROM booking_timeline t
            LEFT JOIN users u ON u.id = t.performed_by_user_id
            LEFT JOIN staff s ON s.user_id = t.performed_by_user_id
            WHERE t.booking_id = ?
            ORDER BY t.occurred_at, t.id
        ", [$bookingId]));
    }

    /* The staff's words for each status. $table: reservation_statuses or payment_statuses. */
    public static function staffLabels(string $table): array
    {
        return self::rows(DB::select("SELECT code, staff_label FROM {$table}"));
    }
}
