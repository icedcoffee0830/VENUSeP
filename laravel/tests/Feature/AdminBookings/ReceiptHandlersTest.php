<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The Transaction Receipt (page and PDF lines) and the "View" window name who handled the
   booking: who approved it and who confirmed the payment. A name that is not known is left out. */
class ReceiptHandlersTest extends TestCase
{
    use SeedsBookings;

    private function act(array $data)
    {
        return $this->postWithToken(route('admin.booking-action'), $data);
    }

    public function test_the_receipt_and_the_view_window_name_who_approved_and_who_confirmed(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'cash', false, true);   // venue 1: staff@gmail.com's
        $this->actingAsStaff()->act(['booking' => $ref, 'action' => 'approve'])->assertOk()->assertJson(['ok' => true]);
        $this->act(['booking' => $ref, 'action' => 'confirm_payment'])->assertOk()->assertJson(['ok' => true]);

        /* frozen on the new receipt */
        $snapshot = json_decode(DB::table('system_receipts')->where('booking_id', $id)->value('snapshot_json'), true);
        $this->assertSame('Marites Robles', $snapshot['approved_by']);
        $this->assertSame('Marites Robles', $snapshot['payment_confirmed_by']);

        $this->get(route('admin.receipt', ['booking' => $ref]))->assertOk()
            ->assertSee('<div><span>Approved by</span>Marites Robles</div>', false)
            ->assertSee('<div><span>Payment confirmed by</span>Marites Robles</div>', false);
        /* the PDF draws these same lines */
        $paidBy = receipt_view(receipt_for_booking($id))['paid_by'];
        $this->assertContains(['Approved by', 'Marites Robles'], $paidBy);
        $this->assertContains(['Payment confirmed by', 'Marites Robles'], $paidBy);

        $this->flushSession();
        $this->actingAsCustomer()->get(route('customer.receipt', ['booking' => $ref]))->assertOk()
            ->assertSee('<div><span>Approved by</span>Marites Robles</div>', false)
            ->assertSee('<div><span>Payment confirmed by</span>Marites Robles</div>', false);
        $this->getJson(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.money.approvedBy', 'Marites Robles')
            ->assertJsonPath('details.money.confirmedBy', 'Marites Robles');
    }

    /* A receipt issued before the names were kept looks them up; one nobody can name is left out. */
    public function test_an_older_receipt_looks_the_names_up_and_leaves_out_unknown_ones(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_cash', 'cash', false, true);   // approved with no History line
        $this->actingAsAdmin()->act(['booking' => $ref, 'action' => 'confirm_payment'])->assertOk()->assertJson(['ok' => true]);
        DB::update("UPDATE system_receipts SET snapshot_json = JSON_REMOVE(snapshot_json, '$.approved_by', '$.payment_confirmed_by') WHERE booking_id = ?", [$id]);
        $admin = DB::table('staff as s')->join('users as u', 'u.id', '=', 's.user_id')->where('u.email', 'admin@gmail.com')->value('s.full_name');

        $this->get(route('admin.receipt', ['booking' => $ref]))->assertOk()
            ->assertSee('<div><span>Payment confirmed by</span>' . e($admin) . '</div>', false)
            ->assertDontSee('Approved by');

        DB::update('UPDATE payments SET confirmed_by_user_id = NULL WHERE booking_id = ?', [$id]);
        DB::update('UPDATE system_receipts SET issued_by_user_id = NULL WHERE booking_id = ?', [$id]);
        $this->get(route('admin.receipt', ['booking' => $ref]))->assertOk()
            ->assertSee('Amount paid')->assertDontSee('Payment confirmed by')->assertDontSee('Approved by')->assertDontSee('Unknown');
        $this->getJson(route('root.booking-details', ['booking' => $ref]))->assertOk()
            ->assertJsonPath('details.money.approvedBy', '')->assertJsonPath('details.money.confirmedBy', '');
    }
}
