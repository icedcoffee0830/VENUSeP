<?php ?>
<!DOCTYPE html>
<!-- ==================================================================
  VENUE FORM — VENUSeP merged system (ported from the AdminLTE mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  add/edit venue form (image, fields, assigned staff)
    [6] PAGE SCRIPT   staff picker (togglePicker / addStaff)

  (No [5]: the stock AdminLTE library scripts were removed in this port —
  the team shell needs no JS.)

  [SIM] marks simulation-only pieces (fake data / demo actions) that
  exist so the mockup works on its own — delete or replace them when
  the real database is connected.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Venue Form</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- ============================================================
         [0] SHELL CSS — the TEAM header bar + sidebar + layout
         (adapted from venusep_profile.php / sidebar.php so this page
         needs no AdminLTE stylesheet). Same block on every page.
         ============================================================ -->
    <style>
      /* layout */
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* (header + sidebar styles live in ../includes/ — the shared includes) */
      /* main content area sits right of the sidebar, below the header */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 1.3rem 0 0; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
      }
    </style>

    <!-- ============================================================
         [1] PAGE CSS — every style this page needs, kept inline so
         the file is self-contained. Grouped: image / fields / staff /
         staff picker / footer (see the /* … */ labels below).
         ============================================================ -->
    <style>
      /*
        Venue form (Add / Edit) page UI CSS
        Self-contained. Blank fields = Add, pre-filled = Edit.
      */
      :root {
        --vm-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        --vm-page-bg: #ffffff;
        --vm-border: #e5e5e5;
        --vm-text: #1f1e1e;
        --vm-muted: #606a75;
        --vm-hover: #ebebeb;
        --vm-strip: #f4f4f4;
        --vm-dark: #1f1e1e;
        --vm-radius: 10px;
        --vm-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
        --vm-shadow-hover: 0 8px 24px rgba(16, 24, 40, 0.12);
        --vm-danger: #ff4d4f;
      }

      * { box-sizing: border-box; }

      body {
        background: var(--vm-page-bg);
        color: var(--vm-text);
        color-scheme: light;
        font-family: var(--vm-font);
        margin: 0;
      }

      .vm-page {
        margin: 0;
        padding: 22px 0 0; /* 22px + 4px from .app-content = 26px top; sides owned by .container-fluid */
        width: 100%;
      }

      .vm-back {
        align-items: center;
        color: var(--vm-muted);
        display: inline-flex;
        font-size: 0.85rem;
        font-weight: 600;
        gap: 0.4rem;
        margin-bottom: 1.25rem;
        text-decoration: none;
      }

      .vm-back:hover { color: var(--vm-text); }

      .vm-title {
        font-size: 1.4rem;
        font-weight: 700;
        margin: 0 0 0.25rem;
      }

      .vm-subtitle {
        color: var(--vm-muted);
        font-size: 0.85rem;
        margin: 0 0 1.75rem;
      }

      .vm-panel {
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: var(--vm-radius);
        box-shadow: var(--vm-shadow);
        padding: 1.5rem;
      }

      /* Image */
      .vm-image {
        align-items: center;
        background: linear-gradient(135deg, #eef1f5 0%, #dfe4ea 100%);
        border-radius: 10px;
        color: #b7bfc9;
        display: flex;
        height: 180px;
        justify-content: center;
        margin-bottom: 0.6rem;
        position: relative;
      }

      .vm-photo-btn {
        align-items: center;
        background: rgba(31, 30, 30, 0.85);
        border: 0;
        border-radius: 8px;
        bottom: 12px;
        color: #ffffff;
        cursor: pointer;
        display: inline-flex;
        font-family: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        gap: 0.35rem;
        padding: 0.4rem 0.75rem;
        position: absolute;
        right: 12px;
      }

      .vm-divider {
        border: 0;
        border-top: 1px solid var(--vm-border);
        margin: 1.5rem 0;
      }

      /* Fields */
      .vm-field { margin-bottom: 1.1rem; }

      .vm-field label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 0.4rem;
      }

      .vm-control {
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        color: var(--vm-text);
        font-family: inherit;
        font-size: 0.9rem;
        padding: 0.6rem 0.8rem;
        width: 100%;
      }

      .vm-control:focus {
        border-color: #bdbdbd;
        outline: 0;
      }

      textarea.vm-control { resize: vertical; }

      /* Staff */
      .vm-staff-title {
        font-size: 0.85rem;
        font-weight: 600;
        margin: 0 0 0.5rem;
      }

      .vm-staff-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .vm-staff-item {
        align-items: center;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        display: flex;
        gap: 0.75rem;
        padding: 0.55rem 0.75rem;
      }

      .vm-avatar {
        align-items: center;
        background: var(--vm-dark);
        border-radius: 50%;
        color: #ffffff;
        display: flex;
        flex-shrink: 0;
        font-size: 0.75rem;
        font-weight: 700;
        height: 34px;
        justify-content: center;
        width: 34px;
      }

      .vm-staff-name {
        flex: 1;
        font-size: 0.9rem;
        font-weight: 500;
      }

      .vm-remove {
        background: transparent;
        border: 0;
        border-radius: 6px;
        color: var(--vm-muted);
        cursor: pointer;
        font-size: 1rem;
        line-height: 1;
        padding: 0.3rem 0.5rem;
      }

      .vm-remove:hover {
        background: #fff1f1;
        color: var(--vm-danger);
      }

      .vm-add-staff {
        align-items: center;
        background: var(--vm-strip);
        border: 1px dashed #cfd4da;
        border-radius: 8px;
        color: var(--vm-text);
        cursor: pointer;
        display: inline-flex;
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 600;
        gap: 0.4rem;
        margin-top: 0.6rem;
        padding: 0.55rem 0.9rem;
      }

      .vm-add-staff:hover { background: var(--vm-hover); }

      /* Staff picker */
      .vm-picker {
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        margin-top: 0.6rem;
        overflow: hidden;
      }

      .vm-picker[hidden] { display: none; }

      .vm-picker-search {
        border: 0;
        border-bottom: 1px solid var(--vm-border);
        font-family: inherit;
        font-size: 0.85rem;
        padding: 0.6rem 0.8rem;
        width: 100%;
      }

      .vm-picker-search:focus { outline: 0; }

      .vm-picker-item {
        align-items: center;
        border-bottom: 1px solid var(--vm-border);
        display: flex;
        gap: 0.75rem;
        padding: 0.5rem 0.75rem;
      }

      .vm-picker-item:last-of-type { border-bottom: 0; }

      .vm-picker-add {
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 6px;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.3rem 0.7rem;
      }

      .vm-picker-add:hover { background: var(--vm-hover); }

      .vm-picker-done {
        background: var(--vm-dark);
        border: 0;
        color: #ffffff;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.55rem;
        width: 100%;
      }

      /* Footer */
      .vm-form-foot {
        display: flex;
        gap: 0.6rem;
        justify-content: flex-end;
        margin-top: 1.75rem;
      }

      .vm-btn {
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.875rem;
        font-weight: 700;
        min-height: 2.375rem;
        padding: 0 1.3rem;
        text-decoration: none;
        transition: background-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
      }

      .vm-btn-outline {
        background: #ffffff;
        color: var(--vm-text);
        display: inline-flex;
        align-items: center;
      }

      .vm-btn-outline:hover { background: var(--vm-hover); }

      .vm-btn-primary {
        background: var(--vm-dark);
        border-color: var(--vm-dark);
        color: #ffffff;
      }

      .vm-btn-primary:hover {
        box-shadow: var(--vm-shadow-hover);
        transform: translateY(-1px);
      }
    </style>
  </head>
  <body>
    <div class="app-wrapper">
            <!-- ==========================================================
           [2] HEADER BAR — the ONE shared top bar, included from
           ../includes/header.php. Edit it THERE and every page updates.
           ========================================================== -->
      <?php include __DIR__ . '/../includes/header.php'; ?>

            <!-- ==========================================================
           [3] SIDEBAR — the ONE shared sidebar, included from
           ../includes/sidebar.php ($active = the highlighted item).
           Edit the menu THERE and every page updates.
           ========================================================== -->
      <?php $active = 'Venue Management'; include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- ==========================================================
           [4] PAGE CONTENT — the venue form itself: back link, title,
           venue image, name + description fields, assigned-staff list
           with the "Add Staff" picker, then the Save/Cancel footer.
           [SIM] the pre-filled values and the staff names are sample
           data — the real app will load the venue by its ?id= from
           the database (blank form = Add, loaded form = Edit).
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="vm-page">
      <a class="vm-back" href="venue-management.php">&larr; Back to Venue Management</a>
      <h1 class="vm-title">Edit Venue (Location)</h1>
      <p class="vm-subtitle">A venue is a location that holds rooms. It is not bookable.</p>

      <form class="vm-panel" onsubmit="return false">
        <!-- Single image -->
        <div class="vm-image">
          <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
          </svg>
          <button type="button" class="vm-photo-btn">✎ Change photo</button>
        </div>

        <hr class="vm-divider" />

        <div class="vm-field">
          <label for="venueName">Venue Name</label>
          <input id="venueName" class="vm-control" type="text" value="Bahay Alumni" placeholder="e.g. Bahay Alumni" />
        </div>

        <div class="vm-field">
          <label for="venueDesc">Description</label>
          <textarea id="venueDesc" class="vm-control" rows="3" placeholder="Short description of the location">Heritage location for alumni events and functions.</textarea>
        </div>

        <hr class="vm-divider" />

        <!-- Assigned staff -->
        <p class="vm-staff-title">Assigned Staff</p>
        <div class="vm-staff-list" id="staffList">
          <div class="vm-staff-item">
            <span class="vm-avatar">JD</span>
            <span class="vm-staff-name">Juan Dela Cruz</span>
            <button type="button" class="vm-remove" onclick="this.closest('.vm-staff-item').remove()">&times;</button>
          </div>
          <div class="vm-staff-item">
            <span class="vm-avatar">MS</span>
            <span class="vm-staff-name">Maria Santos</span>
            <button type="button" class="vm-remove" onclick="this.closest('.vm-staff-item').remove()">&times;</button>
          </div>
        </div>

        <button type="button" class="vm-add-staff" onclick="togglePicker()">➕ Add Staff</button>

        <div class="vm-picker" id="staffPicker" hidden>
          <input class="vm-picker-search" type="search" placeholder="Search staff..." />
          <div class="vm-picker-item">
            <span class="vm-avatar">PR</span>
            <span class="vm-staff-name">Pedro Reyes</span>
            <button type="button" class="vm-picker-add" onclick="addStaff('PR','Pedro Reyes', this)">+ Add</button>
          </div>
          <div class="vm-picker-item">
            <span class="vm-avatar">AL</span>
            <span class="vm-staff-name">Ana Lim</span>
            <button type="button" class="vm-picker-add" onclick="addStaff('AL','Ana Lim', this)">+ Add</button>
          </div>
          <div class="vm-picker-item">
            <span class="vm-avatar">CT</span>
            <span class="vm-staff-name">Carlos Tan</span>
            <button type="button" class="vm-picker-add" onclick="addStaff('CT','Carlos Tan', this)">+ Add</button>
          </div>
          <button type="button" class="vm-picker-done" onclick="togglePicker()">Done</button>
        </div>

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="venue-management.php">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary">Save Changes</button>
        </div>
      </form>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — this page's own JS (UI-only, no backend):
         · togglePicker()  show/hide the "Add Staff" picker
         · addStaff()      move a person from the picker to the list
         [SIM] adding/removing staff only changes the page in memory —
         nothing is saved; the real app will save via the server.
         ============================================================ -->
    <script>
      // UI-only staff management. No backend.
      function togglePicker() {
        const p = document.getElementById('staffPicker');
        p.hidden = !p.hidden;
      }

      function addStaff(initials, name, btn) {
        const item = document.createElement('div');
        item.className = 'vm-staff-item';
        item.innerHTML =
          '<span class="vm-avatar">' + initials + '</span>' +
          '<span class="vm-staff-name">' + name + '</span>' +
          '<button type="button" class="vm-remove" onclick="this.closest(\'.vm-staff-item\').remove()">&times;</button>';
        document.getElementById('staffList').appendChild(item);
        btn.closest('.vm-picker-item').remove();
      }
    </script>
  </body>
</html>
