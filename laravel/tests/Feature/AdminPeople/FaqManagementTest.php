<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/faq-management.php + admin/faq-save.php */
class FaqManagementTest extends TestCase
{
    public function test_guest_is_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.faq-management'))->assertRedirect(route('admin.admin-login'));
        $this->post(route('admin.faq-save'), ['action' => 'toggle', 'id' => 1])->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_the_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.faq-management'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_sees_every_section_with_live_values_and_working_links(): void
    {
        $pct = (int) DB::table('system_settings')->where('setting_key', 'discount_percent')->value('setting_value') ?: 20;
        $this->actingAsStaff()->get(route('admin.faq-management'))->assertOk()
            ->assertSee('FAQ Management')
            ->assertSee('Add a question')
            ->assertSee('Paying with GCash')
            ->assertSee('Who gets the ' . $pct . '% off?', false)                         // {discount} filled in
            ->assertSee('href="' . route('customer.booking-history') . '"', false)      // display link -> the route
            ->assertSee('href="../customer/booking-history.php"', false)                // the editor keeps the stored form
            ->assertSee('name="_token"', false)
            ->assertSee('action="' . route('admin.faq-save') . '"', false);
    }

    public function test_admin_sees_the_page_and_a_flash_message(): void
    {
        $this->actingAsAdmin()->get(route('admin.faq-management', ['msg' => 'builtin']))->assertOk()
            ->assertSee('Built-in questions cannot be deleted — hide it instead.');
    }

    public function test_add_then_delete_an_added_question(): void
    {
        $this->actingAsStaff();
        $res = $this->post(route('admin.faq-save'), [
            'action' => 'add', 'section' => 'cash', 'policy' => 'refunds_on',
            'question' => 'Port test question?', 'answer' => "Line one\r\n- a\r\n- b",
        ]);
        $res->assertRedirect(route('admin.faq-management') . '?msg=added&show=refunds_on#cash');
        $row = DB::table('faqs')->where('question', 'Port test question?')->first();
        $this->assertNotNull($row);
        $this->assertSame("Line one\n- a\n- b", $row->answer);
        $this->assertSame(0, (int) $row->is_builtin);
        $max = DB::table('faqs')->where('section_key', 'cash')->max('sort_order');
        $this->assertSame((int) $max, (int) $row->sort_order);

        $this->post(route('admin.faq-save'), ['action' => 'delete', 'id' => $row->id])
            ->assertRedirect(route('admin.faq-management') . '?msg=deleted');
        $this->assertNull(DB::table('faqs')->where('id', $row->id)->first());
    }

    public function test_validation_and_refusals(): void
    {
        $this->actingAsStaff();
        $this->post(route('admin.faq-save'), ['action' => 'add', 'section' => 'cash', 'question' => '', 'answer' => 'x'])
            ->assertRedirect(route('admin.faq-management') . '?msg=question&keep=1');
        $this->post(route('admin.faq-save'), ['action' => 'add', 'section' => 'nope', 'question' => 'q', 'answer' => 'x'])
            ->assertRedirect(route('admin.faq-management') . '?msg=section');
        $this->post(route('admin.faq-save'), ['action' => 'delete', 'id' => 26])          // built-in
            ->assertRedirect(route('admin.faq-management') . '?msg=builtin');
        $this->assertNotNull(DB::table('faqs')->where('id', 26)->first());
        $this->get(route('admin.faq-save'))->assertRedirect(route('admin.faq-management') . '?msg=method');
        $this->post(route('admin.faq-save'), ['action' => 'bogus'])->assertRedirect(route('admin.faq-management') . '?msg=action');
    }

    /* Moving a question to another section by editing it: it kept its old position
       number, so it shared a sort_order with a row of the new section and the
       Up/Down arrows could not order the two. It now goes to the end of the new section. */
    public function test_editing_a_question_into_another_section_puts_it_last_there(): void
    {
        $this->actingAsStaff();
        $this->post(route('admin.faq-save'), ['action' => 'add', 'section' => 'cash', 'policy' => 'any',
            'question' => 'Section move test?', 'answer' => 'x']);
        $id = DB::table('faqs')->where('question', 'Section move test?')->value('id');
        try {
            $this->post(route('admin.faq-save'), ['action' => 'edit', 'id' => $id, 'section' => 'after', 'policy' => 'any',
                'question' => 'Section move test?', 'answer' => 'y'])
                ->assertRedirect(route('admin.faq-management') . '?msg=saved&show=any#after');
            $row = DB::table('faqs')->where('id', $id)->first();
            $this->assertSame('after', $row->section_key);
            $othersMax = (int) DB::table('faqs')->where('section_key', 'after')->where('id', '<>', $id)->max('sort_order');
            $this->assertSame($othersMax + 1, (int) $row->sort_order);

            // an edit that stays in its section keeps its place
            $this->post(route('admin.faq-save'), ['action' => 'edit', 'id' => $id, 'section' => 'after', 'policy' => 'any',
                'question' => 'Section move test?', 'answer' => 'z']);
            $this->assertSame($othersMax + 1, (int) DB::table('faqs')->where('id', $id)->value('sort_order'));
        } finally {
            DB::table('faqs')->where('id', $id)->delete();
        }
    }

    /* A second click on Delete (or another admin deleting it first) is not a "built-in"
       refusal, and saving or hiding a question that is gone is not "saved". */
    public function test_a_question_that_no_longer_exists_says_so(): void
    {
        $this->actingAsStaff();
        foreach (['delete', 'toggle'] as $action) {
            $this->post(route('admin.faq-save'), ['action' => $action, 'id' => 999999])
                ->assertRedirect(route('admin.faq-management') . '?msg=missing');
        }
        $this->post(route('admin.faq-save'), ['action' => 'edit', 'id' => 999999, 'section' => 'cash', 'policy' => 'any',
            'question' => 'Gone?', 'answer' => 'x'])
            ->assertRedirect(route('admin.faq-management') . '?msg=missing');
        $this->assertNull(DB::table('faqs')->where('question', 'Gone?')->first());
    }

    /* The answer format allows "[text](page.php)" and http(s) links (faqs.php). Typed
       HTML stays text on the customer page, and no other scheme becomes a link. */
    public function test_answers_link_http_pages_and_never_run_typed_html(): void
    {
        $id = DB::table('faqs')->insertGetId(['section_key' => 'cash', 'question' => 'Link test <b>q</b>?', 'policy' => 'any', 'sort_order' => 99,
            'answer' => "See [the USeP site](https://www.usep.edu.ph/about) or [bad](javascript:alert(1)).\n<script>alert(2)</script> <img src=x onerror=alert(3)>"]);
        try {
            $this->get(route('customer.faq'))->assertOk()
                ->assertSee('<a href="https://www.usep.edu.ph/about">the USeP site</a>', false)
                ->assertDontSee('href="javascript:', false)
                ->assertDontSee('<script>alert(2)', false)
                ->assertDontSee('<img src=x', false)
                ->assertDontSee('<b>q</b>', false)
                ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false);
        } finally {
            DB::table('faqs')->where('id', $id)->delete();
        }
    }

    public function test_toggle_twice_and_move_round_trip(): void
    {
        $this->actingAsStaff();
        $before = DB::table('faqs')->where('id', 26)->value('is_active');
        $this->post(route('admin.faq-save'), ['action' => 'toggle', 'id' => 26])->assertRedirect(route('admin.faq-management') . '?msg=toggled');
        $this->assertNotSame((int) $before, (int) DB::table('faqs')->where('id', 26)->value('is_active'));
        $this->post(route('admin.faq-save'), ['action' => 'toggle', 'id' => 26]);
        $this->assertSame((int) $before, (int) DB::table('faqs')->where('id', 26)->value('is_active'));

        $first = DB::table('faqs')->where('section_key', 'after')->orderBy('sort_order')->orderBy('id')->first();
        $this->post(route('admin.faq-save'), ['action' => 'move', 'id' => $first->id, 'dir' => 'down'])
            ->assertRedirect(route('admin.faq-management') . '?msg=moved#after');
        $this->assertGreaterThan((int) $first->sort_order, (int) DB::table('faqs')->where('id', $first->id)->value('sort_order'));
        $this->post(route('admin.faq-save'), ['action' => 'move', 'id' => $first->id, 'dir' => 'up']);
        $this->assertSame((int) $first->sort_order, (int) DB::table('faqs')->where('id', $first->id)->value('sort_order'));
    }
}
