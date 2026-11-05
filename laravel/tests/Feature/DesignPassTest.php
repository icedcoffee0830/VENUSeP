<?php

namespace Tests\Feature;

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
}
