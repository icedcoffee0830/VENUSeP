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

    /* Text colours under 4.5:1 on white. A dot or a border may keep its colour; reports are the teammate's. */
    public function test_no_text_uses_the_greys_and_warning_colours_that_fail_contrast(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php') || $file->getFilename() === 'reports.blade.php') continue;
            $this->assertDoesNotMatchRegularExpression('/#8a857d|#8a8680|#a3a09a|[^-a-z]color: ?#(ff6b12|2f9e63)/i', file_get_contents($file->getPathname()), $file->getFilename());
        }
    }
}
