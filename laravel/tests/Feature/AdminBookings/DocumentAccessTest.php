<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* document-view (the bytes) and valid-id-view (the viewer page) — used by
   customers AND staff. Admins see any booking's documents, staff their own
   venue's (2026-10-31); a customer only their own; everyone else gets the
   same plain 404.

   Document 3 = booking 158's valid ID (customer 8, mesionaalexa@gmail.com).
   The test puts a tiny fake PNG at its path in THIS worktree's private
   storage copy (VENUSEP_DOC_ROOT) and removes it afterwards. */
class DocumentAccessTest extends TestCase
{
    private const OWNER = 'mesionaalexa@gmail.com';
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = getenv('VENUSEP_DOC_ROOT') . '/bookings/158/customer_id_5ef9bd3afd018835.png';
        @mkdir(dirname($this->file), 0700, true);
        file_put_contents($this->file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(dirname($this->file));
        @rmdir(dirname($this->file, 2));
        parent::tearDown();
    }

    private function assertPlain404($res): void
    {
        $res->assertNotFound()->assertSee('Not found.')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function test_guest_gets_404_from_both(): void
    {
        $this->assertPlain404($this->get(route('root.document-view', ['doc' => 3])));
        $this->assertPlain404($this->get(route('root.valid-id-view', ['doc' => 3])));
    }

    public function test_another_venues_staff_get_the_same_404(): void
    {
        $this->assertPlain404($this->actingAsStaff()->get(route('root.document-view', ['doc' => 3])));   // venue 2 booking, venue 1 staff
        $this->assertPlain404($this->actingAsStaff()->get(route('root.valid-id-view', ['booking' => 'VB-2026-158'])));
    }

    public function test_an_admin_gets_any_bookings_file_inline_with_the_safety_headers(): void
    {
        $res = $this->actingAsAdmin()->get(route('root.document-view', ['doc' => 3]));
        $res->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="customer_id-3.png"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('root.document-view', ['doc' => 3, 'download' => 1]))
            ->assertHeader('Content-Disposition', 'attachment; filename="customer_id-3.png"');
    }

    public function test_the_owner_sees_their_own_file(): void
    {
        $this->actingAsCustomer(self::OWNER)->get(route('root.document-view', ['doc' => 3]))->assertOk();
    }

    public function test_another_customer_gets_the_same_404(): void
    {
        $this->assertPlain404($this->actingAsCustomer()->get(route('root.document-view', ['doc' => 3])));
    }

    public function test_a_missing_file_or_bad_id_is_404(): void
    {
        @unlink($this->file);
        $this->assertPlain404($this->actingAsStaff()->get(route('root.document-view', ['doc' => 3])));
        $this->assertPlain404($this->get(route('root.document-view', ['doc' => 'abc'])));
        $this->assertPlain404($this->get(route('root.document-view', ['receipt' => 999999])));
    }

    public function test_viewer_page_for_staff_links_back_to_the_request(): void
    {
        $this->actingAsAdmin()->get(route('root.valid-id-view', ['booking' => 'VB-2026-158']))->assertOk()
            ->assertSee('<title>VENUSeP | Valid ID · VB-2026-158</title>', false)
            ->assertSee('src="' . route('root.document-view') . '?doc=3"', false)
            ->assertSee(json_encode(route('admin.booking-request', ['id' => 'VB-2026-158'])), false)
            ->assertSee('staff and the customer who uploaded it');
    }

    public function test_viewer_page_for_the_owner_links_back_to_their_history(): void
    {
        $this->actingAsCustomer(self::OWNER)->get(route('root.valid-id-view', ['doc' => 3]))->assertOk()
            ->assertSee(json_encode(route('customer.booking-history', ['view' => 'VB-2026-158'])), false)
            ->assertSee('you and the venue staff');
    }

    public function test_viewer_page_refuses_another_customer(): void
    {
        $this->assertPlain404($this->actingAsCustomer()->get(route('root.valid-id-view', ['booking' => 'VB-2026-158'])));
    }

    /* cacct's night-2 report: a suspended staff session could still open IDs and
       receipts here (no page guard). The EnforceTwoFactor middleware's
       staff_session_refresh() now ends that session before this controller runs. */
    public function test_a_suspended_staff_session_sees_nothing(): void
    {
        $staff = \Illuminate\Support\Facades\DB::table('staff')->where('user_id', \Illuminate\Support\Facades\DB::table('users')->where('email', 'staff@gmail.com')->value('id'));
        $staff->update(['venue_id' => 2]);   // booking 158's venue, so only the suspension refuses
        try {
            $this->actingAsStaff()->get(route('root.document-view', ['doc' => 3]))->assertOk();   // still active: allowed
            \Illuminate\Support\Facades\DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 0]);
            $this->assertPlain404($this->actingAsStaff()->get(route('root.document-view', ['doc' => 3])));
            $this->assertPlain404($this->actingAsStaff()->get(route('root.valid-id-view', ['booking' => 'VB-2026-158'])));
            $this->assertFalse(session()->has('user_id'));
        } finally {
            \Illuminate\Support\Facades\DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 1]);
            $staff->update(['venue_id' => 1]);
        }
    }

    public function test_viewer_for_a_gcash_receipt_and_a_booking_without_an_id(): void
    {
        $this->actingAsAdmin()->get(route('root.valid-id-view', ['receipt' => 1]))->assertOk()
            ->assertSee('GCash receipt')->assertSee('Sent to staff for review');
        $this->get(route('root.valid-id-view', ['booking' => 'VB-2026-190']))->assertOk()->assertSee('No ID on file');
    }
}
