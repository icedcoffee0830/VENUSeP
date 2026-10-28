<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/booking-emails — the Receipt & emails panel's JSON + Resend. */
class BookingEmailsTest extends TestCase
{
    use SeedsBookings;

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

    public function test_bad_reference_is_400(): void
    {
        $this->actingAsStaff()->get(route('admin.booking-emails', ['booking' => 'x']))
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Invalid booking reference.']);
    }

    public function test_resend_needs_the_token(): void
    {
        $this->actingAsStaff()->post(route('admin.booking-emails'), ['booking' => 'VB-2026-190', 'action' => 'resend', 'id' => 1])
            ->assertStatus(400)->assertJson(['message' => 'This page has expired. Reload it and try again.']);
    }

    public function test_staff_cannot_resend_another_venues_email(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r5');
        $mail = $this->failedEmail($id);
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertStatus(403);
    }

    public function test_resend_goes_to_the_mail_log_and_marks_it_sent(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1');
        $mail = $this->failedEmail($id);
        $this->actingAsStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Sent.', 'email' => ['id' => $mail, 'status' => 'sent']]);
        $this->assertSame('sent', DB::table('email_outbox')->where('id', $mail)->value('status'));
        // the 'log' transport (no mail-config.php): nothing left the machine
        $eml = getenv('VENUSEP_DOC_ROOT') . '/mail-log/' . $mail . '.eml';
        $this->assertFileExists($eml);
        @unlink($eml);
        // a sent email cannot be resent
        $this->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend', 'id' => $mail])
            ->assertStatus(409)->assertJson(['message' => 'That email was already sent, or is still on its way.']);
    }
}
