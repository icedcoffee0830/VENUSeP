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
        $script = "const COUNTER_STAFF='Staff', DISCOUNT_PERCENT=20, CSRF='t'; let state; function render(){}\n"
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
}
