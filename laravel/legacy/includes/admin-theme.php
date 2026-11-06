<?php
/* Holds: the crimson page theme printed on every admin page. */
/* =====================================================================
   ADMIN THEME — the crimson look for every admin page, in ONE place.
   Included automatically by header.php (admin branch), which every
   admin page already includes AFTER its own styles, so these rules win at
   equal specificity without touching any page.

   What it does (UI only — no markup, no JS, no data):
     · the page stays WHITE; the crimson sidebar + header and the crimson
       accents below carry the brand (decided 2026-09-18)
     · the black primary buttons, active tabs and active pagination become
       crimson, like the customer side
     · the two pages that hard-code the sidebar width lay out from the
       shared --venusep-sidebar-width variable, so the header burger works

   Grouped by page so a rule is easy to find. Selectors are the pages' own
   class names (2026-09-18); if a page renames a class, update it here.
   ===================================================================== */
?>
<link rel="stylesheet" href="<?php echo asset_stamped('assets/css/admin-theme.css'); ?>" />
