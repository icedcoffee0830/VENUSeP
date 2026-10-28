<?php

/* legacy('bookings', 'pricing') — load the shared business logic from
   legacy/includes/ (the old includes/*.php, run as-is; db.php and auth.php
   there are the Laravel shims).

   Returns every top-level variable the legacy files have defined so far
   ($venueRooms, $hostelRooms, $DISCOUNT_PERCENT, $customerContact, ...), so
   `extract(legacy('venue-rooms'));` gives a controller or view exactly what
   `include 'venue-rooms.php';` gave the old page.

   Why a registry: the old pages included every file at their top level, so a
   variable one file defined was visible to the next. Here files are loaded from
   functions and views, and require_once never runs a file twice — so each file
   is loaded in a scope pre-seeded with every variable loaded before it, and what
   it defines is kept for the rest of the request. Always load legacy files
   through this function, never with a bare include/require. */
if (!function_exists('legacy')) {
    function legacy(string ...$names): array
    {
        static $vars = [];
        static $loaded = [];
        foreach ($names as $name) {
            $file = base_path('legacy/includes/' . $name . '.php');
            if (isset($loaded[$file])) {
                continue;
            }
            $loaded[$file] = true;
            $vars = (static function (array $__seed, string $__file): array {
                extract($__seed, EXTR_SKIP);
                require_once $__file;
                $defined = get_defined_vars();
                unset($defined['__seed'], $defined['__file']);
                return $defined;
            })($vars, $file);
        }
        return $vars;
    }
}
