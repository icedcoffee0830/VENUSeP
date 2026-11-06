<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Email\FakeMail;
use Tests\TestCase;

/* admin/booking-emails — the Receipt & emails panel's JSON + Resend. */
class BookingEmailsTest extends TestCase
{
    use FakeMail;
    use SeedsBookings;

    protected function tearDown(): void
    {
        $this->stopFakeMail();
        parent::tearDown();
    }

    private function failedEmail(int $bookingId): int
    {
        return (int) DB::table('email_outbox')->insertGetId([
            'kind' => 'walkin_booking', 'booking_id' => $bookingId, 'to_email' => 'walkin@example.test',
            'subject' => 'Test', 'body_html' => '<p>Test</p>', 'body_text' => 'Test', 'status' => 'failed',
        ]);
    }

    public function test_guest_gets_401_json(): void
    {
        $this->get(route('admin.booking-emails', ['booking' => 'VB-2026-190']))
            ->assertStatus(401)->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
    }

    public function test_staff_reads_the_panel(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $this->failedEmail($id);
        $this->actingAsStaff()->get(route('admin.booking-emails', ['booking' => $ref]))
            ->assertOk()->assertJson(['ok' => true, 'isWalkIn' => false, 'to' => 'customer@gmail.com', 'receipt' => null,
                'emails' => [['kind' => 'walkin_booking', 'status' => 'failed', 'attempts' => 0]]]);
    }

    public function test_every_email_kind_has_a_label(): void
    {
        // the panel showed "undefined" for any kind but the three walk-in ones (2026-11-05)
        [$id, $ref] = $this->seedVenueBooking('r1');
        DB::table('email_outbox')->insert(['kind' => 'booking_approved', 'booking_id' => $id, 'to_email' => 'customer@gmail.com',
            'subject' => 'Test', 'body_html' => '<p>Test</p>', 'body_text' => 'Test', 'status' => 'sent']);
        $this->actingAsStaff()->get(route('admin.booking-emails', ['booking' => $ref]))
            ->assertOk()->assertJson(['emails' => [['kind' => 'booking_approved', 'label' => 'Booking approved']]]);
        foreach (MAIL_KINDS as $kind => $label) {
            $this->assertNotSame('', $label, $kind);
        }
    }

    public function test_bad_reference_is_400(): void
    {
        $this->actingAsStaff()->get(route('admin.booking-emails', ['booking' => 'x']))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Invalid booking reference.']);
    }

    public function test_staff_cannot_resend_another_venues_email(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r5');
        $mail = $this->failedEmail($id);
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertStatus(403);
    }

    public function test_with_no_settings_resend_says_not_sent_and_the_row_never_says_sent(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $mail = $this->failedEmail($id);
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => false, 'message' => "Not sent: email isn't set up on this laptop.",
                'email' => ['id' => $mail, 'status' => 'not_sent', 'sent' => null]]);
        // the 'log' transport (no mail-config.php): nothing left the machine
        $eml = doc_root() . '/mail-log/' . $mail . '.eml';
        $this->assertFileExists($eml);
        @unlink($eml);

        $this->get(route('admin.booking-emails', ['booking' => $ref]))
            ->assertOk()->assertJson(['emails' => [['id' => $mail, 'status' => 'not_sent', 'sent' => null, 'error' => MAIL_NOT_SET_UP]]]);
        $this->assertNull(DB::table('email_outbox')->where('id', $mail)->value('sent_at'));
    }

    public function test_a_not_sent_row_goes_out_on_resend_once_settings_exist_and_only_once(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $mail = $this->failedEmail($id);
        DB::table('email_outbox')->where('id', $mail)->update(['status' => 'not_sent', 'last_error' => 'Not sent.']);

        $this->fakeGmail();
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Sent.', 'email' => ['id' => $mail, 'status' => 'sent', 'error' => null]]);
        $this->assertStringContainsString('RCPT TO:<walkin@example.test>', $this->fakeInbox());
        $this->assertNotNull(DB::table('email_outbox')->where('id', $mail)->value('sent_at'));

        // a sent email cannot be resent
        $this->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertStatus(409)->assertJson(['message' => 'That email was already sent, or is still on its way.']);
    }

    public function test_a_wrong_password_reads_plainly_and_the_password_appears_nowhere(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $mail = $this->failedEmail($id);
        $plain = 'The mail server refused the sign-in. Check the App Password in mail-config.php.';
        $log = tempnam(sys_get_temp_dir(), 'vlog');
        $oldLog = ini_set('error_log', $log);

        $this->fakeGmail('reject');
        $res = $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => false, 'message' => $plain, 'email' => ['status' => 'failed', 'error' => $plain]]);

        ini_set('error_log', $oldLog);
        $logged = (string) file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('VENUSeP mail #' . $mail . ' failed', $logged);
        $this->assertStringNotContainsString('fake-app-password-123', $logged . $res->getContent()
            . DB::table('email_outbox')->where('id', $mail)->value('last_error'));
    }

    public function test_no_network_reads_plainly_and_can_be_resent(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $mail = $this->failedEmail($id);
        $plain = "Could not reach the mail server. Check this laptop's internet connection, then try again.";
        $oldLog = ini_set('error_log', storage_path('logs/auth-tests-error.log'));   // the raw message stays out of PHPUnit's output

        $this->noNetwork();
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => false, 'message' => $plain, 'email' => ['status' => 'failed', 'error' => $plain]]);

        ini_set('error_log', $oldLog);
        $this->fakeGmail();
        $this->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => true, 'email' => ['status' => 'sent']]);
    }

    /* A laptop's real mail-config.php must never be read by a test: it would send real email. */
    public function test_the_tests_never_read_a_laptops_real_settings(): void
    {
        legacy('mailer');
        $this->assertSame('no-mail-settings-in-tests', config('venusep.mail_config'));
        $this->assertSame('log', mail_config()['transport']);
        $this->assertFalse(mail_is_set_up());
    }
}
