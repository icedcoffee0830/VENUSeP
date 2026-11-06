<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind opening a stored file: an ID, a refund document or a GCash receipt.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   booking_documents: one row per stored document; removed_at set = the file is gone.
   gcash_receipts: one row per uploaded GCash receipt (notes in PaymentQueries).
   document_access_log: who on the staff side opened someone's ID.
   Read only: bookings. */
class DocumentQueries
{
    /* One stored document, waiting for staff to check it. */
    public static function add(array $d): int
    {
        DB::insert("
            INSERT INTO booking_documents
                (booking_id, document_type, file_path, original_filename, stored_filename,
                 mime_type, file_size_bytes, sha256_hash, verification_status, uploaded_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ", [$d['booking'], $d['type'], $d['path'], $d['original'], $d['stored'], $d['mime'], $d['size'], $d['hash'], $d['by']]);
        return (int) DB::getPdo()->lastInsertId();
    }

    public static function markEncrypted(int $id): void
    {
        DB::update('UPDATE booking_documents SET encrypted = 1 WHERE id = ?', [$id]);
    }

    /* IDs no longer needed, fifty at a time: the booking is finished, or a rejected request's
       chance to be fixed has passed (its first day). */
    public static function finishedIds(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT d.id, d.file_path
            FROM booking_documents d
            JOIN bookings b ON b.id = d.booking_id
            WHERE d.document_type = 'customer_id'
              AND d.removed_at IS NULL
              AND (b.reservation_status IN ('completed', 'cancelled', 'released')
                   OR (b.reservation_status = 'rejected' AND fn_booking_first_day(b.id) < CURDATE()))
            LIMIT 50
        "));
    }

    /* The file is gone; the row stays, so the page can say what happened. */
    public static function markRemoved(int $id): void
    {
        DB::update('UPDATE booking_documents SET removed_at = NOW() WHERE id = ?', [$id]);
    }

    /* A document with its booking's owner, in one read; or null. */
    public static function findDocument(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT d.file_path, d.original_filename, d.mime_type, d.document_type AS kind, d.encrypted, d.removed_at,
                   b.customer_id, b.id AS booking_id
            FROM booking_documents d
            JOIN bookings b ON b.id = d.booking_id
            WHERE d.id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }

    /* A GCash receipt in the same shape, or null. */
    public static function findGcashUpload(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT g.file_path, g.original_filename, NULL AS mime_type, 'gcash_receipt' AS kind, 0 AS encrypted, NULL AS removed_at,
                   b.customer_id, b.id AS booking_id
            FROM gcash_receipts g
            JOIN bookings b ON b.id = g.booking_id
            WHERE g.id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }

    /* Who opened someone's ID, for the admins' history. */
    public static function logAccess(int $documentId, int $userId): void
    {
        DB::insert('INSERT INTO document_access_log (document_id, user_id) VALUES (?, ?)', [$documentId, $userId]);
    }

    /* What the ID viewer shows of a document, or null. */
    public static function findIdFile(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT booking_id, file_path, document_type AS kind, uploaded_at AS at
            FROM booking_documents
            WHERE id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }

    /* What the viewer shows of a GCash receipt, or null. */
    public static function findUploadFile(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT booking_id, file_path, 'gcash_receipt' AS kind, created_at AS at, attempt_no, reference_number,
                   amount_centavos, receiver_number, verdict, flags_json, override_note
            FROM gcash_receipts
            WHERE id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }
}
