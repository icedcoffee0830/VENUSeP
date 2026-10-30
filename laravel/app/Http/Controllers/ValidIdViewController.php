<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/* =====================================================================
   DOCUMENT VIEWER (was valid-id-view.php) — the page a valid ID, a booking
   document or a GCash receipt screenshot opens in (same tab): the file on
   a dark stage, the booking it belongs to beside it, and a Back button.

   GET  ?booking=<VB-ref>               that booking's valid ID
   GET  ?doc=<booking_documents.id>     any booking document (ID, POS, OR…)
   GET  ?receipt=<gcash_receipts.id>    a GCash receipt screenshot

   WHO MAY SEE WHAT — the same rule as document-view, which still serves the
   actual bytes: staff see any booking, a customer only their own, anyone
   else a 404 (never 403). No route middleware: the 404 is the guard.
   ===================================================================== */
class ValidIdViewController extends Controller
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'bookings');

        $viIsStaff = venusep_is_staff_session();
        $viIsCustomer = !$viIsStaff && customer_logged_in();
        if (!$viIsStaff && !$viIsCustomer) {
            return $this->notFound();
        }
        $viPdo = venusep_db();
        if ($viPdo === null) {
            return $this->notFound();
        }

        /* ---- which file ---- */
        $doc         = $request->query('doc');
        $receipt     = $request->query('receipt');
        $viDocId     = $doc !== null && ctype_digit((string) $doc) ? (int) $doc : 0;
        $viReceiptId = $receipt !== null && ctype_digit((string) $receipt) ? (int) $receipt : 0;
        $viBookingId = null;
        if (!$viDocId && !$viReceiptId) {
            $viBookingId = booking_id_from_reference((string) ($request->query('booking') ?? ''));
            if ($viBookingId === null) {
                return $this->notFound();
            }
            $viDocs  = booking_document_ids($viBookingId);
            $viDocId = isset($viDocs['customer_id']) ? (int) $viDocs['customer_id'] : 0;
        }

        $viFile = null;
        if ($viDocId) {
            $s = $viPdo->prepare('SELECT booking_id, file_path, document_type AS kind, uploaded_at AS at FROM booking_documents WHERE id = :i');
            $s->execute([':i' => $viDocId]);
            $viFile = $s->fetch() ?: null;
        } elseif ($viReceiptId) {
            $s = $viPdo->prepare(
                "SELECT booking_id, file_path, 'gcash_receipt' AS kind, created_at AS at, attempt_no, reference_number,
                        amount_centavos, receiver_number, verdict, flags_json, override_note
                   FROM gcash_receipts WHERE id = :i"
            );
            $s->execute([':i' => $viReceiptId]);
            $viFile = $s->fetch() ?: null;
        }
        if ($viFile) {
            $viBookingId = (int) $viFile['booking_id'];
        } elseif ($viBookingId === null) {
            return $this->notFound();
        }

        /* ---- may this person see it? ---- */
        $own = $viPdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
        $own->execute([':b' => $viBookingId]);
        $ownerId = $own->fetchColumn();
        if ($ownerId === false || ($viIsStaff ? !staff_may_handle_booking($viBookingId) : (int) $ownerId !== (int) (session('customer_id') ?? 0))) {
            return $this->notFound();
        }
        $viRows = bookings_query(['booking_id' => $viBookingId]);
        $b = $viRows ? $viRows[0] : null;
        if (!$b) {
            return $this->notFound();
        }

        /* ---- what to say about it ---- */
        $viKinds = ['customer_id' => 'Valid ID', 'pos' => 'POS (order of payment)', 'official_receipt' => 'Official Receipt',
                    'transaction_receipt' => 'Transaction receipt', 'refund_support' => 'Refund document', 'other' => 'Document',
                    'gcash_receipt' => 'GCash receipt'];
        $viTitle = $viFile ? ($viKinds[$viFile['kind']] ?? 'Document') : 'Valid ID';
        $viFacts = [];      /* [label, value, tone] about the document itself */
        if ($viFile && $viFile['kind'] === 'customer_id') {
            $viFacts[] = ['Type', $b['isWalkIn'] ? 'Presented at the counter' : ($b['isUsep'] ? 'USeP ID' : 'Government-issued ID'), ''];
            /* the ID is judged when the booking is approved or rejected; a request
               that expired before anyone looked was never judged at all */
            [$idState, $idTone] = $b['reservationCode'] === 'pending' ? ['Waiting for staff review', 'warn']
                : ($b['reservationCode'] === 'rejected' ? ['Not accepted', 'bad']
                : (!empty($b['neverApproved']) ? ['Not reviewed — the request expired', ''] : ['Accepted', 'ok']));
            $viFacts[] = ['Status', $idState, $idTone];
            if ($b['isUsep']) {
                $viFacts[] = ['USeP discount', $b['usepVerified'] ? 'Confirmed from this ID' : ($b['reservationCode'] === 'pending' ? 'Claimed — staff check it' : 'Not granted'), $b['usepVerified'] ? 'ok' : ''];
            }
        } elseif ($viFile && $viFile['kind'] === 'gcash_receipt') {
            $flags = json_decode((string) $viFile['flags_json'], true) ?: [];
            $byStaff = in_array('rejected_by_staff', $flags, true);
            $verdicts = ['accepted' => ['Passed the automatic check', 'ok'], 'manual_review' => ['Sent to staff for review', 'warn'],
                         'rejected' => ['Refused by the automatic check', 'bad']];
            [$vText, $vTone] = $byStaff ? ['Rejected by staff', 'bad'] : ($verdicts[$viFile['verdict']] ?? [(string) $viFile['verdict'], '']);
            $viFacts[] = ['Attempt', (string) $viFile['attempt_no'], ''];
            $viFacts[] = ['Check', $vText, $vTone];
            if ($byStaff && $viFile['override_note']) {
                $viFacts[] = ['Staff note', (string) $viFile['override_note'], ''];
            }
            $viFacts[] = ['Reference read', $viFile['reference_number'] ?: '—', ''];
            $viFacts[] = ['Amount read', $viFile['amount_centavos'] !== null ? '₱' . number_format(((int) $viFile['amount_centavos']) / 100, 2) : '—', ''];
            $viFacts[] = ['Amount due', $b['amount'], ''];
        }
        if ($viFile) {
            $viFacts[] = ['Uploaded', date('M j, Y · g:i A', strtotime($viFile['at'])), ''];
        }

        $viSrc   = $viFile ? route('root.document-view') . '?' . ($viReceiptId ? 'receipt=' . $viReceiptId : 'doc=' . $viDocId) : '';
        $viIsPdf = $viFile && strtolower(pathinfo((string) $viFile['file_path'], PATHINFO_EXTENSION)) === 'pdf';
        /* Back: where this was opened from (same tab); if opened directly, the booking's own page. */
        $viHome  = $viIsStaff ? route('admin.booking-request', ['id' => $b['bookingId']])
                              : route('customer.booking-history', ['view' => $b['bookingId']]);

        return response()->view('valid-id-view', compact('viTitle', 'viFacts', 'viFile', 'viSrc', 'viIsPdf', 'viHome', 'viIsStaff', 'b'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function notFound()
    {
        return response('Not found.', 404)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
