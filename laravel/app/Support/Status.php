<?php

namespace App\Support;

/* The status words the code compares against, so a typo fails loudly instead of quietly matching nothing.
   The full lists, with what each word means: venusep_schema.sql (reservation_statuses, payment_statuses). */
final class Status
{
    /* What colour each status word's chip is (public/assets/css/chips.css): a word not listed is grey.
       Green = done well, amber = waiting on someone, red = stopped, grey = finished or neutral. */
    public const CHIP_TONES = [
        'Paid' => 'green', 'Approved' => 'green',
        'Pending' => 'amber', 'Payment due' => 'amber', 'Awaiting POS' => 'amber', 'Under review' => 'amber', 'Action needed' => 'amber',
        'Refund requested' => 'amber', 'Refund processing' => 'amber', 'Refund - action needed' => 'amber', 'Refund - Official Receipt needed' => 'amber',
        'Overdue' => 'red', 'Rejected' => 'red', 'Refund denied' => 'red',
        /* the staff words, each the colour of its customer word */
        'Payment confirmed' => 'green', 'Paid at cashier' => 'green', 'Paid at the front desk' => 'green', 'Reservation approved' => 'green',
        'Reservation pending review' => 'amber', 'Awaiting POS - CEDU' => 'amber', 'Awaiting GCash payment' => 'amber', 'Awaiting cash payment' => 'amber',
        'Receipt under review' => 'amber', 'Room closed - awaiting decision' => 'amber', 'Refund returned for correction' => 'amber',
        'Refund approved - awaiting Official Receipt' => 'amber',
        'Payment overdue' => 'red', 'Request rejected' => 'red',
    ];

    public static function chipClass(string $word): string
    {
        return 'vz-chip vz-chip-' . (self::CHIP_TONES[$word] ?? 'grey');
    }

    /* bookings.reservation_status: where the booking itself stands.
       gcash_receipts.verdict also uses 'rejected'. */
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const RELEASED = 'released';
    public const COMPLETED = 'completed';

    /* bookings.payment_status: where the money stands */
    public const LOCKED = 'locked';
    public const AWAIT_EVENT = 'await_event';
    public const AWAIT_POS = 'await_pos';
    public const AWAIT_GCASH = 'await_gcash';
    public const AWAIT_CASH = 'await_cash';
    public const UNDER_REVIEW = 'under_review';
    public const OVERDUE = 'overdue';
    public const CONFIRMED = 'confirmed';
    public const PAID_CASH = 'paid_cash';
    public const REFUND_REQUESTED = 'refund_requested';
    public const REFUND_CORRECTION = 'refund_correction';
    public const REFUND_AWAIT_OR = 'refund_await_or';
    public const REFUND_PROCESSING = 'refund_processing';
    public const REFUND_DENIED = 'refund_denied';

    /* The customer can pay now. Not OVERDUE: that stays closed until staff extend the
       deadline, which puts it back here (Aron 2026-11-05). */
    public const PAY_OPEN = [self::AWAIT_GCASH, self::AWAIT_CASH];

    /* Booking History's "Due payments" tab: awaiting payment (as Booking Management's tab counts it) + overdue. */
    public const PAYMENT_DUE = [self::AWAIT_GCASH, self::AWAIT_CASH, self::AWAIT_POS, self::OVERDUE];

    /* A booking that owes nothing yet. */
    public const UNPAID = [self::LOCKED, self::AWAIT_EVENT, self::AWAIT_GCASH, self::AWAIT_CASH, self::AWAIT_POS];

    /* refunds.refund_status (an ENUM in CREATE TABLE refunds). It also uses 'under_review'
       and 'approved', the same words as above. */
    public const REQUESTED = 'requested';
    public const RETURNED_FOR_CORRECTION = 'returned_for_correction';

    /* A refund staff can still decide. */
    public const REFUND_OPEN = [self::REQUESTED, self::UNDER_REVIEW, self::RETURNED_FOR_CORRECTION, self::APPROVED];
}
