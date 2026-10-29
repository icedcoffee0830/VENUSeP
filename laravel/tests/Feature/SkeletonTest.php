<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/* The shared pieces every ported page stands on: the guards (ported from
   includes/auth.php), the two layouts, and the legacy bridge. */
class SkeletonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'staff'])->get('/_t/staff', fn () => 'staff ok');
        Route::middleware(['web', 'staff:admin'])->get('/_t/admin-only', fn () => 'admin ok');
        Route::middleware(['web', 'customer'])->get('/_t/customer', fn () => 'customer ok');
        Route::middleware(['web', 'booking.access'])->get('/_t/booking', fn () => 'booking ok');
        Route::middleware(['web', 'staff'])->get('/_t/admin-layout', fn () => view('_skeleton.admin-page'));
        Route::middleware(['web', 'customer'])->get('/_t/customer-layout', fn () => view('_skeleton.customer-page'));
        /* Laravel skips its CSRF check under phpunit, so the expired-page reply is exercised directly */
        Route::middleware(['web'])->post('/_t/expired', fn () => throw new \Illuminate\Session\TokenMismatchException());
        Route::middleware('web')->get('/_t/nav', fn () => view('_skeleton.nav-page'));
        Route::middleware('web')->get('/_t/legacy', function () {
            legacy('db', 'pricing', 'bookings');
            $pdo = venusep_db();
            return response()->json([
                'tz' => $pdo->query('SELECT @@session.time_zone')->fetchColumn(),
                'row_is_array' => is_array($pdo->query('SELECT 1 AS one')->fetch()),
                'gap' => venue_cleanup_gap_minutes(),
                'php_tz' => date_default_timezone_get(),
            ]);
        });
    }

    public function test_guest_is_sent_to_the_right_sign_in_page(): void
    {
        $this->get('/_t/staff')->assertRedirect(route('admin.admin-login'));
        $this->get('/_t/customer')->assertRedirect(route('customer.customer-login'));
        $this->get('/_t/booking')->assertRedirect(route('customer.customer-login'));
    }

    public function test_staff_guard_lets_staff_and_admins_in_but_not_customers(): void
    {
        $this->actingAsStaff()->get('/_t/staff')->assertOk()->assertSee('staff ok');
        $this->actingAsAdmin()->get('/_t/staff')->assertOk();
        $this->actingAsCustomer()->get('/_t/staff')->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_only_pages_send_staff_to_the_dashboard(): void
    {
        $this->actingAsStaff()->get('/_t/admin-only')->assertRedirect(route('admin.dashboard'));
        $this->actingAsAdmin()->get('/_t/admin-only')->assertOk();
    }

    public function test_an_admin_session_without_the_code_is_ended(): void
    {
        $this->withSession(['user_id' => 1, 'account_type' => 'admin'])   // no tfa_passed
            ->get('/_t/staff')->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_guard_and_counter_mode(): void
    {
        $this->actingAsCustomer()->get('/_t/customer')->assertOk()->assertSee('customer ok');
        $this->actingAsStaff()->get('/_t/customer')->assertRedirect(route('customer.customer-login'));
        $this->actingAsStaff()->get('/_t/booking?counter=1')->assertOk();   // staff at the counter
        $this->flushSession();   // a test keeps its session between requests
        $this->get('/_t/booking?counter=1')->assertRedirect(route('customer.customer-login'));   // the URL alone grants nothing
    }

    public function test_guarded_pages_are_not_cached(): void
    {
        $this->actingAsStaff()->get('/_t/staff')->assertHeader('Pragma', 'no-cache');
    }

    public function test_layouts_render_the_shared_shell(): void
    {
        $this->actingAsStaff()->get('/_t/admin-layout')->assertOk()
            ->assertSee('Booking Management')->assertSee('class="active"', false)
            ->assertSee(route('admin.booking-requests'), false)
            ->assertSee('nb-bell', false)                        // notification bell
            ->assertSee('name="csrf-token"', false)
            ->assertSee('Skeleton admin body');
        $this->actingAsCustomer()->get('/_t/customer-layout')->assertOk()
            ->assertSee('Browse Venues')->assertSee(route('customer.customer-profile'), false)
            ->assertSee('Skeleton customer body');
    }

    public function test_legacy_bridge_gives_the_old_connection_settings(): void
    {
        $this->get('/_t/legacy')->assertOk()->assertJson([
            'tz' => '+08:00', 'row_is_array' => true, 'php_tz' => 'Asia/Manila',
        ]);
    }

    public function test_customer_nav_for_a_guest_a_customer_and_the_counter(): void
    {
        $this->get('/_t/nav')->assertOk()->assertSee(route('customer.customer-login'), false)
            ->assertSee('nav body')->assertDontSee(route('customer.booking-history'), false);
        $this->actingAsCustomer()->get('/_t/nav')->assertOk()
            ->assertSee(route('customer.booking-history'), false)->assertSee(route('customer.logout'), false);
        $this->flushSession();
        $this->actingAsStaff()->get('/_t/nav?counter=1')->assertOk()->assertSee(route('admin.dashboard'), false);
    }

    public function test_an_expired_page_gets_the_old_json_replies(): void
    {
        $this->actingAsStaff()->post('/_t/expired', [], ['Accept' => '*/*'])->assertStatus(400)
            ->assertJson(['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        $this->flushSession();
        $this->post('/_t/expired', [], ['Accept' => '*/*'])->assertStatus(401)->assertJson(['ok' => false, 'error' => 'not_logged_in']);
        $this->post('/_t/expired', [], ['Accept' => 'text/html'])->assertStatus(419);   // a form post: Laravel's page
    }
}
