<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The ₱1 case (migration 19): rounding would make it free, so the full price stays and there
   is no discount. discount_percent keeps the rate given, so every screen must go by
   discount_amount and show no discount line: admin, customer, receipt. */
class NoDiscountLeftTest extends TestCase
{
    use SeedsBookings;

    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM system_receipts WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM payments WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function approve(string $ref)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-action'), ['booking' => $ref, 'action' => 'approve', 'discount' => '1'])->assertOk();
    }

    /* A ₱1 booking with a USeP claim, approved with the discount. */
    private function approvedOnePeso(): array
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        $this->made[] = $id;
        DB::table('bookings')->where('id', $id)->update(['room_price' => 1, 'total_amount' => 1, 'quoted_discount_percent' => 20]);
        $this->actingAsStaff();
        $reply = $this->approve($ref);
        $row = $this->booking($id);
        $this->assertSame(['1.00', '0.00'], [(string) $row->total_amount, (string) $row->discount_amount]);
        $this->assertGreaterThan(0, (float) $row->discount_percent);   /* the rate given stays on the row */
        return [$id, $ref, $reply];
    }

    public function test_the_admin_panel_does_not_say_a_rate_was_applied(): void
    {
        [, , $reply] = $this->approvedOnePeso();
        $this->assertEquals(0, $reply->json('booking.discount'));   /* cur.discountPct: "no discount — full price" */
    }

    public function test_a_real_discount_still_reports_its_rate(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, true);
        $this->made[] = $id;
        DB::table('bookings')->where('id', $id)->update(['room_price' => 1000, 'total_amount' => 1000, 'quoted_discount_percent' => 20]);
        $this->actingAsStaff();
        $this->assertEquals(20, $this->approve($ref)->json('booking.discount'));
    }

    public function test_the_customers_details_show_no_discount_line(): void
    {
        [, $ref] = $this->approvedOnePeso();
        $this->flushSession();
        $this->actingAsCustomer()->getJson(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.money.discount', '')->assertJsonPath('details.money.total', '₱1.00');
    }

    public function test_the_receipt_shows_no_discount_line(): void
    {
        [$id] = $this->approvedOnePeso();
        legacy('system-receipt');
        $pdo = venusep_db();
        $actor = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->assertNotNull(payment_confirm($pdo, $id, $actor, 'Test payment', true));
        $view = receipt_view(receipt_for_booking($pdo, $id));
        $this->assertStringNotContainsString('USeP discount', json_encode($view));
    }
}
