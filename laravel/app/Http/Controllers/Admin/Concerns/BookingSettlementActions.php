<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Http\Controllers\Customer\BookingRules;
use App\Queries\AccountQueries;
use App\Queries\BookingCreateQueries;
use App\Queries\BookingQueries;
use App\Queries\PaymentQueries;
use App\Queries\RefundQueries;
use App\Support\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* What ends a booking outside the normal flow (Aron, 2026-11-01). Each returns after a
   commit; refusals throw through reply(). Staff act on their own office only: the
   controller's scope check runs first. */
trait BookingSettlementActions
{
    /* FORCE CANCEL (staff): not paid, no receipt waiting for review, and the event has
       not started. After that the money is real (a debt or a payment), and these paths
       cannot quietly erase it. */
    private function forceCancel(Request $request, array $booking, int $actor, string $note): void
    {
        $reasons = ['fake' => 'Suspected fake booking', 'asked' => 'The customer asked to cancel', 'unreachable' => 'The customer could not be reached'];
        $reason = $reasons[(string) $request->post('reason')] ?? null;
        // Check
        if ($reason === null || $note === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a reason and write a note. The customer reads both.']);
        }
        if ($booking['reservation_status'] !== Status::APPROVED) {
            $this->reply(409, ['ok' => false, 'message' => $booking['reservation_status'] === Status::PENDING
                ? 'This request has not been approved yet: use Reject instead.' : 'This booking is already closed.']);
        }
        if ($booking['payment_status'] === Status::UNDER_REVIEW) {
            $this->reply(409, ['ok' => false, 'message' => 'A receipt is waiting for review. They may really have paid: check it and reject it first.']);
        }
        if (!in_array($booking['payment_status'], Status::UNPAID, true)) {
            $this->reply(409, ['ok' => false, 'message' => 'This booking has a payment or a debt on it, so it cannot be force cancelled.']);
        }
        if (BookingCreateQueries::eventStarted((int) $booking['id'])) {
            $this->reply(409, ['ok' => false, 'message' => 'The event has already started, so it cannot be force cancelled.']);
        }

        // Save: the save repeats the rule, so a payment arriving a moment ago wins
        $words = $reason . ': ' . mb_substr($note, 0, 400);
        if (!BookingQueries::markForceCancelled((int) $booking['id'], $words, $actor, 'Force cancelled by staff. ' . $words)) {
            $this->reply(409, ['ok' => false, 'message' => 'This booking just changed, so nothing was cancelled. Reload the page.']);
        }
    }

    /* WRITE-OFF: a debt that will not be paid. Staff ask, an administrator decides. */
    private function writeOffRequest(array $booking, int $actor, string $note): void
    {
        // Check
        if ($booking['payment_status'] !== Status::OVERDUE) {
            $this->reply(409, ['ok' => false, 'message' => 'Only an Overdue payment can be written off.']);
        }
        if ($note === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Write why this debt cannot be collected.']);
        }

        // Save
        BookingQueries::saveWriteOffRequest((int) $booking['id'], mb_substr($note, 0, 500), $actor, 'Write-off requested: ' . $note);
    }

    /* An administrator agrees: the debt is written off. */
    private function approveWriteOff(array $booking, int $actor): void
    {
        // Check
        $this->refuseUnlessWriteOffWaits($booking);

        // Save
        BookingQueries::markWrittenOff((int) $booking['id'], $actor, 'Debt written off by an administrator: ' . $booking['write_off_reason']);
    }

    /* An administrator says no: the debt stays owed. */
    private function declineWriteOff(array $booking, int $actor, string $note): void
    {
        // Check
        $this->refuseUnlessWriteOffWaits($booking);

        // Save
        BookingQueries::clearWriteOffRequest((int) $booking['id'], $actor, 'Write-off declined.' . ($note !== '' ? ' ' . $note : ''));
    }

    /* Only an administrator decides a write-off, and only one staff asked for. */
    private function refuseUnlessWriteOffWaits(array $booking): void
    {
        if (session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can decide a write-off.']);
        }
        if ($booking['payment_status'] !== Status::OVERDUE || empty($booking['write_off_reason'])) {
            $this->reply(409, ['ok' => false, 'message' => 'There is no write-off waiting on this booking.']);
        }
    }

    /* REVERSE PAYMENT (admin, password + reason): a fake receipt, or a payment recorded on
       the wrong booking. Back to unpaid with 48 hours; the System Receipt is void. */
    private function reversePayment(Request $request, array $booking, int $actor, string $note): void
    {
        $reasons = ['fake' => 'Fake receipt', 'wrong' => 'Recorded on the wrong booking', 'never' => 'The money never arrived', 'other' => 'Other'];
        $reason = $reasons[(string) $request->post('reason')] ?? null;
        // Check
        if (session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can reverse a payment.']);
        }
        if (!in_array($booking['payment_status'], [Status::CONFIRMED, Status::PAID_CASH, Status::REFUND_DENIED], true)) {
            $this->reply(409, ['ok' => false, 'message' => 'Only a paid booking with no refund in progress can be reversed.']);
        }
        if ($reason === null || mb_strlen($note) < 10) {
            $this->reply(400, ['ok' => false, 'message' => 'Choose a reason and write a note of at least 10 characters.']);
        }
        $password = (string) $request->post('password');
        if ($password === '') {
            $this->reply(400, ['ok' => false, 'message' => 'Enter your password to confirm.']);
        }

        // Save, in the same transaction as the password check. reauth_verify
        // (legacy/includes/auth.php) commits a wrong try itself, so it counts: this save keeps
        // that plain PDO transaction instead of saveAs's.
        $words = $reason . ': ' . mb_substr($note, 0, 400);
        $pdo = venusep_db();
        $pdo->beginTransaction();
        if ($no = reauth_verify($pdo, AccountQueries::lockForPasswordCheck($actor), $password)) {
            isset($no['locked'])
                ? $this->reply(423, ['ok' => false, 'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                : $this->reply(401, ['ok' => false, 'message' => 'Wrong password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
        }
        PaymentQueries::markPaymentReversed((int) $booking['id'], $words, $actor, 'Payment reversed by an administrator. ' . $words);
        $pdo->commit();
    }

    /* A REFUND THE SCHOOL MADE OUTSIDE THE SYSTEM (staff): recorded so the system reflects
       it. The system never offers this, so it works with the refund switch off. */
    private function recordOutsideRefund(Request $request, array $booking, int $actor, string $note): void
    {
        $reasons = ['closure' => 'venue_unavailable', 'school' => 'other', 'other' => 'other'];
        $labels = ['closure' => 'Office closure', 'school' => 'School decision', 'other' => 'Other'];
        $key = (string) $request->post('reason');
        $ref = mb_substr(trim((string) $request->post('cashier_reference')), 0, 100);
        $on = (string) $request->post('refunded_on');
        // Check
        if (!in_array($booking['payment_status'], [Status::CONFIRMED, Status::PAID_CASH, Status::REFUND_DENIED], true)) {
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

        // Save, then the proof once it is committed
        RefundQueries::saveOutsideRefund((int) $booking['id'], $reasons[$key], mb_substr($note, 0, 2000), $ref, $on, $actor,
            'Refunded outside the system by the school. ' . $labels[$key] . ': ' . mb_substr($note, 0, 400)
            . ' Cashier reference ' . $ref . ', ' . date('M j, Y', strtotime($on)) . '.');
        $this->storeRefundFiles((int) $booking['id'], $files, $actor);
    }

    /* MOVE A DATE: same length, same price. Staff once, unless the office closed. */
    private function moveDate(Request $request, array $booking, int $actor): void
    {
        $id = (int) $booking['id'];
        $from = (string) $request->post('from');
        $to = (string) $request->post('to');
        $closure = !empty($request->post('closure'));
        // Check
        if (!in_array($booking['reservation_status'], [Status::PENDING, Status::APPROVED], true)
            || !in_array($booking['payment_status'], array_merge(Status::UNPAID, [Status::CONFIRMED, Status::PAID_CASH]), true)) {
            $this->reply(409, ['ok' => false, 'message' => 'This booking cannot be moved.']);
        }
        if (BookingCreateQueries::eventStarted($id)) {
            $this->reply(409, ['ok' => false, 'message' => 'The event has already started, so it cannot be moved.']);
        }
        if ((int) $booking['date_moves'] >= 1 && !$closure && session('account_type') !== 'admin') {
            $this->reply(403, ['ok' => false, 'message' => 'This booking has already been moved once. Only an administrator can move it again.']);
        }
        if (!BookingRules::isDate($from) || !BookingRules::isDate($to) || $to < $from || $from <= date('Y-m-d')) {
            $this->reply(400, ['ok' => false, 'message' => 'Pick new dates after today, with the end on or after the start.']);
        }
        $office = office_by_id($booking['venue_id']);
        $note = 'Moved to ' . date('M j, Y', strtotime($from)) . ($to !== $from ? ' – ' . date('M j, Y', strtotime($to)) : '') . ($closure ? ' because the office closed.' : '.');

        if ($booking['booking_type'] === 'hostel') {
            $this->moveStay($booking, $actor, $from, $to, $office, $closure, $note);
            return;
        }
        $start = (string) $request->post('start');
        $end = (string) $request->post('end');
        if (!BookingRules::validHours($start, $end, $office)) {
            $this->reply(400, ['ok' => false, 'message' => 'Pick a start and end time within the office hours (' . $office['opens'] . ' to ' . $office['closes'] . ').']);
        }
        if ($far = BookingRules::tooFarAhead($to)) {
            $this->reply(409, ['ok' => false, 'message' => $far]);
        }
        if (BookingRules::daysBetween($from, $to) + 1 !== BookingQueries::countHeldVenueDays($id)) {
            $this->reply(400, ['ok' => false, 'message' => 'The new dates must cover the same number of days, so the price stays the same.']);
        }

        // Save: the deadline housekeeping, then one transaction, as a new booking does.
        BookingCreateQueries::saveNew(function () use ($id, $from, $to, $start, $end, $closure, $actor, $note) {
            BookingQueries::moveVenueSlots($id, $from, $to, $start, $end);
            BookingQueries::saveDateMove($id, $closure, $actor, $note);
        });
    }

    private function moveStay(array $booking, int $actor, string $in, string $out, array $office, bool $closure, string $note): void
    {
        $id = (int) $booking['id'];
        // Check
        if ($far = BookingRules::tooFarAhead($out)) {
            $this->reply(409, ['ok' => false, 'message' => $far]);
        }
        if ($closed = BookingRules::stayClosed($office, $in, $out)) {
            $this->reply(409, ['ok' => false, 'message' => $closed]);
        }
        $was = BookingQueries::findHostelStay($id);
        if (BookingRules::daysBetween($in, $out) !== BookingRules::daysBetween($was['check_in_date'], $was['check_out_date'])) {
            $this->reply(400, ['ok' => false, 'message' => 'The new dates must cover the same number of nights, so the price stays the same.']);
        }

        // Save: the new dates and a bed per guest first. BookingRules::assignBeds runs
        // outside any transaction (each bed is its own), so the move is recorded after it.
        $occupants = BookingQueries::findOccupantIds($id);
        $pdo = venusep_db();
        $place = function (string $i, string $o) use ($pdo, $booking, $id, $occupants, $actor, $note) {
            BookingQueries::clearBedsAndSetStayDates($id, $i, $o, $actor, $note);
            BookingRules::assignBeds($pdo, (int) $booking['room_id'], $id, $occupants, $i, $o);
        };
        try {
            $place($in, $out);
        } catch (\Throwable $e) {
            $place($was['check_in_date'], $was['check_out_date']);     // back where it was
            if ($e instanceof \PDOException) {
                sql_failed($e);
            }
            $this->reply(409, ['ok' => false, 'message' => 'There are not enough free beds on those dates. Nothing changed.']);
        }
        /* saveDateMove runs inside its caller's transaction, like the venue move's. */
        DB::transaction(fn () => BookingQueries::saveDateMove($id, $closure, $actor, $note));
    }

    /* HOSTEL CHECK-OUT: the bed is free again from today, however early they left. */
    private function checkOut(array $booking, int $actor): void
    {
        // Check
        $stay = BookingQueries::findHostelStay((int) $booking['id']);
        if (!$stay || !$stay['checked_in']) {
            $this->reply(409, ['ok' => false, 'message' => 'Only a guest who has checked in can check out.']);
        }
        if ($stay['checked_out']) {
            $this->reply(409, ['ok' => false, 'message' => 'The guest has already checked out.']);
        }

        // Save
        BookingQueries::markCheckedOut((int) $booking['id'], $actor, 'Guest checked out.');
    }
}
