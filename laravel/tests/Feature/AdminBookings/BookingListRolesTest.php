<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Who sees which bookings on Booking Management (DB-DECISIONS 28), pinned against a plain
   count of the bookings table: the teammate's "the super admin's list shows nothing" and
   "hostel staff don't see everything" (2026-11-05). The page leaves out only bookings that
   are finished and paid. An admin with no group sees nothing: staff_me() gives it no office.
   The page is loaded before the count: every request runs the deadline housekeeping first. */
class BookingListRolesTest extends TestCase
{
    private const BAHAY = 1;
    private const VENUES = 2;
    private const HOSTEL = 3;

    /* The ids the page should list for these offices. */
    private function expected(array $offices): array
    {
        $in = implode(',', $offices);
        $ids = array_map(fn ($r) => (int) $r->id, DB::select(
            "SELECT b.id FROM bookings b JOIN rooms r ON r.id = b.room_id
              WHERE r.venue_id IN ($in)
                AND NOT (b.reservation_status = 'completed' AND b.payment_status IN ('confirmed', 'paid_cash'))"
        ));
        sort($ids);
        return $ids;
    }

    private function listed(): array
    {
        return $this->get(route('admin.booking-requests'))->assertOk()->viewData('brRows');
    }

    private function ids(array $rows): array
    {
        $ids = array_map(fn ($r) => (int) $r['seq'], $rows);
        sort($ids);
        return $ids;
    }

    public function test_the_super_admin_lists_every_office(): void
    {
        $this->actingAsAdmin();
        $rows = $this->listed();
        $this->assertNotEmpty($rows);
        $this->assertSame($this->expected([self::BAHAY, self::VENUES, self::HOSTEL]), $this->ids($rows));
    }

    public function test_the_cedu_admin_lists_usep_venues_and_the_hostel(): void
    {
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $rows = $this->listed();
        $this->assertSame($this->expected([self::VENUES, self::HOSTEL]), $this->ids($rows));
    }

    public function test_the_bahay_admin_lists_bahay_alumni_only(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');
        $rows = $this->listed();
        $this->assertSame($this->expected([self::BAHAY]), $this->ids($rows));
    }

    /* Hostel staff cover the whole hostel, so My halls leaves nothing out either. */
    public function test_hostel_staff_list_the_whole_hostel_and_every_row_is_theirs(): void
    {
        $this->assertSame(self::HOSTEL, (int) DB::table('staff as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('u.email', 'jakemerson@gmail.com')->value('s.venue_id'));
        $this->actingAsStaff('jakemerson@gmail.com');
        $rows = $this->listed();
        $this->assertNotEmpty($rows);
        $this->assertSame($this->expected([self::HOSTEL]), $this->ids($rows));
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => !$r['mine'])));
    }

    public function test_an_admin_with_no_group_lists_nothing(): void
    {
        $email = 'francishubero@gmail.com';
        $group = DB::table('users')->where('email', $email)->value('office_group');
        $this->beforeApplicationDestroyed(fn () => DB::table('users')->where('email', $email)->update(['office_group' => $group]));
        DB::table('users')->where('email', $email)->update(['office_group' => null]);
        $this->actingAsAdmin($email);
        $this->assertSame([], $this->listed());
    }
}
