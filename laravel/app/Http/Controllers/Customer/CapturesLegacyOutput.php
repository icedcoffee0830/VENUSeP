<?php

namespace App\Http\Controllers\Customer;

/* Some legacy includes PRINT a <script> block when they load (pricing.php,
   gcash-checker.php, counter-booker.php). The old pages included them at the
   spot the block belonged; here the controller loads them through legacy()
   and hands the printed block to the view.

   require_once runs a file once per PHP process, so the block is kept for the
   rest of the process — a second request in the same process (tests) still
   gets it. */
trait CapturesLegacyOutput
{
    protected static function legacyOutput(string $name): string
    {
        static $printed = [];
        if (!array_key_exists($name, $printed)) {
            ob_start();
            legacy($name);
            $printed[$name] = (string) ob_get_clean();
        }
        return $printed[$name];
    }
}
