<?php

namespace Tests\Feature\CustomerBooking;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/* Counter mode (staff session + ?counter=1) on the two booking pages: every link
   and script redirect back into the booking site keeps ?counter=1, like the
   landing page's room cards do — else one click ("See all spaces", a "More
   rooms" card, "All rooms", the footer) drops staff onto the customer sign-in. */
class CounterLinksTest extends TestCase
{
    /* Every URL into the booking site on the page: hrefs, and the quoted URLs
       the script sends location to (goHome, restart, the back-button guard). */
    private function siteUrls(string $html): array
    {
        $pages = '(?:venues|room-reservation|hostel-reservation|faq)';
        preg_match_all('~href="(' . preg_quote(url('/customer/'), '~') . '/' . $pages . '[^"]*)"~', $html, $a);
        preg_match_all("~'(" . preg_quote(url('/customer/'), '~') . '/' . $pages . "[^']*)'~", $html, $b);

        /* links built in the script (`${...}`) are drawn only on the counter's own screens */
        $urls = array_filter(array_merge($a[1], $b[1]), fn ($u) => !str_contains($u, '${'));

        return array_map('html_entity_decode', array_values($urls));
    }

    public static function pages(): array
    {
        return [['customer.room-reservation', 'r1'], ['customer.hostel-reservation', 'h1']];
    }

    #[DataProvider('pages')]
    public function test_every_link_back_into_the_site_keeps_counter_mode(string $route, string $room): void
    {
        $html = $this->actingAsAdmin()->get(route($route, ['room' => $room, 'counter' => 1]))->assertOk()->getContent();
        $urls = $this->siteUrls($html);
        $this->assertGreaterThan(8, count($urls));
        foreach ($urls as $u) {
            $this->assertStringContainsString('counter=1', $u, "$route: $u drops counter mode");
        }
    }

    #[DataProvider('pages')]
    public function test_a_customer_gets_no_counter_flag(string $route, string $room): void
    {
        $html = $this->actingAsCustomer('aronerfe0830@gmail.com')->get(route($route, ['room' => $room]))->assertOk()->getContent();
        $this->assertStringNotContainsString('counter=1', implode(' ', $this->siteUrls($html)));
    }
}
