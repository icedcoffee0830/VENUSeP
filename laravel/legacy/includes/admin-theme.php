<?php
/* =====================================================================
   ADMIN THEME — the crimson look for every admin page, in ONE place.
   Included automatically by includes/header.php (admin branch), which every
   admin page already includes AFTER its own <style>, so these rules win at
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
<style>
  /* ---- surfaces: WHITE page, crimson shell (decided 2026-09-18) ----
     header.php paints <body> with the crimson gradient for the customer
     portal; the admin portal keeps the page white and lets the sidebar,
     header and the crimson accents carry the brand. */
  body, .app-wrapper, .app-main, .app-container, .app-content, .app-content-header, main.main { background: #fff !important; }
  .app-container { margin-left: var(--venusep-sidebar-width, 235px) !important; transition: margin-left 320ms cubic-bezier(.16,1,.3,1); }   /* Dashboard, Reports */
  .calendar-page { --calendar-page-bg: #fff; }
  /* Dashboard + Reports force the old black bar with !important in their own CSS — the shared gradient wins here */
  nav.app-header, .app-header, .navbar { background: linear-gradient(100deg,#8a1222 0%,#3a0c14 52%,#120809 100%) !important; border-bottom: 1px solid rgba(255,255,255,.12) !important; }

  /* ---- page titles: the accent line, not a colour change ---- */
  main.main > .header h1, .th-head h1, .calendar-heading h1, .page-heading h1, .vm-heading h1, .br-title, .vm-title, .ps-heading h1 { color: #1f1e1e !important; }
  .br-back, .vm-back, .page-heading nav a { color: #a11626 !important; }
  .br-back:hover, .vm-back:hover, .page-heading nav a:hover { color: #7d0f1e !important; }
  .vm-section-title strong, .vm-section-title b { color: #a11626 !important; }
  aside.sidebar .menu a.active i, .app-header .hd-avatar { color: #ffd166; }

  /* ---- primary actions: black -> crimson ---- */
  .btn.btn-primary, .btn.btn-primary-dark, .vm-btn.vm-btn-primary, .br-btn.br-btn-primary, .vm-edit-photos, .vm-photo-btn, .vm-picker-done,
  .staff-btn-primary, .ps-btn-primary, .rs-btn-primary, .btn-dark {
    background: #a11626 !important; border-color: #a11626 !important; color: #fff !important; box-shadow: 0 8px 20px rgba(138,18,34,.22) !important; }
  .btn.btn-primary:hover, .btn.btn-primary-dark:hover, .vm-btn.vm-btn-primary:hover, .br-btn.br-btn-primary:hover, .vm-edit-photos:hover, .vm-photo-btn:hover, .vm-picker-done:hover,
  .staff-btn-primary:hover, .ps-btn-primary:hover, .rs-btn-primary:hover, .btn-dark:hover { background: #7d0f1e !important; border-color: #7d0f1e !important; }

  /* ---- active tabs / pagination / calendar view buttons ---- */
  .br-tab.active, .tabulator-page.active, .tabulator .tabulator-footer .tabulator-page.active { background: #a11626 !important; border-color: #a11626 !important; color: #fff !important; }
  .fc .fc-button-primary { background: #fff !important; border-color: #d9d4cc !important; color: #1f1e1e !important; }
  .fc .fc-button-primary:hover { background: #f4f2ee !important; }
  .fc .fc-button-primary.fc-button-active, .fc .fc-button-primary:not(:disabled):active { background: #a11626 !important; border-color: #a11626 !important; color: #fff !important; }
  .fc .fc-daygrid-day.fc-day-today { background: rgba(161,22,38,.07) !important; }

  /* ---- black pills: role tag (Staff Management), report status (Reports) ---- */
  .badge-role, .tag.tag-finalized { background: #fbe9ec !important; color: #a11626 !important; border: 1px solid rgba(161,22,38,.25) !important; }

  /* ---- focus + links inside cards: black -> crimson accents ---- */
  input:focus, select:focus, textarea:focus { border-color: #a11626 !important; box-shadow: 0 0 0 3px rgba(161,22,38,.12) !important; outline: 0; }
  .form-check-input:checked, input[type="checkbox"]:checked, input[type="radio"]:checked { accent-color: #a11626; }
</style>
