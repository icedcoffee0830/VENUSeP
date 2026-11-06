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
