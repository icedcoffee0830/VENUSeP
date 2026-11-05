<?php

namespace Tests\Feature\AdminBookings;

use App\Queries\RefundQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/* The refund query file's promise: a save that matches no row is noticed, and leaves nothing behind. */
class RefundQueriesTest extends TestCase
{
    use SeedsBookings;

    private const ACTOR = 1;

    #[DataProvider('savesOnRowsThatAreNotThere')]
    public function test_a_save_that_matches_no_row_is_noticed(string $function, array $arguments): void
    {
        Log::spy();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("RefundQueries::$function changed 0 rows");
        RefundQueries::$function(...$arguments);
    }

    /* [function, its arguments]: a booking and a refund that do not exist */
    public static function savesOnRowsThatAreNotThere(): array
    {
        return [
            ['saveWalkInRefund', [99999999, false, 'other', 'x', self::ACTOR, 'x']],
            ['markRefundReturned', [99999999, 99999999, 'x', self::ACTOR, 'x']],
            ['markRefundDenied', [99999999, 99999999, 'x', self::ACTOR, 'x']],
            ['markRefundPaidOut', [99999999, 99999999, 1.0, true, null, null, null, self::ACTOR, 'x']],
            ['saveOutsideRefund', [99999999, 'other', 'x', 'CR-1', '2026-01-01', self::ACTOR, 'x']],
        ];
    }

    /* Nothing waiting for an administrator is an answer, not a broken save. */
    public function test_approving_when_nothing_waits_is_false_and_writes_no_history(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash', false, true);
        DB::table('refunds')->insert(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'x',
            'amount_requested' => 100, 'refund_status' => 'requested']);
        $this->assertFalse(RefundQueries::markRefundApproved($id, self::ACTOR, 'x'));
        $this->assertSame(0, DB::table('booking_timeline')->where('booking_id', $id)->where('action_code', 'refund_approved')->count());
    }

    /* The refund is paid out, then closing the booking matches nothing: the payout goes back too. */
    public function test_a_payout_that_fails_halfway_leaves_the_refund_open(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash', false, true);
        $refund = DB::table('refunds')->insertGetId(['booking_id' => $id, 'reason_category' => 'other', 'reason' => 'x',
            'amount_requested' => 100, 'refund_status' => 'requested']);
        Log::spy();
        try {
            RefundQueries::markRefundPaidOut($refund, 99999999, 100.0, true, null, null, null, self::ACTOR, 'x');
            $this->fail('closing a booking that is not there should be noticed');
        } catch (RuntimeException $e) {
            $this->assertSame('RefundQueries::markRefundPaidOut changed 0 rows', $e->getMessage());
        }
        $this->assertSame('requested', DB::table('refunds')->where('id', $refund)->value('refund_status'));
    }
}
