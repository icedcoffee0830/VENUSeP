<?php

namespace Tests\Feature\CustomerBooking;

use Tests\TestCase;

/* legacy counter-booker.php — the "who is this booking for" step of the counter.
   Its script is run for real in Node (no browser needed): the page's own block,
   with the few page globals it calls stubbed. */
class CounterBookerTest extends TestCase
{
    /* Runs $js after the counter-booker <script> block of the counter page; returns its stdout. */
    private function runCounterScript(string $js): string
    {
        $html = $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))
            ->assertOk()->getContent();
        preg_match_all('~<script>(.*?)</script>~s', $html, $m);
        $block = collect($m[1])->first(fn ($s) => str_contains($s, 'const booker0'));
        $this->assertNotNull($block, 'counter-booker.php block not on the page');

        $esc = "function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;'); }";
        $script = "const COUNTER_STAFF='Staff', DISCOUNT_PERCENT=20, CSRF='t', COUNTER_OVERDUE={}, COUNTER_BLOCKED={}; let state; function render(){}\n"
            . $esc . "\n" . $block . "\nstate = { booker: Object.assign({}, booker0) };\n" . $js;
        $file = tempnam(sys_get_temp_dir(), 'cbk') . '.js';
        file_put_contents($file, $script);
        $out = shell_exec('node ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        return trim((string) $out);
    }

    /* A customer's name went into the result button's onclick with only `"`
       escaped, so a name holding `&quot;` closed the JS string once the browser
       decoded the attribute — anyone could register a name that runs script in
       the staff member's session. */
    public function test_a_hostile_customer_name_in_the_search_results_stays_text(): void
    {
        $out = $this->runCounterScript(<<<'JS'
            globalThis.hacked = false;
            const name = 'Eve &quot;);globalThis.hacked=true;//', phone = '0917***1234 &amp; <b>';
            state.booker.mode = 'account';
            state.booker.results = [{ id: 7, name, email: 'eve@example.com', phone }];
            const html = whoScreen();
            const attr = html.match(/onclick="(pickCustomer\([^"]*\))"/)[1];
            /* what the browser hands to the JS engine: the attribute, entity-decoded */
            const code = attr.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
            let got = null;
            pickCustomer = function (id, n, p) { got = [id, n, p]; };
            eval(code);
            console.log(JSON.stringify({ hacked: globalThis.hacked, ok: !!got && got[0] === 7 && got[1] === name && got[2] === phone }));
        JS);

        $this->assertSame('{"hacked":false,"ok":true}', $out);
    }

    /* Overdue block (2026-10-30): picking an account with an Overdue booking warns
       staff, and the step can still go on (nothing is disabled by it). */
    public function test_an_account_with_an_overdue_booking_is_flagged_but_can_still_be_booked(): void
    {
        $out = $this->runCounterScript(<<<'JS'
            COUNTER_OVERDUE[7] = 'USeP Gymnasium, Sep 10, 2026 — ₱1,800 <b>';
            state.booker = Object.assign({}, booker0, { customerId: 7, name: 'Eve', phone: '0917***1234', verified: true, idChecked: true });
            const html = whoScreen();
            state.booker.customerId = 8;
            const other = whoScreen();
            console.log(JSON.stringify({
              warned: html.includes('<strong>Unpaid booking:</strong> USeP Gymnasium, Sep 10, 2026 — ₱1,800 &lt;b&gt;.'),
              ready: counterBookerReady(), otherWarned: other.includes('Unpaid booking'),
            }));
        JS);

        $this->assertSame('{"warned":true,"ready":true,"otherWarned":false}', $out);
    }

    /* Blocked at this office (DB-DECISIONS 28): the search result and the picked account say
       so, and the step cannot go on. Another customer is not affected. */
    public function test_an_account_blocked_at_this_office_is_flagged_and_cannot_be_booked(): void
    {
        $out = $this->runCounterScript(<<<'JS'
            COUNTER_BLOCKED[7] = true;
            state.booker = Object.assign({}, booker0, { mode: 'account', customerId: 7, name: 'Eve', phone: '0917***1234', verified: true, idChecked: true,
              results: [{ id: 7, name: 'Eve', email: 'e@x.com', phone: '0917***1234' }, { id: 8, name: 'Bo', email: 'b@x.com', phone: '0917***9999' }] });
            const html = whoScreen();
            const ready = counterBookerReady();
            state.booker.customerId = 8;
            console.log(JSON.stringify({
              listed: (html.match(/Blocked at this office/g) || []).length, ready: ready, otherReady: counterBookerReady(),
            }));
        JS);

        $this->assertSame('{"listed":2,"ready":false,"otherReady":true}', $out);
    }

    /* A new account (DB-DECISIONS 28): a domain close to a common one gets a click-to-fix
       suggestion; the confirm step shows the email large, to read back. */
    public function test_a_new_account_suggests_the_email_domain_and_confirms_the_email_large(): void
    {
        $out = $this->runCounterScript(<<<'JS'
            const s = ['ju@gmial.com', 'ju@yaho.com', 'ju@outlok.com', 'ju@hotmial.com', 'ju@usep.edu.hp', 'ju@gmail.com', 'ju@company.org', 'nope'].map(emailSuggestion);
            state.booker = Object.assign({}, booker0, { mode: 'new', name: 'Ju', email: 'ju@gmial.com', phone: '09170002222' });
            const form = whoScreen();
            state.booker.email = 'ju@gmail.com'; state.booker.confirmNew = true; state.booker.warning = 'Another account already uses this mobile number.';
            const confirm = whoScreen();
            console.log(JSON.stringify({ s, fix: form.includes('Did you mean <strong>ju@gmail.com</strong>?'), ready: counterBookerReady(),
              big: /id="bkConfirmEmail" style="font-size:24px[^"]*">ju@gmail.com</.test(confirm),
              readBack: confirm.includes('Read this email address back to the customer'), warned: confirm.includes('already uses this mobile') }));
        JS);

        $this->assertSame('{"s":["ju@gmail.com","ju@yahoo.com","ju@outlook.com","ju@hotmail.com","ju@usep.edu.ph","","",""],'
            . '"fix":true,"ready":false,"big":true,"readBack":true,"warned":true}', $out);
    }

    /* No password on the account (Google, or just made here): "ID checked" stands in for it. */
    public function test_an_account_with_no_password_is_ready_once_id_checked_is_ticked(): void
    {
        $out = $this->runCounterScript(<<<'JS'
            state.booker = Object.assign({}, booker0, { mode: 'account', customerId: 7, name: 'Eve', phone: '0917•••1234', idChecked: true, noPassword: true });
            const html = whoScreen();
            const before = counterBookerReady();
            state.booker.idIdentity = true;
            console.log(JSON.stringify({ box: html.includes('id="bkIdIdentity"'), noPwInput: !html.includes('id="bkPassword"'),
              before, after: counterBookerReady(), line: counterAccountLine().includes('ID checked') }));
        JS);

        $this->assertSame('{"box":true,"noPwInput":true,"before":false,"after":true,"line":true}', $out);
    }
}
