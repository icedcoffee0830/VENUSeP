<?php

namespace Tests\Feature\Email;

use App\Queries\EmailQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Every email kind in MAIL_KINDS, through the pretend mail server: it queues, its links
   are the settings' base_url and real pages, and redirect_all_to takes it with the real
   recipient in the subject. A new kind with no case here fails the test. */
class EmailKindsTest extends TestCase
{
    use FakeMail;
    use MakesAccounts { tearDown as accountsTearDown; }

    private const BOOKING = 7;      // a seeded paid booking, so it can have a receipt
    private const BASE = 'https://mail-test.example.test';
    private const TEAM = 'team.inbox@example.test';
    private const WALK_IN = 'walk.in@example.test';

    private int $lastOutbox = 0;
    private int $lastReceipt = 0;
    private int $realCustomer = 0;
    private int $walkIn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        legacy('password-reset', 'system-receipt');
        $this->lastOutbox = (int) DB::table('email_outbox')->max('id');
        $this->lastReceipt = (int) DB::table('system_receipts')->max('id');
        $this->realCustomer = (int) DB::table('bookings')->where('id', self::BOOKING)->value('customer_id');
        $this->walkIn = (int) DB::table('customers')->insertGetId(['user_id' => null, 'full_name' => 'Walk In Guest',
            'contact_email' => self::WALK_IN, 'phone' => '09170001111']);
    }

    protected function tearDown(): void
    {
        $this->stopFakeMail();
        $this->bookingBelongsTo($this->realCustomer);
        DB::table('customers')->where('id', $this->walkIn)->delete();
        DB::table('email_outbox')->where('id', '>', $this->lastOutbox)->delete();
        DB::table('system_receipts')->where('id', '>', $this->lastReceipt)->delete();
        $this->accountsTearDown();
    }

    private function bookingBelongsTo(int $customerId): void
    {
        DB::table('bookings')->where('id', self::BOOKING)->update(['customer_id' => $customerId]);
    }

    /* Queue one email of this kind the way the site does. Returns [outbox id, real recipient]. */
    private function queue(string $kind): array
    {
        $this->bookingBelongsTo(str_starts_with($kind, 'walkin_') ? $this->walkIn : $this->realCustomer);

        switch ($kind) {
            case 'walkin_booking':
                return [queue_walkin_booking(self::BOOKING, 1), self::WALK_IN];
            case 'walkin_receipt':
                return [queue_walkin_receipt(self::BOOKING, receipt_issue(self::BOOKING, 1), 1), self::WALK_IN];
            case 'receipt_copy':
                return [queue_receipt_copy(receipt_issue(self::BOOKING, 1), 1, 'copy.kind@example.test', 'Kind Tester'), 'copy.kind@example.test'];
            case 'password_reset':
                $acct = $this->makeAccount('customer');
                pr_request(venusep_db(), $acct->email, '127.0.0.1');
                return [(int) DB::table('email_outbox')->where('requested_by_user_id', $acct->id)->value('id'), $acct->email];
            case 'booking_approved':
            case 'booking_rejected':
            case 'payment_due':
            case 'payment_overdue':
            case 'booking_cancelled':
            case 'booking_block_cancelled':
                return [queue_status_email(self::BOOKING, $kind, 'A note from the office.', 1), EmailQueries::findNameAndEmail(self::BOOKING)['email']];
            case 'super_admin_handover':
                $ids = queue_handover_emails(['email' => 'old.head@example.test', 'name' => 'Old Head'],
                    ['email' => 'new.head@example.test', 'name' => 'New Head'], 'Test reason', 1);
                return [$ids[1], 'new.head@example.test'];
            case 'counter_welcome':
                return [queue_counter_welcome('Lorna Bautista', 'lorna.kind@example.test', office_by_id(1), 1), 'lorna.kind@example.test'];
        }
        $this->fail('No case for the email kind ' . $kind);
    }

    public function test_every_kind_queues_links_to_real_pages_and_goes_to_the_test_inbox(): void
    {
        $this->fakeGmail('accept', ['redirect_all_to' => self::TEAM, 'base_url' => self::BASE]);
        $config = mail_config();
        $links = [];

        foreach (array_keys(MAIL_KINDS) as $kind) {
            $before = strlen((string) file_get_contents($this->fakeCapture));
            [$id, $recipient] = $this->queue($kind);
            $this->assertNotNull($id, $kind);
            $row = EmailQueries::find($id);
            $this->assertSame($kind, $row['kind']);
            $this->assertSame($recipient, $row['to_email'], $kind);

            $this->assertTrue(mail_send($id), $kind . ': ' . EmailQueries::find($id)['last_error']);
            $this->assertSame('sent', EmailQueries::find($id)['status'], $kind);

            $built = mail_build($row, $config);
            $this->assertSame('[TEST → ' . $recipient . '] ' . $row['subject'], $built->Subject, $kind);
            $this->assertSame([self::TEAM], array_column($built->getToAddresses(), 0), $kind);

            $taken = quoted_printable_decode(substr((string) file_get_contents($this->fakeCapture), $before));
            $this->assertStringContainsString('RCPT TO:<' . self::TEAM . '>', $taken, $kind);
            $this->assertStringNotContainsString('RCPT TO:<' . $recipient . '>', $taken, $kind);

            preg_match_all('~https?://[^\s"\'<>]+~', preg_replace('/^X-Mailer:.*$/m', '', $taken), $found);
            foreach (array_unique($found[0]) as $link) {
                $link = rtrim(html_entity_decode($link), '.,');
                $this->assertStringStartsWith(self::BASE . '/', $link, $kind);
                $links[$kind][] = $link;
                // a real page: the router throws for any other address
                $this->assertNotNull(Route::getRoutes()->match(Request::create($link)), $link);
            }
        }

        $this->assertSame(self::BASE . '/customer/forgot-password', $links['counter_welcome'][0]);
        $this->assertStringStartsWith(self::BASE . '/customer/reset-password?token=', $links['password_reset'][0]);
    }
}
