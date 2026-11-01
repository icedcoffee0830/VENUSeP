<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Http\Request;
use PDO;

/* What ends a booking outside the normal flow (Aron, 2026-11-01). Each returns after a
   commit; refusals throw through reply(). Staff act on their own office only: the
   controller's scope check runs first. */
trait BookingSettlementActions
{
    /* FORCE CANCEL (staff): not paid, no receipt waiting for review, and the event has
       not started. After that the money is real (a debt or a payment), and these paths
       cannot quietly erase it. */
    private function forceCancel(Request $request, PDO $pdo, array $bk, int $bookingId, int $actor, string $note): void
    {
        $reasons = ['fake' => 'Suspected fake booking', 'asked' => 'The customer asked to cancel', 'unreachable' => 'The customer could not be reached'];
        $reason = $reasons[(string) $request->post('reason')] ?? null;
        if ($reason === null || $note === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a reason and write a note. The customer reads both.']);
        }
        if ($bk['reservation_status'] !== 'approved') {
            $this->reply(409, ['ok' => false, 'message' => $bk['reservation_status'] === 'pending'
                ? 'This request has not been approved yet: use Reject instead.' : 'This booking is already closed.']);
        }
        if ($bk['payment_status'] === 'under_review') {
            $this->reply(409, ['ok' => false, 'message' => 'A receipt is waiting for review. They may really have paid: check it and reject it first.']);
        }
        if (!in_array($bk['payment_status'], self::UNPAID, true)) {
            $this->reply(409, ['ok' => false, 'message' => 'This booking has a payment or a debt on it, so it cannot be force cancelled.']);
        }
        if (BookingRules::eventStarted($pdo, $bookingId)) {
            $this->reply(409, ['ok' => false, 'message' => 'The event has already started, so it cannot be force cancelled.']);
        }

        $words = $reason . ': ' . mb_substr($note, 0, 400);
        $pdo->beginTransaction();
        $this->setActor($pdo, $actor, 'Force cancelled by staff. ' . $words);
        /* The WHERE repeats the rule: a payment arriving a moment ago wins. */
        $done = $pdo->prepare(
            "UPDATE bookings SET reservation_status = 'cancelled', payment_status = 'locked', cancelled_at = NOW(),
                    staff_notes = :n, updated_by_user_id = :u
              WHERE id = :b AND reservation_status = 'approved'
                AND payment_status IN ('" . implode("','", self::UNPAID) . "')"
        );
        $done->execute([':n' => $words, ':u' => $actor, ':b' => $bookingId]);
        $this->clearActor($pdo);
        if ($done->rowCount() === 0) {
            $pdo->rollBack();
            $this->reply(409, ['ok' => false, 'message' => 'This booking just changed, so nothing was cancelled. Reload the page.']);
        }
        notify_booking_parties($pdo, $bookingId, 'booking_cancelled', $actor, ['note' => $words]);
        $pdo->commit();
    }

    /* WRITE-OFF: a debt that will not be paid. Staff ask, an administrator decides. */
    private function writeOffRequest(PDO $pdo, array $bk, int $bookingId, int $actor, string $note): void
    {
        if ($bk['payment_status'] !== 'overdue') {
            $this->reply(409, ['ok' => false, 'message' => 'Only an Overdue payment can be written off.']);
        }
        if ($note === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Write why this debt cannot be collected.']);
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE bookings SET write_off_reason = :r, write_off_requested_by = :u, write_off_requested_at = NOW() WHERE id = :b')
            ->execute([':r' => mb_substr($note, 0, 500), ':u' => $actor, ':b' => $bookingId]);
        $this->timeline($pdo, $bookingId, 'write_off_requested', $actor, 'Write-off requested: ' . $note);
        notify_refund_needs_admin($pdo, $bookingId, $actor, 'write_off_requested');
        $pdo->commit();
    }

    private function writeOffDecide(PDO $pdo, array $bk, int $bookingId, int $actor, string $note, bool $approve): void
    {
        if (session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can decide a write-off.']);
        }
        if ($bk['payment_status'] !== 'overdue' || empty($bk['write_off_reason'])) {
            $this->reply(409, ['ok' => false, 'message' => 'There is no write-off waiting on this booking.']);
        }
        $pdo->beginTransaction();
        if ($approve) {
            $this->setActor($pdo, $actor, 'Debt written off by an administrator: ' . $bk['write_off_reason']);
            $pdo->prepare("UPDATE bookings SET reservation_status = 'completed', payment_status = 'written_off', updated_by_user_id = :u WHERE id = :b")
                ->execute([':u' => $actor, ':b' => $bookingId]);
            $this->clearActor($pdo);
        } else {
            $pdo->prepare('UPDATE bookings SET write_off_reason = NULL, write_off_requested_by = NULL, write_off_requested_at = NULL WHERE id = :b')
                ->execute([':b' => $bookingId]);
            $this->timeline($pdo, $bookingId, 'write_off_declined', $actor, 'Write-off declined.' . ($note !== '' ? ' ' . $note : ''));
        }
        $pdo->commit();
    }

    /* REVERSE PAYMENT (admin, password + reason): a fake receipt, or a payment recorded on
       the wrong booking. Back to unpaid with 48 hours; the System Receipt is void. */
    private function reversePayment(Request $request, PDO $pdo, array $bk, int $bookingId, int $actor, string $note): void
    {
        $reasons = ['fake' => 'Fake receipt', 'wrong' => 'Recorded on the wrong booking', 'never' => 'The money never arrived', 'other' => 'Other'];
        $reason = $reasons[(string) $request->post('reason')] ?? null;
        if (session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can reverse a payment.']);
        }
        if (!in_array($bk['payment_status'], ['confirmed', 'paid_cash', 'refund_denied'], true)) {
            $this->reply(409, ['ok' => false, 'message' => 'Only a paid booking with no refund in progress can be reversed.']);
        }
        if ($reason === null || mb_strlen($note) < 10) {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a reason and write a note of at least 10 characters.']);
        }
        $password = (string) $request->post('password');
        if ($password === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Enter your password to confirm.']);
        }

        $words = $reason . ': ' . mb_substr($note, 0, 400);
        $pdo->beginTransaction();
        $u = $pdo->prepare(
            'SELECT id, password_hash, account_type, is_active, reauth_failed_attempts, reauth_lock_level,
                    CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
               FROM users WHERE id = :id FOR UPDATE'
        );
        $u->execute([':id' => $actor]);
        if ($no = reauth_verify($pdo, $u->fetch(), $password)) {
            isset($no['locked'])
                ? $this->reply(423, ['ok' => false, 'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                : $this->reply(401, ['ok' => false, 'message' => 'Wrong password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
        }

        $pdo->prepare("UPDATE payments SET payment_record_status = 'rejected' WHERE booking_id = :b AND payment_record_status = 'confirmed'")
            ->execute([':b' => $bookingId]);
        $pdo->prepare('UPDATE system_receipts SET voided_at = NOW(), voided_by_user_id = :u, void_reason = :r WHERE booking_id = :b AND voided_at IS NULL')
            ->execute([':u' => $actor, ':r' => $words, ':b' => $bookingId]);
        $this->setActor($pdo, $actor, 'Payment reversed by an administrator. ' . $words);
        $pdo->prepare(
            "UPDATE bookings SET reservation_status = IF(reservation_status = 'completed', 'approved', reservation_status),
                    payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    current_deadline_at = DATE_ADD(NOW(), INTERVAL 48 HOUR), updated_by_user_id = :u WHERE id = :b"
        )->execute([':u' => $actor, ':b' => $bookingId]);
        $this->clearActor($pdo);
        notify_booking_parties($pdo, $bookingId, 'payment_reversed', $actor, ['note' => $words]);
        $pdo->commit();
    }

    /* A REFUND THE SCHOOL MADE OUTSIDE THE SYSTEM (staff): recorded so the system reflects
       it. The system never offers this, so it works with the refund switch off. */
    private function recordOutsideRefund(Request $request, PDO $pdo, array $bk, int $bookingId, int $actor, string $note): void
    {
        $reasons = ['closure' => 'venue_unavailable', 'school' => 'other', 'other' => 'other'];
        $labels = ['closure' => 'Office closure', 'school' => 'School decision', 'other' => 'Other'];
        $key = (string) $request->post('reason');
        $ref = mb_substr(trim((string) $request->post('cashier_reference')), 0, 100);
        $on = (string) $request->post('refunded_on');
        if (!in_array($bk['payment_status'], ['confirmed', 'paid_cash', 'refund_denied'], true)) {
            $this->reply(409, ['ok' => false, 'message' => 'Only a paid booking with no refund in progress can have this recorded.']);
        }
        if (!isset($reasons[$key]) || $note === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a reason and write the explanation. The customer reads it.']);
        }
        if ($ref === '' || !BookingRules::isDate($on) || $on > date('Y-m-d')) {
            $this->reply(400, ['ok' => false, 'message' => "Enter the Cashier's reference and the date the money was returned."]);
        }
        $files = $this->refundFiles($request, 'proof');
        if (!$files) {
            $this->reply(400, ['ok' => false, 'message' => "Attach the proof from the Cashier's record."]);
        }
        $words = 'Refunded outside the system by the school. ' . $labels[$key] . ': ' . mb_substr($note, 0, 400) . ' Cashier reference ' . $ref . ', ' . date('M j, Y', strtotime($on)) . '.';

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO refunds (booking_id, refund_status, reason_category, reason, amount_requested, amount_approved,
                                  requested_by_user_id, reviewed_by_user_id, reviewed_at, completed_at, outside_system, cashier_reference, refunded_on)
             SELECT b.id, 'completed', :c, :r, b.total_amount, b.total_amount, :u, :u2, NOW(), NOW(), 1, :ref, :on FROM bookings b WHERE b.id = :b"
        )->execute([':c' => $reasons[$key], ':r' => mb_substr($note, 0, 2000), ':u' => $actor, ':u2' => $actor, ':ref' => $ref, ':on' => $on, ':b' => $bookingId]);
        $this->setActor($pdo, $actor, $words);
        $pdo->prepare(
            "UPDATE bookings SET reservation_status = 'cancelled', payment_status = 'refunded', cancelled_at = NOW(), updated_by_user_id = :u WHERE id = :b"
        )->execute([':u' => $actor, ':b' => $bookingId]);
        $this->clearActor($pdo);
        $pdo->prepare("UPDATE payments SET payment_record_status = 'refunded' WHERE booking_id = :b AND payment_record_status = 'confirmed'")
            ->execute([':b' => $bookingId]);
        notify_refund_needs_admin($pdo, $bookingId, $actor, 'refund_recorded');
        $pdo->commit();
        $this->storeRefundFiles($bookingId, $files, $actor);
    }

    /* HOSTEL CHECK-OUT: the bed is free again from today, however early they left. */
    private function checkOut(PDO $pdo, array $bk, int $bookingId, int $actor): void
    {
        $st = $pdo->prepare('SELECT checked_in, checked_out FROM hostel_booking_details WHERE booking_id = :b');
        $st->execute([':b' => $bookingId]);
        $stay = $st->fetch();
        if (!$stay || !$stay['checked_in']) {
            $this->reply(409, ['ok' => false, 'message' => 'Only a guest who has checked in can check out.']);
        }
        if ($stay['checked_out']) {
            $this->reply(409, ['ok' => false, 'message' => 'The guest has already checked out.']);
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE hostel_booking_details SET checked_out = 1, checked_out_at = NOW() WHERE booking_id = :b')->execute([':b' => $bookingId]);
        $pdo->prepare('UPDATE bed_reservation_nights SET released_at = NOW() WHERE booking_id = :b AND night_date >= CURDATE() AND released_at IS NULL')
            ->execute([':b' => $bookingId]);
        $this->timeline($pdo, $bookingId, 'checked_out', $actor, 'Guest checked out.');
        $pdo->commit();
    }
}
