<?php

namespace Tests\Feature\Email;

use App\Queries\EmailQueries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Only one laptop of the group holds the Gmail settings (Aron 2026-11-26: nobody copies the
   file). An email queued on a laptop without them is left 'not_sent'; a laptop with them sends
   it (mail_send_waiting(), after its pages and every minute), once. */
class WaitingEmailsTest extends TestCase
{
    use FakeMail;

    private int $last = 0;

    protected function setUp(): void
    {
        parent::setUp();
        legacy('receipt-emails');
        $this->last = (int) DB::table('email_outbox')->max('id');
    }

    protected function tearDown(): void
    {
        $this->stopFakeMail();
        DB::table('email_outbox')->where('id', '>', $this->last)->delete();
        parent::tearDown();
    }

    private function leftBehind(string $kind, string $age = '0 MINUTE'): int
    {
        $id = mail_enqueue(['kind' => $kind, 'to_email' => 'waiting.test@example.test', 'subject' => 'Waiting ' . $kind,
            'body_html' => '<p>hello</p>', 'body_text' => 'hello']);
        EmailQueries::markNotSent($id, MAIL_NOT_SET_UP);   // as a laptop without mail settings leaves it
        DB::update("UPDATE email_outbox SET created_at = NOW() - INTERVAL $age WHERE id = ?", [$id]);
        return $id;
    }

    public function test_a_laptop_with_mail_settings_sends_what_another_one_could_not_and_only_once(): void
    {
        $this->assertSame(0, mail_send_waiting(), 'this laptop has no settings either: it leaves them all');

        $reset = $this->leftBehind('password_reset');
        $old = $this->leftBehind('password_reset', '45 MINUTE');   // its link has run out
        $approved = $this->leftBehind('booking_approved', '3 HOUR');

        $this->fakeGmail('accept');
        $this->assertGreaterThanOrEqual(2, mail_send_waiting());   // the test database may hold others left by other tests
        $this->assertSame('sent', EmailQueries::find($reset)['status']);
        $this->assertSame('sent', EmailQueries::find($approved)['status']);
        $this->assertSame('not_sent', EmailQueries::find($old)['status'], 'a reset link older than 30 minutes is left');

        $this->assertSame(0, mail_send_waiting(), 'nothing is sent twice');
        $this->assertFalse(EmailQueries::claimForSending($reset, MAIL_NOT_SET_UP), 'a sent email cannot be claimed again');
    }
}
