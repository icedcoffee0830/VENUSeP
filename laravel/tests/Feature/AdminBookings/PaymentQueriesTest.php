<?php

namespace Tests\Feature\AdminBookings;

use App\Queries\PaymentQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/* The payment query file's promise: a save that matches no row is noticed, and leaves nothing behind. */
class PaymentQueriesTest extends TestCase
{
    use SeedsBookings;

    private const ACTOR = 1;

    #[DataProvider('savesOnRowsThatAreNotThere')]
    public function test_a_save_that_matches_no_row_is_noticed(string $function, array $arguments): void
    {
        Log::spy();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("PaymentQueries::$function changed 0 rows");
        PaymentQueries::$function(...$arguments);
    }

    /* [function, its arguments]: a booking and an upload that do not exist */
    public static function savesOnRowsThatAreNotThere(): array
    {
        return [
            ['overrideRejectedUpload', [99999999, 99999999, null, self::ACTOR, 'x']],
            ['undoStaffRejection', [99999999, 99999999, null, self::ACTOR, 'x']],
            ['rejectReceiptUnderReview', [99999999, 'x', self::ACTOR, 'x']],
            ['markPaymentReversed', [99999999, 'x', self::ACTOR, 'x']],
        ];
    }

    /* The upload exists, the booking does not: the upload's change goes back too. */
    public function test_an_undo_that_fails_halfway_leaves_the_upload_as_it_was(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $upload = DB::table('gcash_receipts')->insertGetId(['booking_id' => $id, 'attempt_no' => 1, 'file_path' => 'receipts/pq-' . $id . '.png',
            'reference_number' => (string) random_int(1000000000000, 9999999999999), 'sha256_hash' => hash('sha256', uniqid('', true)),
            'verdict' => 'manual_review', 'flags_json' => '["rejected_by_staff"]', 'reviewed_at' => now()]);
        Log::spy();
        try {
            PaymentQueries::undoStaffRejection(99999999, $upload, null, self::ACTOR, 'x');
            $this->fail('the booking update should match nothing');
        } catch (RuntimeException $e) {
            $this->assertSame('PaymentQueries::undoStaffRejection changed 0 rows', $e->getMessage());
        }
        $g = DB::table('gcash_receipts')->where('id', $upload)->first();
        $this->assertSame(['rejected_by_staff'], json_decode($g->flags_json, true));
        $this->assertNotNull($g->reviewed_at);
    }
}
