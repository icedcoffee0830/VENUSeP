<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/* =====================================================================
   DOCUMENT VIEW (was document-view.php) — the ONLY way a stored ID,
   receipt or OR is ever read.

   GET  ?doc=<booking_documents.id>      an ID / POS / OR / refund support
   GET  ?receipt=<gcash_receipts.id>     a GCash receipt screenshot
   Optional: &download=1 to save rather than display.

   The files live OUTSIDE the web root (VENUSEP_DOC_ROOT), so the bytes only
   ever leave through here, after the permission check:
     staff / admin   any booking's documents
     customer        only documents on their OWN bookings
     anyone else     404
   404, NOT 403, for a document that exists but is not yours.
   No route middleware: the identical 404 is the guard.
   ===================================================================== */
class DocumentViewController extends Controller
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'documents');

        $isStaff    = venusep_is_staff_session();
        $isCustomer = customer_logged_in();
        if (!$isStaff && !$isCustomer) {
            return $this->deny();
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return response('Temporarily unavailable.', 503)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $doc       = $request->query('doc');
        $receipt   = $request->query('receipt');
        $docId     = $doc !== null && ctype_digit((string) $doc) ? (int) $doc : 0;
        $receiptId = $receipt !== null && ctype_digit((string) $receipt) ? (int) $receipt : 0;
        if (!$docId && !$receiptId) {
            return $this->deny();
        }

        /* Fetch the row AND the booking's owner in one query. */
        if ($docId) {
            $stmt = $pdo->prepare(
                'SELECT d.file_path, d.original_filename, d.mime_type, d.document_type AS kind, b.customer_id, b.id AS booking_id
                   FROM booking_documents d JOIN bookings b ON b.id = d.booking_id
                  WHERE d.id = :i'
            );
            $stmt->execute([':i' => $docId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT g.file_path, g.original_filename, NULL AS mime_type, 'gcash_receipt' AS kind, b.customer_id, b.id AS booking_id
                   FROM gcash_receipts g JOIN bookings b ON b.id = g.booking_id
                  WHERE g.id = :i"
            );
            $stmt->execute([':i' => $receiptId]);
        }
        $row = $stmt->fetch();
        if (!$row) {
            return $this->deny();
        }

        /* THE PERMISSION CHECK — against the SESSION's customer id; staff: own venue only. */
        if ($isStaff ? !staff_may_handle_booking($row['booking_id']) : (int) $row['customer_id'] !== (int) session('customer_id')) {
            return $this->deny();
        }

        /* doc_path() realpath-checks the stored path against doc_root(). */
        $full = doc_path($row['file_path']);
        if ($full === null || !is_file($full)) {
            return $this->deny();
        }

        /* Trust the EXTENSION assigned at upload. */
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                  'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        if (!isset($types[$ext])) {
            return $this->deny();
        }

        /* A filename built from the document KIND, never the original name. */
        $niceName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $row['kind']) . '-' . ($docId ?: $receiptId) . '.' . $ext;
        $inline   = empty($request->query('download'));

        return response()->file($full, [
            'Content-Type'            => $types[$ext],
            'Content-Disposition'     => ($inline ? 'inline' : 'attachment') . '; filename="' . $niceName . '"',
            /* nosniff: a browser must never decide a file is HTML and run it here */
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'none'; sandbox",
            'Referrer-Policy'         => 'no-referrer',
            /* private, not public: a shared cache must never hold someone's ID */
            'Cache-Control'           => 'private, no-store, max-age=0',
            'Pragma'                  => 'no-cache',
        ]);
    }

    /* Refuse plainly, and identically, whatever the reason. */
    private function deny()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
