<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* Booking History's "Due payments" tab (Aron 2026-11-05) = awaiting payment + overdue. The
   page marks each row (data-due); a cancelled booking owes nothing, whatever its payment says. */
class BookingHistoryDueFilterTest extends TestCase
{
    use SeedsBookings;

    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    private function seedRow(string $res, string $pay): string
    {
        [$id, $ref] = $this->seedVenueBooking('r1', $res, $pay);
        $this->made[] = $id;
        return $ref;
    }

    /* The row's own opening tag: its reference and its mark are on the same <tr>. */
    private function dueMark(string $html, string $ref): string
    {
        $this->assertSame(1, preg_match('/<tr\b(?:(?!<\/tr>)[^>])*data-booking-id="' . preg_quote($ref, '/') . '"[^>]*>/', $html, $m), 'no row for ' . $ref);
        $this->assertSame(1, preg_match('/data-due="([^"]*)"/', $m[0], $due), 'no due mark on ' . $ref);
        return $due[1];
    }

    public function test_the_tab_is_on_the_page(): void
    {
        $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()
            ->assertSee('data-booking-status="due"', false)->assertSee('Due payments');
    }

    public function test_awaiting_payment_and_overdue_rows_are_due(): void
    {
        $refs = [$this->seedRow('approved', 'await_gcash'), $this->seedRow('approved', 'await_cash'), $this->seedRow('approved', 'await_pos'), $this->seedRow('approved', 'overdue')];
        $html = $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()->getContent();
        foreach ($refs as $ref) {
            $this->assertSame('1', $this->dueMark($html, $ref), $ref);
        }
    }

    public function test_paid_not_yet_payable_and_cancelled_rows_are_not_due(): void
    {
        $refs = [$this->seedRow('approved', 'confirmed'), $this->seedRow('approved', 'await_event'), $this->seedRow('pending', 'locked'), $this->seedRow('cancelled', 'overdue')];
        $html = $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()->getContent();
        foreach ($refs as $ref) {
            $this->assertSame('', $this->dueMark($html, $ref), $ref);
        }
    }
}
