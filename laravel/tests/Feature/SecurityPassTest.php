<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The testing round's security pass (2026-11-06): each test is a request that showed a hole. */
class SecurityPassTest extends TestCase
{
    /* The hostel booking page counts the beds taken each night. It sent every guest's NAME too, of
       every stay, to any signed-in customer (anyone can sign up). The count and the gender mix stay. */
    public function test_the_hostel_page_never_shows_another_customers_guests(): void
    {
        $me = DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.email', 'customer@gmail.com')->value('c.id');
        $names = array_column(DB::select("
            SELECT DISTINCT o.full_name
            FROM bed_reservation_nights n
            JOIN bed_reservations br ON br.id = n.bed_reservation_id
            JOIN hostel_occupants o ON o.id = br.occupant_id
            JOIN bookings b ON b.id = n.booking_id
            WHERE n.released_at IS NULL AND b.reservation_status IN ('pending', 'approved', 'completed')
              AND b.customer_id <> ? AND CHAR_LENGTH(o.full_name) > 5", [$me]), 'full_name');
        $this->assertNotEmpty($names, 'the snapshot has no other customer\'s hostel guest');

        $page = $this->actingAsCustomer()->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk();
        $html = $page->getContent();
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_contains($html, json_encode($n, JSON_UNESCAPED_UNICODE)))),
            'other customers\' guests named on the page');
        $this->assertMatchesRegularExpression('/"occupied":\{"\d{4}-\d\d-\d\d":\[\{"gender":"[FMO]"\}/', $html,
            'the nights are still there, with the gender mix, for the bed count');
    }
}
