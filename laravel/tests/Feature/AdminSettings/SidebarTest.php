<?php

namespace Tests\Feature\AdminSettings;

use App\Queries\BookingQueries;
use App\Queries\CoverQueries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The staff-side sidebar and header (design pass item 5): groups, full page names, the counts of
   what waits, Counter booking on every page. */
class SidebarTest extends TestCase
{
    public function test_an_admin_gets_groups_and_full_page_names_and_the_header_has_counter_booking(): void
    {
        $page = $this->actingAsAdmin()->get(route('admin.calendar'))->assertOk();
        $page->assertSeeInOrder(['Dashboard', 'Booking Management', 'Calendar', 'Transaction History', 'Venues and people', 'Venue Management',
            'Staff Management', 'Customer Management', 'Office setup', 'Office Settings', 'Payment Settings', 'FAQ Management', 'Backup &amp; Restore',
            'Records', 'Reports', 'Activity', 'Settings', 'Log Out'], false);
        $page->assertSee('class="hd-counter" href="' . route('customer.venues', ['counter' => 1]) . '"', false);
    }

    public function test_staff_get_a_plain_list_and_a_customer_keeps_their_menu(): void
    {
        $this->actingAsStaff()->get(route('admin.calendar'))->assertOk()
            ->assertDontSee('class="grp"', false)->assertDontSee(route('admin.staff'), false)->assertSee('class="hd-counter"', false);
        $this->flushSession();
        $this->actingAsCustomer()->get(route('customer.booking-history'))->assertOk()
            ->assertDontSee('class="grp"', false)->assertDontSee('class="hd-counter"', false)->assertDontSee('class="cnt"', false);
    }

    /* The one SQL count against the queue's own rows, for each kind of session. */
    public function test_the_bookings_count_is_the_queues_need_action_count(): void
    {
        legacy('bookings');
        foreach ([fn () => $this->actingAsAdmin(), fn () => $this->actingAsAdmin('cedu.admin@gmail.com'), fn () => $this->actingAsStaff()] as $signIn) {
            $this->flushSession();
            $signIn();
            $this->get(route('admin.booking-requests'))->assertOk();   // the session is live for the queries below
            $need = count(array_filter(booking_queue_rows(), fn ($r) => in_array($r['cat'], ['id', 'confirm', 'review', 'overdue', 'refund', 'pos'], true)));
            $this->assertSame($need, BookingQueries::needsAction());
        }
    }

    public function test_a_waiting_cover_counts_for_the_admin_who_answers_and_links_to_its_tab(): void
    {
        $staff = (int) DB::table('users')->where('email', 'jakemerson@gmail.com')->value('id');
        $cover = CoverQueries::createCover($staff, 1, null, date('Y-m-d H:i:s', strtotime('+1 day')), date('Y-m-d H:i:s', strtotime('+2 days')), 'test', 1, false);
        try {
            $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.calendar'))->assertOk()
                ->assertSee('href="' . route('admin.staff', ['tab' => 'covers']) . '"', false)->assertSee('aria-label="1 need you"', false);
            $this->flushSession();
            $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.calendar'))->assertOk()
                ->assertDontSee(route('admin.staff', ['tab' => 'covers']), false);   // the other group asked, it does not answer
        } finally {
            DB::table('staff_covers')->where('id', $cover)->delete();
        }
    }
}
