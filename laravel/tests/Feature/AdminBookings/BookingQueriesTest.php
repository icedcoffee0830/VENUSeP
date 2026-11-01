<?php

namespace Tests\Feature\AdminBookings;

use App\Queries\BookingQueries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/* The query file's own promises: a save that matches nothing is noticed, and a refused save leaves nothing behind. */
class BookingQueriesTest extends TestCase
{
    use SeedsBookings;

    private const ACTOR = 1;

    public function test_approving_a_booking_that_is_not_there_says_so_in_debug_mode(): void
    {
        Log::spy();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BookingQueries::approve changed 0 rows');
        BookingQueries::approve(99999999, false, 0, 0.0, self::ACTOR, 'x');
    }

    public function test_with_debug_off_it_is_logged_and_the_page_keeps_its_friendly_message(): void
    {
        config(['app.debug' => false]);
        Log::shouldReceive('error')->once()->with('BookingQueries::approve changed 0 rows');
        try {
            BookingQueries::approve(99999999, false, 0, 0.0, self::ACTOR, 'x');
            $this->fail('the procedure should refuse a booking that is not pending');
        } catch (QueryException $e) {
            $this->assertSame('45000', (string) $e->getCode());
        }
    }

    public function test_a_refused_approval_saves_nothing(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash', 'gcash', true, false);
        try {
            BookingQueries::approve($id, true, 20, 100.0, self::ACTOR, 'x');
            $this->fail('the procedure should refuse a booking that is not pending');
        } catch (QueryException $e) {
            $this->assertSame('Only a pending booking can be approved.', $e->errorInfo[2]);
        }
        $this->assertSame(0.0, (float) $this->booking($id)->discount_amount);
        $this->assertNull(DB::selectOne('SELECT @venusep_actor_user_id AS who')->who);
    }

    /* Every save that must touch its row says so when it matches none, as a broken WHERE would. */
    #[DataProvider('savesOnABookingThatIsNotThere')]
    public function test_a_save_that_matches_no_row_is_noticed(string $function, array $after): void
    {
        Log::spy();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("BookingQueries::$function changed 0 rows");
        BookingQueries::$function(99999999, ...$after);
    }

    /* [function, its arguments after the booking id] */
    public static function savesOnABookingThatIsNotThere(): array
    {
        return [
            ['markRejected', ['', self::ACTOR, 'x']],
            ['savePosNumber', ['P', false, self::ACTOR, 'x']],
            ['saveOfficialReceiptNumber', ['O', self::ACTOR, 'x']],
            ['markCheckedIn', [self::ACTOR, 'x']],
            ['markCheckedOut', [self::ACTOR, 'x']],
            ['extendPayByDeadline', ['2030-01-01 00:00:00', self::ACTOR, 'x']],
            ['releaseOverdue', [self::ACTOR, 'x']],
            ['allowMoreReceiptAttempts', [5, 10, self::ACTOR, 'x']],
        ];
    }

    /* The POS is saved, then opening payment matches nothing: the POS goes back too. */
    public function test_a_save_that_fails_halfway_leaves_nothing_behind(): void
    {
        [$id] = $this->seedHostelBooking('approved', 'await_pos');
        DB::unprepared("
            CREATE TRIGGER zz_test_refuse_payment BEFORE UPDATE ON bookings FOR EACH ROW
            BEGIN
                IF NEW.id = $id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test';
                END IF;
            END
        ");
        try {
            BookingQueries::savePosNumber($id, 'POS-1', true, self::ACTOR, 'x');
            $this->fail('the trigger should refuse the booking update');
        } catch (QueryException $e) {
            $this->assertSame('refused by the test', $e->errorInfo[2]);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS zz_test_refuse_payment');
        }
        $this->assertNull(DB::table('hostel_booking_details')->where('booking_id', $id)->value('pos_number'));
        $this->assertNull(DB::selectOne('SELECT @venusep_actor_user_id AS who')->who);
    }

    public function test_saving_the_same_values_is_not_mistaken_for_a_broken_where(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, false);
        DB::table('bookings')->where('id', $id)->update(['updated_by_user_id' => self::ACTOR]);
        BookingQueries::approve($id, false, 0, 0.0, self::ACTOR, 'x');
        $this->assertSame('approved', $this->booking($id)->reservation_status);
    }
}
