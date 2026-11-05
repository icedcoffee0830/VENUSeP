<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* DB-DECISIONS 28: a customer blocked at ONE office. They still sign in and see everything,
   but make no new booking there, online or at the counter (the database refuses it too);
   staff cannot approve their pending request; one still blocked at its start is cancelled.
   Other offices are not affected, and the reason is never shown to the customer. */
class OfficeBlockTest extends TestCase
{
    private const EMAIL = 'aronerfe0830@gmail.com';   /* customer 7: no bookings in the snapshot */
    private const REASON = 'Secret reason: damaged the hall twice';
    private int $customerId = 0;
    private array $bookingIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = (int) DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.email', self::EMAIL)->value('c.id');
    }

    protected function tearDown(): void
    {
        DB::table('customer_office_blocks')->where('customer_id', $this->customerId)->delete();
        DB::table('email_outbox')->whereIn('booking_id', $this->bookingIds ?: [0])->delete();
        DB::table('bookings')->whereIn('id', $this->bookingIds ?: [0])->delete();
        parent::tearDown();
    }

    private function block(int $venueId = 2): void
    {
        $by = DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        DB::insert('INSERT INTO customer_office_blocks (customer_id, venue_id, reason, blocked_by_user_id) VALUES (?, ?, ?, ?)',
            [$this->customerId, $venueId, self::REASON, $by]);
    }

    /* A pending venue request for customer 7 in room r5 (USeP Venues), starting on $day. */
    private function pendingRequest(string $day): int
    {
        $room = DB::table('rooms')->where('room_code', 'r5')->value('id');
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => $this->customerId, 'room_id' => $room, 'booking_type' => 'venue',
            'reservation_status' => 'pending', 'payment_status' => 'locked', 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => 1000, 'discount_percent' => 0,
            'discount_amount' => 0, 'total_amount' => 1000, 'refunds_allowed' => 1, 'submitted_at' => now()->subDays(3),
        ]);
        DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Block test', 'start_date' => $day, 'end_date' => $day, 'attendee_count' => 20]);
        DB::table('venue_booking_slots')->insert(['booking_id' => $id, 'room_id' => $room, 'slot_date' => $day, 'start_time' => '08:00:00', 'end_time' => '10:00:00']);
        return $this->bookingIds[] = $id;
    }

    private function submit(string $room = 'r5')
    {
        $day = date('Y-m-d', strtotime('+' . random_int(400, 1400) . ' days'));
        return $this->actingAsCustomer(self::EMAIL)->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), [
                '_token' => 'test-token', 'type' => 'venue', 'room' => $room, 'event_name' => 'Blocked?',
                'date_start' => $day, 'date_end' => $day, 'attendees' => '50',
                'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
                'affiliated' => '0', 'method' => 'gcash', 'agree_no_refund' => '1',
                /* no ID: once past the block, the next check answers (nothing is written) */
            ]);
    }

    private function save(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->post(route('admin.customer-save'), ['csrf' => 'tok'] + $data);
    }

    public function test_online_booking_is_refused_at_that_office_only_without_the_reason(): void
    {
        $this->block();
        $res = $this->submit()->assertStatus(409)->assertJson(['ok' => false, 'error' => 'blocked']);
        $this->assertStringStartsWith("You can't book at USeP Venues right now. Please contact the office. USeP Venues: ", $res->json('message'));
        $this->assertStringContainsString('usep.venues@example.com', $res->json('message'));
        $this->assertStringNotContainsString('Secret', $res->json('message'));
        $this->submit('r1')->assertStatus(400)->assertJson(['message' => 'A valid ID is required to submit a booking.']);
    }

    public function test_the_booking_pages_say_so_up_front_with_the_contact_info(): void
    {
        $this->block();
        $notice = "You can't book at USeP Venues right now. Please contact the office.";
        $this->actingAsCustomer(self::EMAIL)->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk()
            ->assertSee($notice)->assertSee('usep.venues@example.com')->assertDontSee('Secret reason');
        $this->get(route('customer.venues'))->assertOk()->assertSee($notice);
        $this->get(route('customer.room-reservation', ['room' => 'r1']))->assertOk()->assertDontSee($notice);
    }

    public function test_the_database_refuses_a_new_booking_and_lifting_lets_it_through(): void
    {
        $this->block();
        try {
            $this->pendingRequest(date('Y-m-d', strtotime('+500 days')));
            $this->fail('the trigger let a blocked customer book');
        } catch (QueryException $e) {
            $this->assertStringContainsString('This customer is blocked at this office', $e->getMessage());
        }
        DB::update('UPDATE customer_office_blocks SET lifted_at = NOW() WHERE customer_id = ?', [$this->customerId]);
        $this->assertGreaterThan(0, $this->pendingRequest(date('Y-m-d', strtotime('+501 days'))));
        $this->submit()->assertStatus(400);   /* past the block: the ID check answers */
    }

    public function test_the_counter_shows_it_and_refuses_the_booking(): void
    {
        $this->block(1);
        $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk()
            ->assertSee('const COUNTER_BLOCKED = {"' . $this->customerId . '":true};', false);
        $day = now()->addDays(random_int(400, 4000))->toDateString();
        $this->actingAsStaff()->withSession(['counter_proof' => [$this->customerId => time()], '_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), [
                'type' => 'venue', 'room' => 'r1', 'method' => 'gcash', 'id_checked' => '1', 'agree_no_refund' => '1',
                'booker_mode' => 'account', 'customer_id' => (string) $this->customerId,
                'event_name' => 'Counter test', 'date_start' => $day, 'date_end' => $day, 'attendees' => '40',
                'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
            ])->assertStatus(409)->assertJson(['error' => 'blocked', 'message' => 'Blocked at this office. This customer cannot make new bookings here until an admin lifts the block.']);
    }

    public function test_staff_cannot_approve_and_the_lists_show_the_badge(): void
    {
        $id = $this->pendingRequest(date('Y-m-d', strtotime('+600 days')));
        $this->block();
        $ref = 'VB-' . DB::table('bookings')->where('id', $id)->value(DB::raw('YEAR(submitted_at)')) . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
        $this->actingAsAdmin()->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-action'), ['booking' => $ref, 'action' => 'approve'])
            ->assertStatus(409)->assertJson(['message' => 'This customer is blocked at this office. Lift the block first, or reject the request.']);
        $this->assertSame('pending', DB::table('bookings')->where('id', $id)->value('reservation_status'));

        legacy('bookings');
        $row = collect(booking_queue_rows())->firstWhere('id', $ref);
        $this->assertTrue($row['blocked']);
        $this->get(route('admin.booking-requests'))->assertOk()->assertSee('"blocked":true', false);
        $this->get(route('admin.booking-request', ['id' => $ref]))->assertOk()->assertSee('"blocked":true', false);
        DB::table('customer_office_blocks')->where('customer_id', $this->customerId)->update(['lifted_at' => now()]);
        $this->get(route('admin.booking-requests'))->assertOk()->assertDontSee('"blocked":true', false);
    }

    public function test_a_pending_request_still_blocked_at_its_start_is_cancelled_with_history_and_an_email(): void
    {
        $blocked = $this->pendingRequest(date('Y-m-d', strtotime('-1 day')));
        $this->block();
        DB::statement('CALL sp_expire_due_bookings()');
        $this->assertSame('cancelled', DB::table('bookings')->where('id', $blocked)->value('reservation_status'));
        $this->assertSame('Cancelled automatically: the customer was still blocked at this office when the booking was due to start.',
            DB::table('booking_timeline')->where(['booking_id' => $blocked, 'new_reservation_status' => 'cancelled'])->value('note'));

        legacy('bookings', 'receipt-emails');
        mail_status_sweep(DB::connection()->getPdo());
        $mail = DB::table('email_outbox')->where(['booking_id' => $blocked, 'kind' => 'booking_block_cancelled'])->first();
        $this->assertNotNull($mail, 'no email to the customer');
        $this->assertSame(self::EMAIL, $mail->to_email);
        $this->assertStringContainsString('Contact USeP Venues: ', $mail->body_text);
        $this->assertStringNotContainsString('Secret', $mail->body_html . $mail->body_text);
        mail_status_sweep(DB::connection()->getPdo());   /* once only */
        $this->assertSame(1, DB::table('email_outbox')->where(['booking_id' => $blocked, 'kind' => 'booking_block_cancelled'])->count());
    }

    public function test_an_unblocked_request_still_just_expires(): void
    {
        $id = $this->pendingRequest(date('Y-m-d', strtotime('-1 day')));
        $this->block(1);   /* another office */
        DB::statement('CALL sp_expire_due_bookings()');
        $this->assertSame('released', DB::table('bookings')->where('id', $id)->value('reservation_status'));
    }

    public function test_who_may_block_and_lift(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');   /* Bahay Alumni */
        $this->save(['action' => 'block', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => ''])->assertStatus(400);
        $this->save(['action' => 'block', 'customer_id' => $this->customerId, 'venue_id' => 2, 'reason' => 'x'])->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->save(['action' => 'block', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'No-show twice'])->assertOk();
        $this->save(['action' => 'block', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'again'])->assertStatus(409);
        $row = DB::table('customer_office_blocks')->where(['customer_id' => $this->customerId, 'venue_id' => 1])->first();
        $this->assertSame(['No-show twice', 75], [$row->reason, (int) $row->blocked_by_user_id]);

        $this->flushSession();
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->save(['action' => 'lift', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'x'])->assertStatus(403);
        $this->save(['action' => 'block', 'customer_id' => $this->customerId, 'venue_id' => 3, 'reason' => 'Hostel rules'])->assertOk();

        $this->flushSession();
        $this->actingAsStaff();
        $this->save(['action' => 'lift', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'x'])->assertStatus(403);

        $this->flushSession();
        $this->actingAsAdmin();   /* the super admin: any office */
        $this->save(['action' => 'lift', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'Paid for the damage'])->assertOk();
        $row = DB::table('customer_office_blocks')->where(['customer_id' => $this->customerId, 'venue_id' => 1])->first();
        $this->assertNotNull($row->lifted_at);
        $this->assertSame(['Paid for the damage', 1], [$row->lift_reason, (int) $row->lifted_by_user_id]);
        $this->save(['action' => 'lift', 'customer_id' => $this->customerId, 'venue_id' => 1, 'reason' => 'x'])->assertStatus(409);
        $this->get(route('admin.activity'))->assertOk()->assertSee('Block lifted at Bahay Alumni: Paid for the damage');
    }

    public function test_the_customers_page_is_scoped_and_suspension_is_the_super_admins(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');
        $this->get(route('admin.customer-management'))->assertOk()
            ->assertSee('customer@gmail.com')->assertDontSee('areyes@usep.edu.ph')->assertDontSee(self::EMAIL)
            ->assertSee('"office":"Bahay Alumni"', false)->assertDontSee('"office":"USeP Hostel"', false)
            ->assertDontSee('btn-cust-toggle');
        $this->save(['action' => 'set_active', 'user_id' => 4, 'active' => '0'])->assertStatus(403)
            ->assertJson(['message' => 'Only the super admin can suspend a customer account. To stop bookings at your office, block the customer there.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', 4)->value('is_active'));

        $this->flushSession();
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->get(route('admin.customer-management'))->assertOk()->assertSee('areyes@usep.edu.ph')->assertDontSee(self::EMAIL);

        $this->flushSession();
        $this->actingAsAdmin();
        $this->get(route('admin.customer-management'))->assertOk()->assertSee('areyes@usep.edu.ph')->assertSee(self::EMAIL)->assertSee('btn-cust-toggle');
    }
}
