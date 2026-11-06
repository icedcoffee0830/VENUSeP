<?php

namespace Tests\Feature;

use App\Support\Status;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Design pass items 8a to 8e: the small site-wide fixes. */
class DesignPassTest extends TestCase
{
    public function test_every_shell_page_loads_the_real_weights_of_inter(): void
    {
        $page = $this->actingAsAdmin()->get(route('admin.calendar'))->assertOk();
        foreach ([500, 600, 700] as $weight) {
            $page->assertSee('@fontsource/inter@5/' . $weight . '.css', false);
        }
    }

    /* Text colours under 4.5:1 on white. A dot or a border may keep its colour; reports are the teammate's. */
    public function test_no_text_uses_the_greys_and_warning_colours_that_fail_contrast(): void
    {
        $files = new \AppendIterator();
        $files->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))));
        $files->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path('assets/css'))));
        foreach ($files as $file) {
            if (!preg_match('/\.(php|css)$/', $file->getFilename()) || $file->getFilename() === 'reports.blade.php') continue;
            $this->assertDoesNotMatchRegularExpression('/#8a857d|#8a8680|#a3a09a|[^-a-z]color: ?#(ff6b12|2f9e63)/i', file_get_contents($file->getPathname()), $file->getFilename());
        }
    }

    public function test_every_status_word_has_its_chip(): void
    {
        foreach (['Refund processing' => 'amber', 'Action needed' => 'amber', 'Locked' => 'grey', 'Completed' => 'grey', 'Refunded' => 'grey',
                  'Paid' => 'green', 'Overdue' => 'red', 'Something new' => 'grey'] as $word => $tone) {
            $this->assertSame('vz-chip vz-chip-' . $tone, Status::chipClass($word), $word);
        }
        $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()->assertSee('booking-badge vz-chip vz-chip-', false);
    }

    /* The decision used to vanish on reload: the page read discountPct, the row said discountPercent. */
    public function test_the_discount_decision_is_still_there_after_a_reload(): void
    {
        $booking = DB::selectOne("SELECT id, YEAR(submitted_at) AS y, discount_percent AS pct FROM bookings WHERE reservation_status = 'approved' AND discount_amount > 0 ORDER BY id LIMIT 1");
        $this->assertNotNull($booking, 'the snapshot has no approved booking with a discount');
        $ref = sprintf('VB-%d-%03d', $booking->y, $booking->id);
        $this->actingAsAdmin()->get(route('admin.booking-request', ['id' => $ref]))->assertOk()
            ->assertSee('"discountPct":' . (float) $booking->pct, false);

        $rejected = DB::selectOne("SELECT id, YEAR(submitted_at) AS y FROM bookings WHERE reservation_status = 'rejected' ORDER BY id LIMIT 1");
        $this->get(route('admin.booking-request', ['id' => sprintf('VB-%d-%03d', $rejected->y, $rejected->id)]))->assertOk()
            ->assertSee('"discountPct":null', false);
    }

    public function test_the_words_say_what_each_box_does_and_the_phone_bar_is_crimson(): void
    {
        $this->actingAsAdmin();
        $this->get(route('admin.staff'))->assertOk()->assertSee('Search name or email…')->assertSee('content="#7d1120"', false)
            ->assertDontSee('Filter rows')->assertDontSee('breadcrumb');
        $this->get(route('admin.customer-management'))->assertOk()->assertSee('Name, email or phone…')->assertSee('content="#7d1120"', false)
            ->assertDontSee('Filter rows')->assertDontSee('breadcrumb');
        $this->get(route('admin.transaction-history'))->assertOk()->assertSee('Search customer, venue or booking no.…')->assertDontSee('Filter rows');
        $this->flushSession();
        $this->actingAsCustomer()->get(route('customer.transaction-history'))->assertOk()->assertSee('Venue or booking no.…')
            ->assertSee('No payments yet. When you pay for a booking, it shows here with its receipt.')->assertSee('No payments match these filters.');
    }
}
