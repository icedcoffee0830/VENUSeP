<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Counter mode by office (DB-DECISIONS 28): the counter's start page shows only the
   viewer's offices, and another office's booking page is refused before it is drawn.
   Offices: 1 Bahay Alumni (r1), 2 USeP Venues (r5), 3 USeP Hostel (h1). */
class CounterOfficeTest extends TestCase
{
    use MakesAccounts;

    private function signInRole(string $role): static
    {
        return match ($role) {
            'super'     => $this->actingAsAdmin(),
            'bahay_na'  => $this->actingAsAdmin('francishubero@gmail.com'),
            'cedu_na'   => $this->actingAsAdmin('cedu.admin@gmail.com'),
            'venues'    => $this->signInAs($this->staffAt(2), 'staff'),
            'hostel'    => $this->signInAs($this->staffAt(3), 'staff'),
            /* staff always have an office (a trigger); an admin left without a group has none */
            'no_office' => $this->signInAs($this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']), 'admin'),
        };
    }

    private function staffAt(int $venue): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        return $acct;
    }

    /* role, the heading, which office sections are drawn [Bahay, Venues, Hostel] */
    public static function roles(): array
    {
        return [
            'super admin'        => ['super', 'Three places on campus', [true, true, true]],
            'Bahay Alumni admin' => ['bahay_na', 'One place on campus', [true, false, false]],
            'USeP CEDU admin'    => ['cedu_na', 'Two places on campus', [false, true, true]],
            'Venues staff'       => ['venues', 'One place on campus', [false, true, false]],
            'Hostel staff'       => ['hostel', 'One place on campus', [false, false, true]],
        ];
    }

    #[DataProvider('roles')]
    public function test_the_counter_start_page_shows_only_the_viewers_offices(string $role, string $heading, array $shown): void
    {
        $html = $this->signInRole($role)->get(route('customer.venues', ['counter' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString($heading, $html);
        foreach (['id="venue-bahay-alumni"', 'id="venue-usep-venues"', 'id="hostel-listings"'] as $i => $section) {
            $this->assertSame($shown[$i], str_contains($html, $section), "$role: $section");
        }
        $this->assertSame($shown[2], str_contains($html, 'customer/hostel-reservation?room=h1'), "$role: hostel room card");
        $this->assertStringNotContainsString('You have no office yet', $html);
    }

    public function test_no_office_is_told_to_ask_an_admin(): void
    {
        $html = $this->signInRole('no_office')->get(route('customer.venues', ['counter' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('You have no office yet. Ask an admin to assign one.', $html);
        $this->assertStringNotContainsString('id="hostel-listings"', $html);
        $this->assertStringNotContainsString('room-reservation?room=', $html);
    }

    public function test_the_public_page_is_unchanged(): void
    {
        $html = $this->get(route('customer.venues'))->assertOk()->getContent();

        $this->assertStringContainsString('Three places on campus', $html);
        $this->assertStringContainsString('Alumni halls, university venues and hostel beds', $html);
        $this->assertStringContainsString('id="hostel-listings"', $html);
        $this->assertStringNotContainsString('You have no office yet', $html);
    }

    /* role, room page, room, allowed */
    public static function directUrls(): array
    {
        return [
            'Venues staff, Bahay Alumni room' => ['venues', 'customer.room-reservation', 'r1', false],
            'Venues staff, hostel room'       => ['venues', 'customer.hostel-reservation', 'h1', false],
            'Venues staff, own room'          => ['venues', 'customer.room-reservation', 'r5', true],
            'Bahay admin, Venues room'        => ['bahay_na', 'customer.room-reservation', 'r5', false],
            'CEDU admin, hostel room'         => ['cedu_na', 'customer.hostel-reservation', 'h1', true],
            'CEDU admin, Bahay Alumni room'   => ['cedu_na', 'customer.room-reservation', 'r1', false],
            'no office, any room'             => ['no_office', 'customer.room-reservation', 'r1', false],
            'super admin, any room'           => ['super', 'customer.hostel-reservation', 'h1', true],
        ];
    }

    #[DataProvider('directUrls')]
    public function test_another_offices_booking_page_is_refused_up_front(string $role, string $page, string $room, bool $allowed): void
    {
        $res = $this->signInRole($role)->get(route($page, ['room' => $room, 'counter' => 1]));
        if ($allowed) {
            $res->assertOk();
            return;
        }
        $res->assertRedirect(route('customer.venues') . '?counter=1');
        $this->assertStringContainsString(
            $role === 'no_office' ? 'You have no office yet' : 'That room is not one you handle. At the counter you can book your own rooms only.',
            $this->get($res->headers->get('Location'))->getContent()
        );
    }

    public function test_a_customer_still_opens_any_room(): void
    {
        $this->actingAsCustomer()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk();
    }
}
