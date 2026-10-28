<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* Venue photos are DB-backed (venues.cover_photo) — see
   includes/venue-photos.php for why this is a single column, not a
   room_media-style gallery. */
require_once __DIR__ . '/../includes/venue-photos.php';

$venueId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$venueFormRow = $venueId ? vp_venue_row($venueId) : null;
if ($venueFormRow === null) $venueId = 0;   // unknown id — behaves like Add
$venueFormCsrf = csrf_token();

/* ASSIGNED STAFF (2026-10-27): the real staff.venue_id rows. Every staff member
   handles exactly one venue, so the picker MOVES people here from the venue
   they handle now. Nobody can be removed into "no venue". Only an admin may
   change it (admin/venue-save.php re-checks); staff see the list read-only. */
$vfStaff = [];
$vfPdo = venusep_db();
if ($vfPdo !== null) {
    try {
        $vfStaff = $vfPdo->query(
            "SELECT u.id, u.is_active, s.full_name, s.position_role, s.venue_id, v.name AS venue_name
               FROM users u JOIN staff s ON s.user_id = u.id LEFT JOIN venues v ON v.id = s.venue_id
              WHERE u.account_type = 'staff' ORDER BY s.full_name"
        )->fetchAll();
    } catch (PDOException $e) {
        $vfStaff = [];
    }
}
$vfHere   = array_values(array_filter($vfStaff, function ($s) use ($venueId) { return $venueId && (int) $s['venue_id'] === $venueId; }));
$vfOthers = array_values(array_filter($vfStaff, function ($s) use ($venueId) { return !$venueId || (int) $s['venue_id'] !== $venueId; }));
$vfCanAssign = admin_is_admin();
function vf_initials($name) {
    $parts = preg_split('/\s+/', trim((string) $name));
    return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
}
?>
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

  [SIM] now marks only what is still deliberately simulated: the demo
  advance buttons, which stand in for another person, another office or
  the passage of time. The data is real.
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
    <link rel="stylesheet" href="../assets/css/confirm-modal.css" />

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

      /* real assignments (2026-10-27): who they are, and where a move comes from */
      .vm-staff-sub { display: block; font-size: 0.76rem; font-weight: 400; color: var(--vm-muted); margin-top: 1px; }
      .vm-staff-sub.is-moving { color: #8a5a12; }
      .vm-staff-hint { font-size: 0.78rem; color: var(--vm-muted); margin: -0.25rem 0 0.6rem; line-height: 1.5; }
      .vm-staff-empty { font-size: 0.82rem; color: var(--vm-muted); padding: 0.55rem 0.1rem; }

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
      <h1 class="vm-title"><?php echo $venueId ? 'Edit Venue (Location)' : 'Add Venue'; ?></h1>
      <p class="vm-subtitle">A venue is a location that holds rooms. It is not bookable.<?php if (!$venueId): ?> Photos can be added once the venue is saved and has an id.<?php endif; ?></p>

      <form class="vm-panel" onsubmit="return false">
        <input type="hidden" id="vpVenueId" value="<?php echo (int) $venueId; ?>" />
        <input type="hidden" id="vpCsrf" value="<?php echo htmlspecialchars($venueFormCsrf); ?>" />
        <!-- Single image -->
        <div class="vm-image" id="vpImageArea">
<?php $venueCover = $venueFormRow ? $venueFormRow['cover_photo'] : null; ?>
          <div id="vpImageSlot" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
<?php if ($venueCover): ?>
            <img id="vpCoverImg" src="<?php echo htmlspecialchars($venueCover); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px" />
<?php else: ?>
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
            </svg>
<?php endif; ?>
          </div>
          <label class="vm-photo-btn" style="cursor:pointer">✎ Change photo<input id="vpPhotoInput" type="file" accept="image/*" onchange="vpChangePhoto(this)" style="display:none" /></label>
          <button type="button" class="vm-photo-btn" id="vpRemoveBtn" style="right:auto;left:12px" onclick="vpRemovePhoto()"<?php echo $venueCover ? '' : ' hidden'; ?>>Remove photo</button>
        </div>

        <hr class="vm-divider" />

        <div class="vm-field">
          <label for="venueName">Venue Name</label>
          <input id="venueName" class="vm-control" type="text" value="<?php echo htmlspecialchars($venueFormRow ? $venueFormRow['name'] : ''); ?>" placeholder="e.g. Bahay Alumni" />
        </div>

        <div class="vm-field">
          <label for="venueDesc">Description</label>
          <textarea id="venueDesc" class="vm-control" rows="3" placeholder="Short description of the location"><?php echo htmlspecialchars($venueFormRow ? $venueFormRow['description'] : ''); ?></textarea>
        </div>

        <hr class="vm-divider" />

        <!-- Assigned staff -->
        <p class="vm-staff-title">Assigned Staff</p>
        <p class="vm-staff-hint">Staff handle only their own venue's bookings, and each staff member belongs to exactly one venue. Adding someone here moves them from the venue they handle now.</p>
        <div class="vm-staff-list" id="staffList">
          <?php if (!$vfHere): ?>
            <div class="vm-staff-empty" id="staffEmpty">No staff handle this venue yet<?php echo $vfCanAssign ? ' — add someone below.' : '.'; ?></div>
          <?php endif; ?>
          <?php foreach ($vfHere as $s): ?>
            <div class="vm-staff-item">
              <span class="vm-avatar"><?php echo htmlspecialchars(vf_initials($s['full_name'])); ?></span>
              <span class="vm-staff-name"><?php echo htmlspecialchars($s['full_name']); ?>
                <span class="vm-staff-sub"><?php echo htmlspecialchars($s['position_role'] ?: 'Staff'); ?><?php echo $s['is_active'] ? '' : ' · Suspended'; ?></span></span>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($vfCanAssign): ?>
        <button type="button" class="vm-add-staff" onclick="togglePicker()">➕ Add Staff</button>

        <div class="vm-picker" id="staffPicker" hidden>
          <input class="vm-picker-search" type="search" placeholder="Search staff..." oninput="filterPicker(this.value)" />
          <?php foreach ($vfOthers as $s): ?>
            <div class="vm-picker-item" data-search="<?php echo htmlspecialchars(mb_strtolower($s['full_name'] . ' ' . $s['venue_name'])); ?>">
              <span class="vm-avatar"><?php echo htmlspecialchars(vf_initials($s['full_name'])); ?></span>
              <span class="vm-staff-name"><?php echo htmlspecialchars($s['full_name']); ?>
                <span class="vm-staff-sub">handles <?php echo htmlspecialchars($s['venue_name'] ?: 'no venue'); ?><?php echo $s['is_active'] ? '' : ' · Suspended'; ?></span></span>
              <button type="button" class="vm-picker-add" onclick="addStaff(this)"
                      data-id="<?php echo (int) $s['id']; ?>"
                      data-name="<?php echo htmlspecialchars($s['full_name']); ?>"
                      data-initials="<?php echo htmlspecialchars(vf_initials($s['full_name'])); ?>"
                      data-from="<?php echo htmlspecialchars($s['venue_name'] ?: 'no venue'); ?>">+ Add</button>
            </div>
          <?php endforeach; ?>
          <div class="vm-staff-empty" id="pickerEmpty"<?php echo $vfOthers ? ' hidden' : ''; ?>>No other staff to add. Create a staff account in Staff Management first.</div>
          <button type="button" class="vm-picker-done" onclick="togglePicker()">Done</button>
        </div>
        <?php endif; ?>

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="venue-management.php">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary" onclick="vpSaveChanges()">Save Changes</button>
        </div>
      </form>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6a] PAGE SCRIPT: venue photo — "Change photo" / "Remove
         photo" call admin/venue-photo-api.php so the file is actually
         stored under assets/img/venues/covers/<venue id>.<ext> and the
         venue's cover_photo column, surviving a reload.
         ============================================================ -->
    <script src="../assets/js/confirm-modal.js"></script>
    <script>
      var VP_VENUE_ID = document.getElementById('vpVenueId').value;
      var VP_CSRF = document.getElementById('vpCsrf').value;
      var VP_PLACEHOLDER_SVG = '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6"/></svg>';

      function vpNeedsVenue() {
        if (VP_VENUE_ID && VP_VENUE_ID !== '0') return false;
        venusepAlert('Save this venue first — a photo is attached to a saved venue.');
        return true;
      }
      function vpParseApiResponse(r) {
        return r.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Unexpected response from the server (HTTP ' + r.status + '): ' + text.slice(0, 300)); }
        });
      }
      function vpApiPost(action, extra) {
        var body = extra instanceof FormData ? extra : new FormData();
        body.append('action', action);
        body.append('venue_id', VP_VENUE_ID);
        body.append('csrf', VP_CSRF);
        return fetch('venue-photo-api.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vpParseApiResponse);
      }
      function vpSetCover(url) {
        var slot = document.getElementById('vpImageSlot');
        var btn = document.getElementById('vpRemoveBtn');
        if (slot) slot.innerHTML = url ? '<img id="vpCoverImg" src="' + url + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px" />' : VP_PLACEHOLDER_SVG;
        if (btn) btn.hidden = !url;
      }
      function vpChangePhoto(input) {
        // input.files is a LIVE FileList — read what we need from it
        // before clearing input.value, or the reset empties it too.
        var f = input.files && input.files[0];
        if (!f) { input.value = ''; return; }
        if (vpNeedsVenue()) { input.value = ''; return; }
        var body = new FormData();
        body.append('file', f);
        input.value = '';
        vpSetCover(URL.createObjectURL(f));   // instant local preview
        vpApiPost('upload', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not save the photo.'); vpSetCover(null); return; }
          vpSetCover(res.cover);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); vpSetCover(null); });
      }
      async function vpRemovePhoto() {
        if (vpNeedsVenue()) return;
        if (!(await venusepConfirm('This cannot be undone.', { title: 'Remove this venue\'s photo?', okLabel: 'Remove', danger: true }))) return;
        vpApiPost('delete').then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove the photo.'); return; }
          vpSetCover(null);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }

      /* "Save Changes" — the photo already saves itself the moment you
         change/remove it; Name, Description and the staff moved here save
         together, via admin/venue-save.php. */
      async function vpSaveChanges() {
        var name = document.getElementById('venueName').value.trim();
        var description = document.getElementById('venueDesc').value.trim();
        if (!name) { venusepAlert('Enter a venue name.'); return; }

        /* Moving someone changes which bookings they may act on, so say so first. */
        var moves = Object.keys(VP_MOVES);
        if (moves.length) {
          var lines = moves.map(function (id) { return VP_MOVES[id].name + ' (now ' + VP_MOVES[id].from + ')'; }).join('\n');
          var ok = await venusepConfirm(lines + '\n\nThey will handle ' + name + '\'s bookings instead, and can no longer act on their old venue\'s.',
            { title: 'Move ' + (moves.length === 1 ? 'this staff member' : 'these staff members') + ' here?', okLabel: 'Move and save' });
          if (!ok) return;
        }

        var body = new FormData();
        body.append('venue_id', VP_VENUE_ID);
        body.append('name', name);
        body.append('description', description);
        body.append('move_staff', JSON.stringify(moves));
        body.append('csrf', VP_CSRF);
        fetch('venue-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vpParseApiResponse)
          .then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Could not save the venue.'); return; }
            /* move on only once OK is clicked — unlike alert(), the modal does
               not block, and navigating at once wiped it off the screen */
            var movedLine = (res.moved && res.moved.length) ? '\n\n' + res.moved.join(', ') + (res.moved.length === 1 ? ' now handles ' : ' now handle ') + name + '.' : '';
            venusepAlert('Venue saved.' + movedLine, { tone: 'ok', title: 'Saved' }).then(function () {
              location.href = (res.created && res.venue_id)
                ? 'venue-form.php?id=' + encodeURIComponent(res.venue_id)
                : 'venue-management.php';
            });
          })
          .catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
    </script>
    <!-- ============================================================
         [6b] PAGE SCRIPT — the Assigned Staff picker (2026-10-27):
         · togglePicker()  show/hide the "Add Staff" picker
         · filterPicker()  the picker's search box
         · addStaff()      queue a move of that person to THIS venue
         · undoMove()      take a queued move back (before Save)
         Nothing moves until Save Changes, which sends VP_MOVES.
         ============================================================ -->
    <script>
      /* user id -> { name, from }: people to move here on Save */
      const VP_MOVES = {};

      function togglePicker() {
        const p = document.getElementById('staffPicker');
        if (p) p.hidden = !p.hidden;
      }

      function filterPicker(q) {
        q = String(q || '').trim().toLowerCase();
        document.querySelectorAll('#staffPicker .vm-picker-item').forEach(function (item) {
          if (item.dataset.moved) return;
          item.hidden = q !== '' && item.dataset.search.indexOf(q) === -1;
        });
      }

      function vfEsc(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

      function addStaff(btn) {
        const d = btn.dataset;
        VP_MOVES[d.id] = { name: d.name, from: d.from };
        const item = document.createElement('div');
        item.className = 'vm-staff-item';
        item.dataset.moveId = d.id;
        item.innerHTML =
          '<span class="vm-avatar">' + vfEsc(d.initials) + '</span>' +
          '<span class="vm-staff-name">' + vfEsc(d.name) +
            '<span class="vm-staff-sub is-moving">Moving here from ' + vfEsc(d.from) + ' — saved with Save Changes</span></span>' +
          '<button type="button" class="vm-remove" title="Keep them at ' + vfEsc(d.from) + '" onclick="undoMove(\'' + vfEsc(d.id) + '\')">&times;</button>';
        document.getElementById('staffList').appendChild(item);
        const pick = btn.closest('.vm-picker-item');
        pick.dataset.moved = '1';
        pick.hidden = true;
        const empty = document.getElementById('staffEmpty');
        if (empty) empty.hidden = true;
      }

      function undoMove(id) {
        delete VP_MOVES[id];
        const item = document.querySelector('#staffList [data-move-id="' + id + '"]');
        if (item) item.remove();
        const btn = document.querySelector('#staffPicker .vm-picker-add[data-id="' + id + '"]');
        if (btn) { const pick = btn.closest('.vm-picker-item'); delete pick.dataset.moved; pick.hidden = false; }
        const empty = document.getElementById('staffEmpty');
        if (empty && !document.querySelector('#staffList .vm-staff-item')) empty.hidden = false;
      }
    </script>
  </body>
</html>
