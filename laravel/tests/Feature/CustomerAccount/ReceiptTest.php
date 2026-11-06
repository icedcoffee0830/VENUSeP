<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/receipt + receipt-pdf. The test DB has no System Receipts, so each
   test issues one (legacy receipt_issue) for booking 7 (customer@gmail.com,
   confirmed payment) and removes it, plus any email it queued, afterwards. */
class ReceiptTest extends TestCase
{
    use \Tests\Feature\Email\FakeMail;

    use BookingRefs;

    private const BOOKING = 7;

    private int $receiptId;

    protected function setUp(): void
    {
        parent::setUp();
        legacy('system-receipt');
        $this->receiptId = receipt_issue(self::BOOKING, 1);
    }

    protected function tearDown(): void
    {
        foreach (DB::table('email_outbox')->where('receipt_id', $this->receiptId)->pluck('id') as $outboxId) {
            @unlink(doc_root() . '/mail-log/' . $outboxId . '.eml');
        }
        $this->stopFakeMail();
        DB::table('email_outbox')->where('receipt_id', $this->receiptId)->delete();
        DB::table('system_receipts')->where('id', $this->receiptId)->delete();
        parent::tearDown();
    }

    public function test_guest_is_sent_to_sign_in_and_a_stranger_gets_404(): void
    {
        $this->get(route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]))
            ->assertRedirect(route('customer.customer-login'));
        $this->actingAsCustomer('jmdelacruz@usep.edu.ph')
            ->get(route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]))
            ->assertNotFound()->assertSeeText('Not found.');
    }

    public function test_the_receipt_view_has_the_phone_tab_bar_on_bookings(): void
    {
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]))
            ->assertOk()
            ->assertSee('data-tab="bookings" aria-current="page"', false);
    }

    public function test_the_owner_sees_the_receipt_and_the_confirm_strip(): void
    {
        $ref = $this->ref(self::BOOKING);
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.receipt', ['booking' => $ref, 'confirm' => 1]))
            ->assertOk()
            ->assertSee('Transaction Receipt')->assertDontSee('System Receipt')
            ->assertSee('A refund request needs this receipt.')
            ->assertSee('<a class="rc-back" href="' . route('customer.booking-history') . '">', false)
            ->assertSee('Back to My bookings')
            ->assertSee('Send this receipt as a PDF to', false)
            ->assertSee('customer@gmail.com')
            ->assertSee(route('root.receipt-pdf', ['booking' => $ref]), false)
            ->assertSee('name="_token"', false);
    }

    public function test_a_booking_without_a_receipt_is_404(): void
    {
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.receipt', ['booking' => $this->ref(10)]))   // rejected, never paid
            ->assertNotFound();
    }

    private function emailMe(string $self)
    {
        return $this->actingAsCustomer('customer@gmail.com')->withSession(['_token' => 't'])
            ->post($self, ['_token' => 't', 'action' => 'email'])->assertStatus(303);
    }

    /* With settings the copy goes out; a second one within 5 minutes is not queued. */
    public function test_email_me_sends_one_copy_and_holds_the_next(): void
    {
        $this->fakeGmail();
        $self = route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]);
        $this->emailMe($self)->assertRedirect($self . '&sent=1');
        $copy = DB::table('email_outbox')->where('receipt_id', $this->receiptId)->first();
        $this->assertSame(['receipt_copy', 'sent'], [$copy->kind, $copy->status]);
        $this->assertStringContainsString('VENUSeP Transaction Receipt', $copy->subject);
        $this->get($self . '&sent=1')->assertSee('Check your inbox');

        $this->emailMe($self)->assertRedirect($self . '&recent=1');
        $this->assertSame(1, DB::table('email_outbox')->where('receipt_id', $this->receiptId)->count());
    }

    /* The log transport: nothing left, so the page says so, and pressing again never says "already sent". */
    public function test_email_me_with_no_settings_never_claims_it_was_sent(): void
    {
        $self = route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]);
        foreach ([1, 2] as $press) {
            $this->emailMe($self)->assertRedirect($self . '&notsent=1');
        }
        $this->assertSame(['not_sent', 'not_sent'], DB::table('email_outbox')->where('receipt_id', $this->receiptId)->pluck('status')->all());
        $this->get($self . '&notsent=1')->assertOk()->assertSee('Not sent: email isn&rsquo;t set up here yet', false)
            ->assertDontSee('Check your inbox')->assertDontSee('Already sent');
    }

    public function test_email_me_when_the_mail_server_refuses_says_it_could_not_send(): void
    {
        ini_set('error_log', storage_path('logs/cacct-test-error.log'));   // mailer failures are error_log()ged
        $this->fakeGmail('reject');
        $self = route('customer.receipt', ['booking' => $this->ref(self::BOOKING)]);
        foreach ([1, 2] as $press) {
            $this->emailMe($self)->assertRedirect($self . '&failed=1');
        }
        $this->get($self . '&failed=1')->assertSee('We couldn&rsquo;t send it right now', false)->assertDontSee('Check your inbox');
    }

    public function test_pdf_for_the_owner_with_the_old_headers(): void
    {
        $res = $this->actingAsCustomer('customer@gmail.com')
            ->get(route('root.receipt-pdf', ['booking' => $this->ref(self::BOOKING)]));
        $res->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('attachment; filename="', $res->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($res->getContent()), $res->headers->get('Content-Length'));
    }

    public function test_pdf_is_404_for_a_guest_another_customer_and_another_venues_staff_but_an_admin_may_print_it(): void
    {
        $url = route('root.receipt-pdf', ['booking' => $this->ref(self::BOOKING)]);   // venue 2
        $this->get($url)->assertNotFound()->assertSeeText('Not found.');
        $this->actingAsCustomer('jmdelacruz@usep.edu.ph')->get($url)->assertNotFound();
        $this->flushSession();
        $this->actingAsStaff()->get($url)->assertNotFound();   // venue-1 staff
        $this->flushSession();
        $this->actingAsAdmin()->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /* A suspended staff account loses its session on its next request (auth.php
       staff_session_refresh, run for every request) — so the receipt is "not found". */
    public function test_a_suspended_staff_session_cannot_print_a_receipt(): void
    {
        DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 0]);
        try {
            $this->actingAsStaff()->get(route('root.receipt-pdf', ['booking' => $this->ref(self::BOOKING)]))
                ->assertNotFound();
            $this->assertNull(session('user_id'));
        } finally {
            DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 1]);
        }
    }
}
