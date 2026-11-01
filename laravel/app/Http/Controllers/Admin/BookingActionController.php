<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RepliesJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDO;
use PDOException;

/* =====================================================================
   STAFF ACTIONS — the ONLY way staff change a booking (was admin/booking-action.php).

   POST  _token, booking=<VB-reference>, action=<name>, plus per-action fields
   Replies with JSON.

   One endpoint rather than eight, because every action needs the same four
   checks first — logged in as staff, CSRF, the booking exists, and the
   action is legal from the booking's CURRENT state.

   STATE TRANSITIONS GO THROUGH THE PROCEDURES. sp_approve_booking() knows
   that a pre-pay hostel booking approves into 'await_pos' rather than
   'await_gcash', and recomputes the deadline. This endpoint decides WHO may
   do WHAT, and the database decides what the booking then becomes.

   THE DISCOUNT IS APPLIED HERE, not at booking time (DB-DECISIONS #3), and
   SNAPSHOTTED onto the booking (#2).

   CSRF: Laravel's own check (the page sends X-CSRF-TOKEN / _token); an
   expired token on this script request comes back as the old 400 JSON
   (bootstrap/app.php).
   ===================================================================== */
class BookingActionController extends Controller
{
    use RepliesJson;

    private const POSTPAY_NO_RELEASE = 'A pay-after-the-event booking is never released: the event already happened, so what is owed stays on record. Extend the deadline or follow up with the customer.';

    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'bookings', 'receipt-emails');
        /* pricing.php ends with a <script> block for the booking pages; this
           endpoint answers in JSON, so its output is swallowed. */
        ob_start();
        legacy('pricing');
        ob_end_clean();

        if (!$request->isMethod('post')) {
            $this->reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        $sessionType = session('account_type');
        if (!session()->has('user_id') || !in_array($sessionType, ['admin', 'staff'], true)) {
            $this->reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }

        $actor  = (int) session('user_id');
        $action = (string) ($request->post('action') ?? '');
        $ref    = (string) ($request->post('booking') ?? '');
        $note   = trim((string) ($request->post('note') ?? ''));

        /* STAFF ACT ONLY ON THEIR OWN VENUE'S BOOKINGS (decided 2026-10-27).
           Admins handle every venue. */
        $scopeId = booking_id_from_reference($ref);
        if ($scopeId !== null && !staff_may_handle_booking($scopeId)) {
            $this->reply(403, ['ok' => false, 'message' => 'This booking belongs to another venue. Only that venue\'s staff or an administrator can act on it.']);
        }

        $bookingId = booking_id_from_reference($ref);
        if ($bookingId === null) {
            $this->reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
        }

        /* Apply the deadline rules first, so staff act on what the booking IS now. */
        venusep_expire_due_bookings();

        $cur = $pdo->prepare(
            'SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
                    b.is_usep_affiliated, b.room_price, b.refunds_allowed, b.pay_to_use, b.approved_at,
                    (SELECT c.user_id FROM customers c WHERE c.id = b.customer_id) AS customer_user_id,
                    COALESCE(b.quoted_discount_percent, v.discount_percent) AS quoted_discount
               FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN venues v ON v.id = r.venue_id WHERE b.id = :b'
        );
        $cur->execute([':b' => $bookingId]);
        $bk = $cur->fetch();
        if (!$bk) {
            $this->reply(404, ['ok' => false, 'message' => 'That booking no longer exists.']);
        }

        $queued = null;   /* an email this action queued; sent only after the action is committed */
        try {
            $queued = $this->apply($request, $pdo, $action, $bk, $bookingId, $actor, $note);
        } catch (HttpResponseException $r) {
            /* A refusal mid-action. The old endpoint exit()ed here, which closed
               its connection: no open transaction, no actor variables survived. */
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->clearActor($pdo);
            throw $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->clearActor($pdo);
            /* 45000 is a procedure refusing on a real rule — shown verbatim. */
            if ($e instanceof PDOException && $e->getCode() === '45000') {
                $this->reply(409, ['ok' => false, 'message' => $e->getMessage()]);
            }
            error_log('VENUSeP booking-action ' . $action . ': ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }

        /* Committed. Now, and only now, the email goes out. */
        if ($queued !== null) {
            mail_send($pdo, $queued);
        }

        /* Hand back the booking's new state so the page can redraw from the truth. */
        $after = booking_by_reference($ref);
        $this->reply(200, [
            'ok'      => true,
            'message' => 'Saved.',
            'booking' => $after ? [
                'res'        => $after['reservationCode'],
                'pay'        => $after['paymentCode'],
                'resLabel'   => $after['bookingStatusStaff'],
                'payLabel'   => $after['paymentStatusStaff'],
                'total'      => $after['amount'],
                'discount'   => $after['discountPercent'],
                'payBy'      => $after['payment']['payByLabel'],
                'graceEnds'  => $after['payment']['graceEndsLabel'] ?? null,
                'method'     => $after['method'],          /* Cash received turns a stay into a cash booking */
                'attempts'        => $after['receiptAttempts'],
                'attemptsAllowed' => $after['receiptAttemptsAllowed'],
                /* the timeline as a reload would show it: real time, real actor */
                'tl'              => booking_history_events($after),
            ] : null,
        ]);
    }

    /* The timeline is written by trg_bookings_after_update on every status
       change; these two session variables tell it WHO acted and WHY. Set
       before each UPDATE, cleared after. */
    private function setActor(PDO $pdo, int $actor, string $note): void
    {
        $pdo->prepare('SET @venusep_actor_user_id = :a')->execute([':a' => $actor]);
        $pdo->prepare('SET @venusep_action_note = :n')->execute([':n' => $note !== '' ? $note : null]);
    }

    private function clearActor(PDO $pdo): void
    {
        $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    }

    /* Confirm the payment and issue its System Receipt (inside the caller's
       transaction). Returns the outbox id of a walk-in's receipt email, or null.
       A database error undoes the confirmation; a problem building the EMAIL
       must not — the payment is real. */
    private function confirmPayment(PDO $pdo, int $bookingId, int $actor, string $note, bool $asCash): ?int
    {
        $receiptId = payment_confirm($pdo, $bookingId, $actor, $note, $asCash);
        if ($receiptId === null) {
            return null;
        }
        try {
            return queue_walkin_receipt($pdo, $bookingId, $receiptId, $actor);
        } catch (\Throwable $mailErr) {
            if ($mailErr instanceof PDOException) {
                throw $mailErr;
            }
            error_log('VENUSeP walk-in receipt email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
            return null;
        }
    }

    /* The booking's latest GCash upload — the one a rejection, an undo or an
       override is about — with its flags decoded, or null. */
    private function lastUpload(PDO $pdo, int $bookingId): ?array
    {
        $s = $pdo->prepare('SELECT id, payment_id, verdict, flags_json FROM gcash_receipts WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
        $s->execute([':b' => $bookingId]);
        $up = $s->fetch();
        if (!$up) {
            return null;
        }
        $flags = json_decode((string) $up['flags_json'], true);
        $up['flags'] = is_array($flags) ? $flags : [];
        $up['payment_id'] = $up['payment_id'] !== null ? (int) $up['payment_id'] : null;
        $up['rejected'] = $up['verdict'] === 'rejected' || in_array('rejected_by_staff', $up['flags'], true);
        return $up;
    }

    /* An action that changes no status (paperwork, arrival, the attempt
       allowance): the trigger never sees it, so its timeline row is written here. */
    private function timeline(PDO $pdo, int $bookingId, string $code, int $actor, string $note, ?array $meta = null): void
    {
        $pdo->prepare(
            'INSERT INTO booking_timeline (booking_id, action_code, performed_by_user_id, note, metadata_json)
             VALUES (:b, :c, :u, :n, :m)'
        )->execute([':b' => $bookingId, ':c' => $code, ':u' => $actor, ':n' => $note,
                    ':m' => $meta !== null ? json_encode($meta) : null]);
    }

    /* Paperwork never lands on a booking that has ended (Aron 2026-11-01). A completed
       one still can: a post-pay stay is paid, and its paperwork arrives, after it. */
    private function refuseOnClosedBooking(array $bk, string $what): void
    {
        if (in_array($bk['reservation_status'], ['rejected', 'cancelled', 'released'], true)) {
            $this->reply(409, ['ok' => false, 'message' => $what . ' cannot be recorded on a closed booking.']);
        }
    }

    /* The refund's uploads, every one checked BEFORE anything is written. */
    private function refundFiles(Request $request, string $field): array
    {
        legacy('documents');
        $files = $request->file($field, []);
        $files = array_filter(is_array($files) ? $files : [$files]);
        foreach ($files as $file) {
            $err = 'The upload did not arrive.';
            if (!$file instanceof UploadedFile || !$file->isValid() || doc_validate($file->getPathname(), $file->getClientOriginalName(), $err) === null) {
                $this->reply(400, ['ok' => false, 'message' => $err . ' Nothing was recorded.']);
            }
        }
        return $files;
    }

    /* After the commit, like the customer's own refund documents. */
    private function storeRefundFiles(int $bookingId, array $files, int $actor): void
    {
        foreach ($files as $file) {
            $err = '';
            if (doc_store($bookingId, 'refund_support', $file->getPathname(), $file->getClientOriginalName(), $actor, $err) === null) {
                error_log('VENUSeP refund document, booking ' . $bookingId . ': ' . $err);
            }
        }
    }

    /* The action itself. Returns the outbox id of an email it queued, or null. */
    private function apply(Request $request, PDO $pdo, string $action, array $bk, int $bookingId, int $actor, string $note): ?int
    {
        $queued = null;
        switch ($action) {

        /* ---- APPROVE the ID + the reservation. Optionally grant the USeP rate. ---- */
        case 'approve':
            if ($bk['reservation_status'] === 'released' && $bk['approved_at'] === null) {
                $this->reply(409, ['ok' => false, 'message' => 'This request has expired: it was not approved before its date, so the slot was released.']);
            }
            if ($bk['reservation_status'] !== 'pending') {
                $this->reply(409, ['ok' => false, 'message' => 'Only a pending booking can be approved.']);
            }
            $withDiscount = !empty($request->post('discount'));
            if ($withDiscount && !$bk['is_usep_affiliated']) {
                $this->reply(400, ['ok' => false, 'message' => 'This booking did not claim USeP affiliation.']);
            }
            /* Snapshot the rate ONTO the booking. */
            $pct    = $withDiscount ? (int) $bk['quoted_discount'] : 0;   /* the rate shown when they booked (2026-11-01) */
            $amount = round((float) $bk['room_price'] * $pct / 100, 2);
            $this->setActor($pdo, $actor, $note !== '' ? $note : ($withDiscount ? 'ID verified; USeP rate applied.' : 'ID verified.'));
            $pdo->prepare(
                'UPDATE bookings
                    SET affiliation_verified = :v, discount_percent = :p, discount_amount = :d,
                        total_amount = room_price - :d2, updated_by_user_id = :u
                  WHERE id = :b'
            )->execute([':v' => $withDiscount ? 1 : 0, ':p' => $pct, ':d' => $amount,
                        ':d2' => $amount, ':u' => $actor, ':b' => $bookingId]);
            /* The procedure decides what 'approved' MEANS for this booking. */
            $pdo->prepare('CALL sp_approve_booking(:b, :a)')->execute([':b' => $bookingId, ':a' => $actor]);
            $this->clearActor($pdo);
            break;

        /* ---- REJECT the request. The booking keeps its history; the slot frees. ---- */
        case 'reject':
            if (in_array($bk['reservation_status'], ['rejected', 'cancelled', 'completed', 'released'], true)) {
                $this->reply(409, ['ok' => false, 'message' => 'That booking is already closed.']);
            }
            /* Rejecting answers a REQUEST. An approved booking may already be paid,
               and this would lock its payment with no refund (found 2026-10-30). */
            if ($bk['reservation_status'] !== 'pending') {
                $this->reply(409, ['ok' => false, 'message' => 'Only a pending request can be rejected.']);
            }
            $this->setActor($pdo, $actor, $note !== '' ? $note : 'Request rejected.');
            /* trg_bookings_after_update releases the held slots and bed-nights. */
            $pdo->prepare(
                "UPDATE bookings SET reservation_status = 'rejected', payment_status = 'locked',
                        staff_notes = :n, updated_by_user_id = :u WHERE id = :b"
            )->execute([':n' => $note, ':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            break;

        /* ---- HOSTEL: the POS has come back from CEDU, so payment unlocks. ---- */
        case 'record_pos':
            $pos = trim((string) ($request->post('pos') ?? ''));
            if ($bk['booking_type'] !== 'hostel') {
                $this->reply(400, ['ok' => false, 'message' => 'Only a hostel booking has a POS.']);
            }
            if ($pos === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Enter the POS number.']);
            }
            $this->refuseOnClosedBooking($bk, 'A POS');
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE hostel_booking_details SET pos_number = :p, pos_recorded_at = NOW() WHERE booking_id = :b'
            )->execute([':p' => mb_substr($pos, 0, 100), ':b' => $bookingId]);
            /* Payment only opens now if this booking pre-pays — the status change
               writes the timeline itself; otherwise the POS is paperwork, logged here. */
            if ($bk['refunds_allowed'] && !$bk['pay_to_use'] && $bk['payment_status'] === 'await_pos') {
                $this->setActor($pdo, $actor, 'POS ' . $pos . ' recorded; payment unlocked.');
                $pdo->prepare(
                    "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                            current_deadline_at = fn_payment_deadline(:b), updated_by_user_id = :u
                      WHERE id = :b2"
                )->execute([':b' => $bookingId, ':u' => $actor, ':b2' => $bookingId]);
                $this->clearActor($pdo);
            } else {
                $this->timeline($pdo, $bookingId, 'pos_recorded', $actor, 'POS ' . $pos . ' recorded (CEDU).');
            }
            $pdo->commit();
            break;

        /* ---- HOSTEL: the University Cashier issued the Official Receipt.
                The OR is a DOCUMENT, never a payment status. ---- */
        case 'record_or':
            $or = trim((string) ($request->post('or') ?? ''));
            if ($bk['booking_type'] !== 'hostel') {
                $this->reply(400, ['ok' => false, 'message' => 'Only a hostel booking has an Official Receipt.']);
            }
            if ($or === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Enter the Official Receipt number.']);
            }
            $this->refuseOnClosedBooking($bk, 'An Official Receipt');
            /* The cashier issues it for money received (Aron 2026-11-01). */
            if (!in_array($bk['payment_status'], ['confirmed', 'paid_cash', 'refund_requested', 'refund_correction', 'refund_await_or', 'refund_processing', 'refund_denied'], true)) {
                $this->reply(409, ['ok' => false, 'message' => 'An Official Receipt is recorded only after the payment.']);
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE hostel_booking_details SET official_receipt_no = :o, official_receipt_recorded_at = NOW()
                  WHERE booking_id = :b'
            )->execute([':o' => mb_substr($or, 0, 100), ':b' => $bookingId]);
            $this->timeline($pdo, $bookingId, 'or_recorded', $actor, 'Official Receipt ' . $or . ' recorded (University Cashier).');
            $pdo->commit();
            break;

        /* ---- HOSTEL: the guest arrived. PAY TO USE (2026-10-27): no bed until paid. ---- */
        case 'check_in':
            if ($bk['booking_type'] !== 'hostel') {
                $this->reply(400, ['ok' => false, 'message' => 'Only a hostel booking has a check-in.']);
            }
            /* A rejected, cancelled or released stay has no bed to check into. */
            if ($bk['reservation_status'] !== 'approved') {
                $this->reply(409, ['ok' => false, 'message' => 'Only an approved stay can be checked in.']);
            }
            if ($bk['pay_to_use'] && !in_array($bk['payment_status'], ['confirmed', 'paid_cash'], true)) {
                $this->reply(409, ['ok' => false, 'message' => $bk['payment_status'] === 'under_review'
                    ? 'Not paid yet: the GCash receipt is still under review. Confirm it first, then check the guest in.'
                    : ($bk['payment_status'] === 'await_cash'
                        ? 'Not paid yet. The guest chose to pay cash at the front desk — take it first (Cash received).'
                        : 'Not paid yet. The guest pays before using the bed — by GCash in Pay now, or by choosing cash at the front desk there.')]);
            }
            /* Not before the stay begins (Aron, 2026-10-30): the bed is booked from the check-in date. */
            $stay = $pdo->prepare('SELECT check_in_date, check_in_date > CURDATE() AS early, checked_in FROM hostel_booking_details WHERE booking_id = :b');
            $stay->execute([':b' => $bookingId]);
            $stay = $stay->fetch();
            if ($stay && $stay['checked_in']) {
                $this->reply(409, ['ok' => false, 'message' => 'The guest is already checked in.']);
            }
            if ($stay && $stay['early']) {
                $this->reply(409, ['ok' => false,
                    'message' => 'Too early: check-in opens on ' . date('M j, Y', strtotime($stay['check_in_date'])) . ', the first night of the stay.']);
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                'UPDATE hostel_booking_details SET checked_in = 1, checked_in_at = NOW() WHERE booking_id = :b'
            )->execute([':b' => $bookingId]);
            $this->timeline($pdo, $bookingId, 'checked_in', $actor, 'Guest checked in.');
            $pdo->commit();
            break;

        /* ---- HOSTEL, PAY TO USE: the guest paid CASH at the front desk. ---- */
        case 'cash_received':
            if (!$bk['pay_to_use']) {
                $this->reply(400, ['ok' => false, 'message' => 'Only a hostel stay is paid in cash at the front desk this way.']);
            }
            if ($bk['payment_status'] === 'under_review') {
                $this->reply(409, ['ok' => false, 'message' => 'A GCash receipt is waiting for review. Confirm it or reject it first, then take the cash if it was rejected.']);
            }
            /* Only once the GUEST chose to pay at the front desk (2026-10-28). */
            if ($bk['payment_status'] === 'await_gcash') {
                $this->reply(409, ['ok' => false, 'booking' => ['pay' => 'await_gcash'],
                    'message' => 'The guest has not chosen to pay at the front desk (or cancelled it). Ask them to choose "Cash — hostel front desk" in Pay now first.']);
            }
            if ($bk['payment_status'] !== 'await_cash') {
                $this->reply(409, ['ok' => false, 'message' => 'This stay is not waiting on a payment.']);
            }
            /* fall through: the rest is confirming a payment, as cash */

        /* ---- Money landed and staff verified it. ---- */
        case 'confirm_payment':
            if (!in_array($bk['payment_status'], ['await_gcash', 'await_cash', 'under_review', 'overdue'], true)) {
                $this->reply(409, ['ok' => false, 'message' => 'This booking is not waiting on a payment.']);
            }
            $asCash = $action === 'cash_received';
            /* Waiting on GCash with no receipt in: there is nothing to confirm (#18). */
            if ($action === 'confirm_payment' && $bk['payment_status'] === 'await_gcash') {
                $this->reply(409, ['ok' => false, 'booking' => ['pay' => 'await_gcash'],
                    'message' => 'Nothing to confirm: the customer is paying by GCash and has not sent a receipt yet (they may have cancelled paying cash).']);
            }
            /* ONE transaction: the payment, its ledger row and its System Receipt
               land together or not at all. */
            $pdo->beginTransaction();
            $queued = $this->confirmPayment($pdo, $bookingId, $actor,
                $note !== '' ? $note : ($asCash ? 'Cash received at the front desk.' : 'Payment confirmed.'), $asCash);
            $pdo->commit();
            break;

        /* ================= §7 — decided 2026-10-30 (Aron: "build real saving") =================
           These four only changed the screen. Each is now a real write with a
           timeline entry (the status-change trigger writes it, with the actor). */

        /* ---- A receipt was rejected (by staff or by the checker) but staff then
                FOUND the payment in the venue's GCash Transaction History. ---- */
        case 'override_confirm': {
            $verifiedRef = preg_replace('/\D/', '', (string) ($request->post('ref') ?? ''));
            if ($verifiedRef === '' || $note === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Both the verified reference number and a note are required.']);
            }
            if (strlen($verifiedRef) < 10 || strlen($verifiedRef) > 16) {
                $this->reply(400, ['ok' => false, 'message' => 'A GCash reference number has 10 to 16 digits.']);
            }
            $up = $this->lastUpload($pdo, $bookingId);
            if ($bk['payment_status'] !== 'await_gcash' || !$up || !$up['rejected']) {
                $this->reply(409, ['ok' => false, 'message' => 'There is no rejected receipt waiting on this booking, so there is nothing to override.']);
            }
            /* A duplicate is the checker's strongest signal: only an admin may overrule it. */
            if (array_intersect(['duplicate_ref', 'duplicate_file'], $up['flags']) && session('account_type') !== 'admin') {
                $this->reply(403, ['ok' => false, 'message' => 'This receipt was rejected as a duplicate. Only an administrator can override that.']);
            }
            /* The same rule again for the reference typed here: only an admin may reuse another booking's. */
            $usedRef = $pdo->prepare('SELECT 1 FROM gcash_receipts WHERE active_reference = :r AND booking_id <> :b LIMIT 1');
            $usedRef->execute([':r' => $verifiedRef, ':b' => $bookingId]);
            if ($usedRef->fetchColumn() && session('account_type') !== 'admin') {
                $this->reply(403, ['ok' => false, 'message' => 'That reference is already on another booking. Only an administrator can confirm it.']);
            }
            $why = 'Override — verified ref ' . $verifiedRef . ' in GCash. ' . $note;
            $pdo->beginTransaction();
            /* The checker's verdict stays on record; the receipt gains who overruled it and why. */
            $pdo->prepare(
                "UPDATE gcash_receipts
                    SET reviewed_by_user_id = :u, reviewed_at = NOW(), override_note = :n,
                        flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'overridden_by_staff')
                  WHERE id = :r"
            )->execute([':u' => $actor, ':n' => $why, ':r' => $up['id']]);
            /* Settle the SAME payment row the upload created, rather than a second one. */
            if ($up['payment_id'] !== null) {
                $pdo->prepare("UPDATE payments SET payment_record_status = 'under_review' WHERE id = :p AND payment_record_status = 'rejected'")
                    ->execute([':p' => $up['payment_id']]);
            }
            $queued = $this->confirmPayment($pdo, $bookingId, $actor, $why, false);
            $pdo->commit();
            break;
        }

        /* ---- Take back a STAFF rejection: the receipt is under review again.
                Only while the customer has not moved on (no newer upload, no cash choice). ---- */
        case 'undo_rejection': {
            $up = $this->lastUpload($pdo, $bookingId);
            if ($bk['payment_status'] !== 'await_gcash' || !$up || !in_array('rejected_by_staff', $up['flags'], true)) {
                $this->reply(409, ['ok' => false, 'message' => 'There is no staff rejection to undo: the customer may already have sent another receipt or chosen to pay in cash.']);
            }
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE gcash_receipts
                    SET reviewed_by_user_id = NULL, reviewed_at = NULL, override_note = NULL,
                        flags_json = JSON_REMOVE(flags_json, JSON_UNQUOTE(JSON_SEARCH(flags_json, 'one', 'rejected_by_staff')))
                  WHERE id = :r"
            )->execute([':r' => $up['id']]);
            if ($up['payment_id'] !== null) {
                $pdo->prepare("UPDATE payments SET payment_record_status = 'under_review' WHERE id = :p AND payment_record_status = 'rejected'")
                    ->execute([':p' => $up['payment_id']]);
            }
            $this->setActor($pdo, $actor, 'Rejection withdrawn; the receipt is under review again.' . ($note !== '' ? ' ' . $note : ''));
            $pdo->prepare("UPDATE bookings SET payment_status = 'under_review', updated_by_user_id = :u WHERE id = :b")
                ->execute([':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            $pdo->commit();
            break;
        }

        /* ---- OVERDUE: give the customer 48 more hours from now. A post-pay
                booking is usually already `completed` when it goes overdue (its
                event is over), and that one can be extended too (Aron, 2026-10-30). ---- */
        case 'extend_deadline': {
            if (!in_array($bk['reservation_status'], ['approved', 'completed'], true) || $bk['payment_status'] !== 'overdue') {
                $this->reply(409, ['ok' => false, 'message' => 'Only an overdue payment can be extended.']);
            }
            $until = $pdo->query('SELECT DATE_ADD(NOW(), INTERVAL 48 HOUR)')->fetchColumn();
            $this->setActor($pdo, $actor, 'Pay-by deadline extended 48 hours, to ' . date('M j, Y g:i A', strtotime($until)) . '.'
                . ($note !== '' ? ' ' . $note : ''));
            $pdo->prepare(
                "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                        current_deadline_at = :d, updated_by_user_id = :u WHERE id = :b"
            )->execute([':d' => $until, ':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            break;
        }

        /* ---- OVERDUE: stop waiting — the reservation is released, as the
                system does itself when a pre-pay hold lapses (released / expired).
                Never a POST-PAY booking (Aron, 2026-10-30): its event already
                happened, so releasing it would write off what is owed. ---- */
        case 'release_slot':
            if ($bk['reservation_status'] !== 'approved' || $bk['payment_status'] !== 'overdue') {
                $this->reply(409, ['ok' => false, 'message' => 'Only an approved booking with an overdue payment can be released.']);
            }
            if (!$bk['refunds_allowed'] && !$bk['pay_to_use']) {
                $this->reply(409, ['ok' => false, 'message' => self::POSTPAY_NO_RELEASE]);
            }
            $this->setActor($pdo, $actor, 'Payment overdue; the slot was released by staff.' . ($note !== '' ? ' ' . $note : ''));
            /* trg_bookings_after_update frees the held slots / bed-nights. */
            $pdo->prepare(
                "UPDATE bookings SET reservation_status = 'released', payment_status = 'expired', updated_by_user_id = :u WHERE id = :b"
            )->execute([':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            break;

        /* ---- The receipt was wrong. Only the payment goes back. ---- */
        case 'reject_payment':
            /* Only a receipt waiting for review can be rejected: from any other
               state this reopened payment on a booking that was already paid. */
            if ($bk['payment_status'] !== 'under_review') {
                $this->reply(409, ['ok' => false, 'message' => 'There is no receipt waiting for review on this booking.']);
            }
            /* A reason from the list, plus an optional note — required for "Other". */
            $reason = trim((string) ($request->post('reason') ?? ''));
            if (!in_array($reason, receipt_reject_reasons(), true)) {
                $this->reply(400, ['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
            }
            if ($reason === 'Other' && $note === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Write the reason in the note — the customer reads it.']);
            }
            $note = $reason === 'Other' ? $note : $reason . ($note !== '' ? ' — ' . $note : '');
            $pdo->beginTransaction();
            /* The receipt keeps the CHECKER's verdict; it gains who decided,
               when and why (DB-DECISIONS #6). */
            if ($bk['payment_status'] === 'under_review') {
                $pdo->prepare(
                    "UPDATE gcash_receipts
                        SET reviewed_by_user_id = :u, reviewed_at = NOW(), override_note = :n,
                            flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'rejected_by_staff')
                      WHERE booking_id = :b AND reviewed_at IS NULL
                      ORDER BY id DESC LIMIT 1"
                )->execute([':u' => $actor, ':n' => $note, ':b' => $bookingId]);
            }
            $this->setActor($pdo, $actor, 'Payment rejected: ' . $note);
            $pdo->prepare(
                "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                        staff_notes = :n, updated_by_user_id = :u WHERE id = :b"
            )->execute([':n' => $note, ':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            $pdo->prepare("UPDATE payments SET payment_record_status = 'rejected' WHERE booking_id = :b AND payment_record_status IN ('submitted','under_review')")
                ->execute([':b' => $bookingId]);
            $pdo->commit();
            break;

        /* ---- The 5-attempt rule (DB-DECISIONS #6): the allowance grows, with
                a written reason in the booking's timeline. ---- */
        case 'allow_receipt_attempts': {
            if ($note === '') {
                $this->reply(400, ['ok' => false, 'message' => 'A note is required — say why this booking gets more attempts.']);
            }
            $att = $pdo->prepare(
                'SELECT b.receipt_attempts_allowed, (SELECT COUNT(*) FROM gcash_receipts g WHERE g.booking_id = b.id) AS used
                   FROM bookings b WHERE b.id = :b'
            );
            $att->execute([':b' => $bookingId]);
            $a = $att->fetch();
            if ((int) $a['used'] < (int) $a['receipt_attempts_allowed']) {
                $this->reply(409, ['ok' => false, 'message' => 'This booking still has receipt attempts left.']);
            }
            if (!in_array($bk['payment_status'], ['await_gcash', 'await_cash', 'overdue'], true)) {
                $this->reply(409, ['ok' => false, 'message' => 'Payment is not open on this booking. Reject the receipt under review first, or confirm it.']);
            }
            $newAllowed = (int) $a['used'] + 5;
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE bookings SET receipt_attempts_allowed = :n, updated_by_user_id = :u WHERE id = :b')
                ->execute([':n' => $newAllowed, ':u' => $actor, ':b' => $bookingId]);
            $this->timeline($pdo, $bookingId, 'receipt_attempts_allowed', $actor,
                '5 more GCash receipt attempts (now ' . $newAllowed . ' in total): ' . $note,
                ['allowed_before' => (int) $a['receipt_attempts_allowed'], 'allowed_after' => $newAllowed]);
            $pdo->commit();
            break;
        }

        /* ================= THE REFUND CHAIN (staff decisions) =================
           requested -> (returned_for_correction) -> approved -> completed, or
           -> rejected, or the customer withdraws it. Only 'completed' closes
           the booking and frees its date.
           ===================================================================== */
        /* WALK-IN REFUND (Aron 2026-11-01): a walk-in has no account to file from, so staff
           file it, on the same rule as a customer. The receipt (printed or emailed) must be
           this booking's. Lost: staff attach the proof they found, and the request waits in
           'under_review' until an administrator approves it. */
        case 'refund_file': {
            legacy('documents', 'notifications', 'system-receipt');
            if ($bk['customer_user_id'] !== null) {
                $this->reply(409, ['ok' => false, 'message' => 'The customer files this refund from their own account.']);
            }
            $row = bookings_query(['booking_id' => $bookingId])[0] ?? null;
            if (!$row || !$row['refundable']) {
                $this->reply(409, ['ok' => false, 'message' => !$bk['refunds_allowed']
                    ? 'This booking was made under the non-refundable policy.'
                    : 'This booking is not eligible for a refund: it must be paid, and its event still ahead.']);
            }
            if ($note === '') {
                $this->reply(400, ['ok' => false, 'message' => 'Write why the guest is asking for a refund.']);
            }
            $lost  = !empty($request->post('receipt_lost'));
            $files = $this->refundFiles($request, 'support');
            if ($lost && !$files) {
                $this->reply(400, ['ok' => false, 'message' => 'Attach the proof you found, so an administrator can approve it.']);
            }
            if (!$lost) {
                $issued = receipt_for_booking($pdo, $bookingId);
                if (!$issued || strcasecmp(trim((string) $request->post('receipt_number')), $issued['number']) !== 0) {
                    $this->reply(400, ['ok' => false, 'message' => 'That receipt number does not match this booking. Check it, or tick "The receipt was lost".']);
                }
            }
            $category = (string) $request->post('reason_category');
            if (!in_array($category, ['event_cancelled', 'schedule_conflict', 'wrong_room', 'venue_unavailable', 'payment_error'], true)) {
                $category = 'other';
            }
            $pdo->beginTransaction();
            $paid = $pdo->prepare("SELECT id FROM payments WHERE booking_id = :b AND payment_record_status = 'confirmed' ORDER BY id DESC LIMIT 1");
            $paid->execute([':b' => $bookingId]);
            $pdo->prepare(
                'INSERT INTO refunds (booking_id, payment_id, refund_status, reason_category, reason, amount_requested, requested_by_user_id)
                 SELECT b.id, :p, :s, :c, :r, b.total_amount, :u FROM bookings b WHERE b.id = :b'
            )->execute([':p' => $paid->fetchColumn() ?: null, ':s' => $lost ? 'under_review' : 'requested', ':c' => $category,
                        ':r' => mb_substr($note, 0, 2000), ':u' => $actor, ':b' => $bookingId]);
            $this->setActor($pdo, $actor, $lost ? 'Walk-in refund filed; the receipt was lost, so an administrator must approve the proof.'
                                                : 'Walk-in refund filed by staff; receipt checked.');
            $pdo->prepare("UPDATE bookings SET payment_status = 'refund_requested', updated_by_user_id = :u WHERE id = :b")
                ->execute([':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
            if ($lost) {
                notify_refund_needs_admin($pdo, $bookingId, $actor);
            }
            $pdo->commit();
            $this->storeRefundFiles($bookingId, $files, $actor);
            break;
        }

        /* Staff looked into a missing receipt: more proof for the administrator to read. */
        case 'refund_add_proof': {
            $files = $this->refundFiles($request, 'support');
            if (!$files) {
                $this->reply(400, ['ok' => false, 'message' => 'Choose a file to attach.']);
            }
            $open = $pdo->prepare("SELECT COUNT(*) FROM refunds WHERE booking_id = :b AND refund_status IN ('requested', 'under_review', 'returned_for_correction', 'approved')");
            $open->execute([':b' => $bookingId]);
            if (!$open->fetchColumn()) {
                $this->reply(409, ['ok' => false, 'message' => 'There is no open refund request on this booking.']);
            }
            $this->storeRefundFiles($bookingId, $files, $actor);
            break;
        }

        /* An administrator accepts the proof for a missing receipt; the payout can then be recorded. */
        case 'refund_approve': {
            if (session('account_type') !== 'admin') {
                $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can approve this refund.']);
            }
            $pdo->beginTransaction();
            $done = $pdo->prepare(
                "UPDATE refunds SET refund_status = 'approved', reviewed_by_user_id = :u, reviewed_at = NOW()
                  WHERE booking_id = :b AND (refund_status = 'under_review' OR (refund_status = 'requested' AND official_receipt_pending = 1))"
            );
            $done->execute([':u' => $actor, ':b' => $bookingId]);
            if ($done->rowCount() === 0) {
                $this->reply(409, ['ok' => false, 'message' => 'This refund is not waiting for an approval.']);
            }
            $this->timeline($pdo, $bookingId, 'refund_approved', $actor, 'Proof for the missing receipt accepted by an administrator.');
            $pdo->commit();
            break;
        }

        case 'refund_return':
        case 'refund_deny':
        case 'refund_complete': {
            $rf = $pdo->prepare('SELECT * FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
            $rf->execute([':b' => $bookingId]);
            $refund = $rf->fetch();
            if (!$refund) {
                $this->reply(404, ['ok' => false, 'message' => 'There is no refund request on this booking.']);
            }
            /* The customer can withdraw while this page is open — re-read, not trusted. */
            if (!in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction', 'approved'], true)) {
                $this->reply(409, ['ok' => false,
                    'message' => 'This request is no longer open — it was ' . str_replace('_', ' ', $refund['refund_status'])
                               . ' while this page was open. Nothing has been recorded.']);
            }
            if ($note === '' && $action !== 'refund_complete') {
                $this->reply(400, ['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
            }

            if ($action === 'refund_return') {
                if ($bk['customer_user_id'] === null) {
                    $this->reply(409, ['ok' => false, 'message' => 'A walk-in has no account to correct this from. Deny it, or fix it with the guest here.']);
                }
                /* PAPERWORK, not a denial. */
                $this->setActor($pdo, $actor, 'Refund returned for correction: ' . $note);
                $pdo->prepare(
                    "UPDATE refunds SET refund_status = 'returned_for_correction', reviewed_by_user_id = :u,
                            reviewed_at = NOW(), notes = :n, correction_due_at = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                      WHERE id = :r"
                )->execute([':u' => $actor, ':n' => $note, ':r' => $refund['id']]);
                $pdo->prepare("UPDATE bookings SET payment_status = 'refund_correction', updated_by_user_id = :u WHERE id = :b")
                    ->execute([':u' => $actor, ':b' => $bookingId]);
                $this->clearActor($pdo);

            } elseif ($action === 'refund_deny') {
                /* FINAL — but this booking keeps its date and its paid status. */
                $this->setActor($pdo, $actor, 'Refund denied: ' . $note);
                $pdo->prepare(
                    "UPDATE refunds SET refund_status = 'rejected', reviewed_by_user_id = :u,
                            reviewed_at = NOW(), notes = :n WHERE id = :r"
                )->execute([':u' => $actor, ':n' => $note, ':r' => $refund['id']]);
                $pdo->prepare("UPDATE bookings SET payment_status = 'refund_denied', updated_by_user_id = :u WHERE id = :b")
                    ->execute([':u' => $actor, ':b' => $bookingId]);
                $this->clearActor($pdo);

            } else {
                /* THE PAYOUT. A missing receipt needs an administrator's approval first (Aron 2026-11-01). */
                if ($refund['refund_status'] === 'under_review' || ($refund['official_receipt_pending'] && $refund['refund_status'] !== 'approved')) {
                    $this->reply(403, ['ok' => false, 'message' => 'The receipt is missing: an administrator has to approve the proof before this is paid.']);
                }
                /* Cash or GCash: the staff's call (Aron 2026-11-01). The proof file is optional. */
                legacy('documents');
                $proof     = $this->refundFiles($request, 'proof');
                $inCash    = $request->post('payout_method') === 'cash';
                $payoutRef = $inCash ? '' : trim((string) ($request->post('payout_reference') ?? ''));
                $payoutTo  = $inCash ? null : cb_normalise_mobile((string) $request->post('payout_to'));
                $amount    = (float) $refund['amount_requested'];   // refunds are always full: never a posted amount
                $this->setActor($pdo, $actor, 'Refund paid out' . ($inCash ? ' in cash' : '') . ($payoutRef !== '' ? ' · GCash ref ' . $payoutRef : '') . ($note !== '' ? ' · ' . $note : ''));
                $pdo->prepare(
                    "UPDATE refunds SET refund_status = 'completed', amount_approved = :a,
                            refund_to_number = IF(:cash, NULL, COALESCE(:t, refund_to_number)),
                            payout_reference = :p, reviewed_by_user_id = :u, reviewed_at = COALESCE(reviewed_at, NOW()),
                            completed_at = NOW(), notes = CONCAT_WS(' | ', NULLIF(notes,''), :n)
                      WHERE id = :r"
                )->execute([':a' => $amount, ':cash' => $inCash ? 1 : 0, ':t' => $payoutTo, ':p' => $payoutRef !== '' ? mb_substr($payoutRef, 0, 100) : null,
                            ':u' => $actor, ':n' => $note !== '' ? $note : null, ':r' => $refund['id']]);
                /* ONLY NOW is the booking closed and its date freed. */
                $pdo->prepare(
                    "UPDATE bookings SET reservation_status = 'cancelled', payment_status = 'refunded',
                            cancelled_at = NOW(), updated_by_user_id = :u WHERE id = :b"
                )->execute([':u' => $actor, ':b' => $bookingId]);
                $pdo->prepare("UPDATE payments SET payment_record_status = 'refunded' WHERE booking_id = :b AND payment_record_status = 'confirmed'")
                    ->execute([':b' => $bookingId]);
                $this->clearActor($pdo);
                $this->storeRefundFiles($bookingId, $proof, $actor);
            }
            break;
        }

        default:
            $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
        }

        return $queued;
    }
}
