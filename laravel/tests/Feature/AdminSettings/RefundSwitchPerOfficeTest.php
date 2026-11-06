<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* One refund switch per office (DB-DECISIONS 28, venues.refunds_enabled): Bahay Alumni (1) and
   USeP Venues (2) on = pay before + refunds, off = pay after + no refunds; USeP Hostel (3) always
   pays before, its switch is refunds only. Each booking keeps the rule it was made under.
   The switches and the bookings a test makes are put back afterwards. office_all() is read once
   per request (a static), so a test that needs a page under another switch state is its own method. */
class RefundSwitchPerOfficeTest extends TestCase
{
    use AdminTestHelpers;

    private array $saved = [];
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = DB::table('venues')->pluck('refunds_enabled', 'id')->all();
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
            $dir = getenv('VENUSEP_DOC_ROOT') . '/bookings/' . $id;
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        foreach ($this->saved as $id => $on) {
            DB::update('UPDATE venues SET refunds_enabled = ? WHERE id = ?', [$on, $id]);
        }
        $this->restoreAdminPassword();
        parent::tearDown();
    }

    private function switches(array $on): void
    {
        foreach ($on as $id => $value) {
            DB::update('UPDATE venues SET refunds_enabled = ? WHERE id = ?', [$value, $id]);
        }
    }

    private function idFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, 'my-id.png', 'image/png', null, true);
    }

    /* A customer's online booking; returns the response. No agree_no_refund unless asked. */
    private function submit(array $post)
    {
        $post += ['_token' => 'test-token', 'affiliated' => '0', 'id_document' => $this->idFile()];
        return $this->actingAsCustomer('aronerfe0830@gmail.com')->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), $post);
    }

    private function venue(string $room, array $over = []): array
    {
        $day = date('Y-m-d', strtotime('+' . random_int(400, 1400) . ' days'));
        return $over + ['type' => 'venue', 'room' => $room, 'event_name' => 'Per-office refund test', 'date_start' => $day, 'date_end' => $day,
            'attendees' => '20', 'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]), 'method' => 'gcash'];
    }

    private function hostel(int $daysAhead, array $over = []): array
    {
        $in = date('Y-m-d', strtotime('+' . $daysAhead . ' days'));
        return $over + ['type' => 'hostel', 'room' => 'h1', 'check_in' => $in, 'check_out' => date('Y-m-d', strtotime($in . ' +1 day')),
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'male']])];
    }

    private function rule(int $bookingId): array
    {
        return (array) DB::selectOne('SELECT refunds_allowed, pay_to_use, payment_policy FROM v_booking_summary WHERE booking_id = ?', [$bookingId]);
    }

    public function test_each_office_sets_its_new_bookings_rule_and_flipping_it_keeps_the_old_ones(): void
    {
        $this->switches([1 => 0, 2 => 1]);

        /* USeP Venues is on: pay before, refundable, no "non-refundable" tick needed */
        $on = $this->submit($this->venue('r5'))->assertOk()->json('bookingId');
        $this->made[] = $on;
        $this->assertEquals(['refunds_allowed' => 1, 'pay_to_use' => 0, 'payment_policy' => 'prepay'], $this->rule($on));

        /* Bahay Alumni is off: the tick is required, then pay after, non-refundable */
        $this->submit($this->venue('r1'))->assertStatus(400)
            ->assertJson(['message' => 'Please confirm you understand this booking is non-refundable.']);
        $off = $this->submit($this->venue('r1', ['agree_no_refund' => '1']))->assertOk()->json('bookingId');
        $this->made[] = $off;
        $this->assertEquals(['refunds_allowed' => 0, 'pay_to_use' => 0, 'payment_policy' => 'postpay'], $this->rule($off));

        /* the super admin flips both: the two bookings keep what they were made under */
        $this->switches([1 => 1, 2 => 0]);
        $this->assertEquals('prepay', $this->rule($on)['payment_policy']);
        $this->assertEquals('postpay', $this->rule($off)['payment_policy']);
        $this->assertEquals(1, $this->rule($on)['refunds_allowed']);
        $this->assertEquals(0, $this->rule($off)['refunds_allowed']);
    }

    public function test_the_hostel_with_refunds_on_still_pays_before(): void
    {
        $this->switches([3 => 1]);
        $on = $this->submit($this->hostel(1))->assertOk()->json('bookingId');   // no "non-refundable" tick needed
        $this->made[] = $on;
        $this->assertEquals(['refunds_allowed' => 1, 'pay_to_use' => 1, 'payment_policy' => 'pay_to_use'], $this->rule($on));

        $this->switches([3 => 0]);
        $this->assertEquals(['refunds_allowed' => 1, 'pay_to_use' => 1, 'payment_policy' => 'pay_to_use'], $this->rule($on),
            'the stay keeps its refund right');
    }

    public function test_the_hostel_with_refunds_off_pays_before_and_settings_say_it_is_fixed(): void
    {
        $this->submit($this->hostel(3))->assertStatus(400)
            ->assertJson(['message' => 'Please confirm you understand this booking is non-refundable.']);
        $off = $this->submit($this->hostel(3, ['agree_no_refund' => '1']))->assertOk()->json('bookingId');
        $this->made[] = $off;
        $this->assertEquals(['refunds_allowed' => 0, 'pay_to_use' => 1, 'payment_policy' => 'pay_to_use'], $this->rule($off));

        $page = $this->actingAsAdmin()->get(route('admin.payment-settings'))->assertOk();
        $page->assertSee('Payment: always before the stay (fixed).');
        $this->assertSame(1, substr_count($page->getContent(), 'Payment: always before the stay (fixed).'), 'the hostel row only');
        $page->assertSee('Payment: <strong>after the event</strong>', false);   // the two event offices, off
    }

    public function test_only_the_super_admin_changes_a_switch(): void
    {
        $post = ['_token' => 'test-csrf-token', 'venue_id' => '1', 'enable' => '1', 'password' => 'whatever'];
        $this->actingAsAdmin('francishubero@gmail.com')->withTestCsrf()->post(route('admin.refund-switch'), $post)
            ->assertStatus(403)->assertJson(['error' => 'not_admin']);
        $this->flushSession();
        $this->actingAsStaff()->withTestCsrf()->post(route('admin.refund-switch'), $post)->assertStatus(403);
        $this->assertEquals(0, DB::table('venues')->where('id', 1)->value('refunds_enabled'));

        $this->flushSession();
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.payment-settings'))->assertOk()
            ->assertSee('Only the super admin can change this')->assertDontSee('id="rsModal"', false)->assertDontSee('data-rs-open', false);

        $this->flushSession();
        $this->useKnownAdminPassword();
        $this->actingAsAdmin()->withTestCsrf()->post(route('admin.refund-switch'), ['password' => $this->pw] + $post)
            ->assertOk()->assertJson(['ok' => true, 'enabled' => true]);
        $this->assertEquals([1 => 1, 2 => 0, 3 => 0], DB::table('venues')->orderBy('id')->pluck('refunds_enabled', 'id')->map(fn ($v) => (int) $v)->all());
        $this->get(route('admin.payment-settings'))->assertOk()->assertSee('Last changed by <strong>admin@gmail.com</strong>', false);
    }

    public function test_the_public_page_and_the_faq_describe_each_office(): void
    {
        $this->switches([1 => 1, 2 => 0, 3 => 0]);
        $this->get(route('customer.venues'))->assertOk()
            ->assertSee('<strong>Bahay Alumni:</strong> before your event, at least one day ahead.', false)
            ->assertSee('<strong>USeP Venues:</strong> after your event, by the day after it.', false)
            ->assertSee('<strong>USeP Hostel:</strong> before your stay, by your check-in day.', false)
            ->assertSee('Refunds depend on the office.')
            ->assertSee('At Bahay Alumni you can ask for a refund')
            ->assertSee('Bookings at USeP Venues and USeP Hostel are non-refundable');

        $faq = $this->get(route('customer.faq'))->assertOk();
        $faq->assertSee('<li><strong>Bahay Alumni:</strong> paid before your event, and you can ask for a refund.</li>', false)
            ->assertSee('<li><strong>USeP Venues:</strong> paid after your event, and non-refundable.</li>', false)
            ->assertSee('<li><strong>USeP Hostel:</strong> paid before your stay, and non-refundable.</li>', false)
            ->assertSee('Who can ask for a refund? <span class="fq-for">Bahay Alumni</span>', false)
            ->assertSee('Can I get a refund? <span class="fq-for">USeP Venues and USeP Hostel</span>', false);
        /* the hostel section is about the hostel only: its one rule, untagged */
        $this->assertSame(1, substr_count($faq->getContent(), 'How do I pay for a hostel bed?'));
    }

    /* every office off (the test DB): one rule, nothing tagged, no refund questions */
    public function test_with_every_office_off_the_pages_say_non_refundable(): void
    {
        $this->get(route('customer.venues'))->assertOk()->assertSee('All bookings are non-refundable.')->assertDontSee('Refunds depend on the office.');
        $this->get(route('customer.faq'))->assertOk()
            ->assertDontSee('class="fq-for"', false)->assertDontSee('Who can ask for a refund?')->assertSee('Can I get a refund?');
        // the booking pages promise no money back after a closure either
        $this->actingAsCustomer('aronerfe0830@gmail.com');
        $this->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk()->assertDontSee('your payment is returned');
        $this->get(route('customer.room-reservation', ['room' => 'r5']))->assertOk()->assertDontSee('your payment is returned');
    }
}
