<?php

namespace Tests\Feature\Email;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Forgot password with real sending on (through the pretend server), and with none. */
class PasswordResetEmailTest extends TestCase
{
    use FakeMail;
    use MakesAccounts { tearDown as accountsTearDown; }

    private array $logFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        legacy('password-reset');
    }

    protected function tearDown(): void
    {
        $this->stopFakeMail();
        foreach ($this->logFiles as $file) {
            @unlink($file);
        }
        $this->accountsTearDown();
    }

    private function logFile(int $outboxId): string
    {
        return $this->logFiles[] = doc_ensure_dir('mail-log') . '/' . $outboxId . '.eml';
    }

    private function outboxId(object $acct): int
    {
        return (int) DB::table('email_outbox')->where('requested_by_user_id', $acct->id)->orderByDesc('id')->value('id');
    }

    private function queueWelcome(object $acct, string $to): int
    {
        return mail_enqueue(['kind' => 'counter_welcome', 'requested_by_user_id' => $acct->id, 'to_email' => $to,
            'subject' => 'Test', 'body_html' => '<p>Test</p>', 'body_text' => 'Test']);
    }

    public function test_with_settings_the_link_arrives_and_opens_the_reset_page(): void
    {
        $this->fakeGmail('accept', ['base_url' => 'http://localhost']);
        $acct = $this->makeAccount('customer');

        $this->post(route('customer.forgot-password'), ['email' => $acct->email])->assertRedirect(route('customer.forgot-password'));
        $this->get(route('customer.forgot-password'))->assertSee('Check your email')->assertSee('If that email belongs to a VENUSeP account');

        $mail = DB::table('email_outbox')->where('id', $this->outboxId($acct))->first();
        $this->assertSame('sent', $mail->status);
        $this->assertStringNotContainsString('reset-password?token=', $mail->body_text);
        $this->assertFileDoesNotExist($this->logFile($mail->id));   // the link went out and stayed nowhere

        $this->assertSame(1, preg_match('~http://localhost(/customer/reset-password\?token=[A-Za-z0-9_-]{43})~', $this->fakeInbox(), $link));
        $this->followingRedirects()->get($link[1])->assertOk()->assertSee('Choose a new password');
    }

    public function test_the_first_real_send_removes_reset_links_from_the_mail_log_and_keeps_the_rest(): void
    {
        $acct = $this->makeAccount('customer');
        pr_request(venusep_db(), $acct->email, '127.0.0.1');   // no settings: the link's only copy is the mail-log
        $resetFile = $this->logFile($this->outboxId($acct));
        $this->assertFileExists($resetFile);

        $otherId = $this->queueWelcome($acct, 'other@example.test');
        mail_send($otherId);
        $otherFile = $this->logFile($otherId);
        $this->assertFileExists($otherFile);
        $this->assertFileExists($resetFile);   // still no real sending

        $this->fakeGmail();
        $this->assertTrue(mail_send($this->queueWelcome($acct, 'sent@example.test')));

        $this->assertFileDoesNotExist($resetFile);
        $this->assertFileExists($otherFile);
    }

    public function test_with_no_settings_the_page_never_says_a_link_was_sent_for_any_address(): void
    {
        $acct = $this->makeAccount('customer');
        $replies = [];
        foreach ([$acct->email, 'nobody.here@example.test'] as $email) {
            $this->post(route('customer.forgot-password'), ['email' => $email])->assertRedirect(route('customer.forgot-password'));
            $replies[] = $this->get(route('customer.forgot-password'))->assertSee('email isn’t set up on this laptop')
                ->assertDontSee('we’ve sent')->assertDontSee('Check your email')->getContent();
        }
        $strip = fn ($html) => preg_replace('/name="_token" value="[^"]*"|content="[^"]*"/', '', $html);
        $this->assertSame($strip($replies[0]), $strip($replies[1]));   // the page still cannot test who has an account

        $id = $this->outboxId($acct);
        $this->assertSame('not_sent', DB::table('email_outbox')->where('id', $id)->value('status'));
        $this->logFile($id);
    }
}
