<?php

/* legacy('bookings', 'pricing') — load the shared business logic from
   legacy/includes/ (the old includes/*.php, run as-is; db.php and auth.php
   there are the Laravel shims). */
if (!function_exists('legacy')) {
    function legacy(string ...$names): void
    {
        foreach ($names as $name) {
            require_once base_path('legacy/includes/' . $name . '.php');
        }
    }
}
