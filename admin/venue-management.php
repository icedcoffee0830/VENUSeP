<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* ==================================================================
   [SIM] ROOMS — sample data; the real app loads these from the database.

   Rooms have NO `status` field. "Available"/"Occupied" were only ever the
   booking calendar wearing a word — a second source of truth, free to drift
   from the first, and it did: a room sat at "Maintenance" while its booking
   form kept taking reservations. Only a closure is a real decision, and a
   closure has DATES, so that is all that is stored:

       maintenance => ['from'=>ISO, 'until'=>ISO|null, 'reason'=>…, 'blocks'=>bool]
       until = null  -> INDEFINITE (no END date — not "no date"; see review below)
       blocks = true -> HARD, cannot be booked | false -> MEDIUM, still bookable

   Everything the card shows about maintenance is DERIVED from that window by
   vmMaint() below, so the card can never disagree with the booking engine.
   Mirrors customer/room-reservation.php — one shape, both sides.
   ================================================================== */
$TODAY = date('Y-m-d');

/* Both venue and hostel rooms now come from ONE shared source each, so this page,
   the customer landing and the booking pages can never disagree about what
   exists. The venue rooms used to be three hand-written cards here that no
   customer could actually book — the exact drift the shared includes end. */
include __DIR__ . '/../includes/venues.php';       // the venue list — one source, data-driven
include __DIR__ . '/../includes/venue-rooms.php';
include __DIR__ . '/../includes/hostel-rooms.php';
require_once __DIR__ . '/../includes/room-photos.php';   // real uploaded room cover photos, if any
require_once __DIR__ . '/../includes/venue-photos.php';  // real uploaded venue cover photos, if any
include __DIR__ . '/../includes/pricing.php';       // the USeP discount rate — THIS page is the screen that edits it (one rate for all venues)

$rooms = $venueRooms;   // the r1–r8 event rooms, same data the booking page shows

/* Does the window cover this date? -> the booking gate. */
function vmCovers($m, $ds) {
  return $m && $ds >= $m['from'] && ($m['until'] === null || $ds <= $m['until']);
}
/* Is the window still relevant at all? -> whether the card says anything.
   A window whose end date has passed is over; the room is simply normal again. */
function vmLive($m, $today) {
  return $m && ($m['until'] === null || $m['until'] >= $today);
}
/* How long an indefinite closure has been running — the only input the review
   nag needs. Derived from `from`, so there is no extra field to keep accurate. */
function vmDaysClosed($m, $today) {
  if (!$m || $today < $m['from']) return 0;
  return (int) ((strtotime($today) - strtotime($m['from'])) / 86400);
}
/* An indefinite closure past 14 days is the ONE case nothing else will ever
   raise: a fixed window reopens itself when its end date passes, but an
   indefinite one only ends when a human remembers — and nobody remembers rooms.
   So the room has to ask. 14 days is a starting line, not a rule; staff work the
   list from the top either way, and nothing else depends on the number. */
function vmNeedsReview($m, $today) {
  return $m && $m['blocks'] && $m['until'] === null && vmDaysClosed($m, $today) >= 14;
}
/* A small "n of 6" bed strip. Hostel rooms show OCCUPANCY where a venue room
   shows capacity — because "4 of 6 beds free" is the fact staff actually need,
   and unlike a status label it is true of a room on a NIGHT, not forever. */
function vmBedStrip($taken, $total) {
  $out = '<span class="vm-bedstrip">';
  for ($i = 0; $i < $total; $i++) {
    $out .= '<span class="vm-bedpip' . ($i < $taken ? ' is-taken' : '') . '"></span>';
  }
  return $out . '</span>';
}
/* Gender mix is DISPLAY ONLY — staff see the room's make-up, nothing enforces it. */
function vmMixLabel(array $mix) {
  $p = [];
  if ($mix['F']) $p[] = $mix['F'] . 'F';
  if ($mix['M']) $p[] = $mix['M'] . 'M';
  return $p ? implode(' ', $p) : '';
}

/* The card's maintenance line — computed, never stored. Returns null when the
   room has nothing to say, which is all "Available" ever meant. */
function vmMaint($m, $today) {
  if (!vmLive($m, $today)) return null;
  $started = $today >= $m['from'];
  $fmt = function ($d) { return date('M j, Y', strtotime($d)); };
  if (!$started)            $when = $m['until'] ? $fmt($m['from']) . ' – ' . $fmt($m['until']) : 'from ' . $fmt($m['from']);
  elseif ($m['until'])      $when = 'until ' . $fmt($m['until']);
  else                      $when = 'no set return date';
  return [
    'label' => ($m['blocks'] ? 'Closed for maintenance' : 'Partial maintenance') . ' · ' . $when,
    'dot'   => $m['blocks'] ? 'vm-dot-off' : 'vm-dot-warn',
    'reason'=> $m['reason'],
    'review'=> vmNeedsReview($m, $today),
    'days'  => vmDaysClosed($m, $today),
  ];
}
?>
<!DOCTYPE html>
<!-- ==================================================================
  VENUE MANAGEMENT — VENUSeP merged system (ported from the AdminLTE mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  venue cards + room cards, search, sort, Add buttons
    [6] PAGE SCRIPT   search filter, venue sort, photo dots

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
    <title>VENUSeP | Venue Management</title>

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
      .app-content-header { padding: 26px 0 0; } /* title row starts 26px below the header, same as other pages */
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
         [1] PAGE CSS — every style this page needs, kept inline so the
         file is self-contained. Groups are numbered 1) … 9) below.
         ============================================================ -->
    <style>
      /*
        Venue Management (list) page UI CSS
        Self-contained: move this whole block to the shared CSS file when the group merges pages.
      */

      /* 1) Quick Edit Values */
      :root {
        --vm-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        --vm-page-bg: #ffffff;
        --vm-card-bg: #ffffff;
        --vm-border: #e5e5e5;
        --vm-text: #1f1e1e;
        --vm-muted: #606a75;
        --vm-hover: #ebebeb;
        --vm-strip: #f4f4f4;
        --vm-dark: #1f1e1e;
        --vm-radius: 10px;
        --vm-shadow: 0 1px 3px rgba(16, 24, 40, 0.08);
        --vm-shadow-hover: 0 8px 24px rgba(16, 24, 40, 0.12);
        --vm-warn: #ff6b12;
        --vm-danger: #ff4d4f;
        --vm-content-x: 1.5rem;
      }

      * {
        box-sizing: border-box;
      }

      body {
        background: var(--vm-page-bg);
        color: var(--vm-text);
        color-scheme: light;
        font-family: var(--vm-font);
        margin: 0;
      }

      .vm-page {
        margin: 0 auto;
        max-width: 1180px;
        padding: 2rem var(--vm-content-x) 4rem;
      }

      /* 2) Header */
      .vm-header {
        align-items: flex-start;
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        justify-content: space-between;
      }

      .vm-heading h1 {
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.1;
        margin: 0;
      }

      .vm-heading p {
        color: var(--vm-muted);
        font-size: 0.875rem;
        margin: 0.35rem 0 0;
      }

      .vm-actions {
        display: flex;
        gap: 0.6rem;
      }

      .vm-btn {
        align-items: center;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        font-family: inherit;
        font-size: 0.875rem;
        font-weight: 700;
        gap: 0.4rem;
        min-height: 2.375rem;
        padding: 0 1.1rem;
        text-decoration: none;
        transition:
          background-color 0.18s ease,
          border-color 0.18s ease,
          box-shadow 0.18s ease,
          transform 0.18s ease;
        white-space: nowrap;
      }

      .vm-btn-primary {
        background: var(--vm-dark);
        border-color: var(--vm-dark);
        color: #ffffff;
      }

      .vm-btn-primary:hover {
        box-shadow: var(--vm-shadow-hover);
        transform: translateY(-1px);
      }

      .vm-btn-outline {
        background: #ffffff;
        color: var(--vm-text);
      }

      .vm-btn-outline:hover {
        background: var(--vm-hover);
        border-color: #d7d7d7;
      }

      /* 3) Search */
      .vm-toolbar {
        align-items: center;
        display: flex;
        gap: 0.6rem;
        margin: 1.5rem 0 0.5rem;
      }

      .vm-search-wrap {
        flex: 1;
        position: relative;
      }

      .vm-search-wrap svg {
        left: 0.9rem;
        pointer-events: none;
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
      }

      .vm-search {
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        color: var(--vm-text);
        font-family: inherit;
        font-size: 0.9rem;
        height: 2.6rem;
        padding: 0 1rem 0 2.6rem;
        width: 100%;
      }

      .vm-search:focus {
        border-color: #bdbdbd;
        outline: 0;
      }

      /* Custom sort dropdown styled like the calendar "Date" sort dropdown */
      .vm-dd {
        flex-shrink: 0;
        position: relative;
        width: 12rem;
      }

      .vm-dd-trigger {
        align-items: center;
        background-color: #ffffff;
        background-image:
          linear-gradient(45deg, transparent 50%, var(--vm-text) 50%),
          linear-gradient(135deg, var(--vm-text) 50%, transparent 50%);
        background-position:
          calc(100% - 18px) 50%,
          calc(100% - 13px) 50%;
        background-repeat: no-repeat;
        background-size: 5px 5px, 5px 5px;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
        color: var(--vm-text);
        cursor: pointer;
        display: flex;
        font-size: 0.875rem;
        font-weight: 700;
        height: 2.6rem;
        list-style: none;
        padding: 0 2.2rem 0 0.85rem;
        transition:
          background-color 0.18s ease,
          border-color 0.18s ease,
          box-shadow 0.18s ease;
      }

      .vm-dd-trigger::-webkit-details-marker {
        display: none;
      }

      .vm-dd-trigger:hover,
      .vm-dd-trigger:focus {
        background-color: var(--vm-hover);
        border-color: #cfcac2;
        outline: 0;
      }

      .vm-dd-menu {
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        box-shadow: 0 6px 18px rgba(16, 24, 40, 0.14);
        left: 0;
        list-style: none;
        margin: 0.35rem 0 0;
        overflow: hidden;
        padding: 0;
        position: absolute;
        right: 0;
        top: 100%;
        z-index: 20;
      }

      .vm-dd-option {
        color: var(--vm-text);
        cursor: pointer;
        font-size: 0.875rem;
        padding: 0.55rem 0.85rem;
      }

      .vm-dd-option:hover {
        background: var(--vm-hover);
      }

      /* 4) Section titles */
      .vm-section-title {
        align-items: baseline;
        color: var(--vm-muted);
        display: flex;
        font-size: 0.75rem;
        font-weight: 800;
        gap: 0.5rem;
        letter-spacing: 0.06em;
        margin: 2.25rem 0 1rem;
        text-transform: uppercase;
      }

      .vm-section-title span {
        font-weight: 500;
        letter-spacing: 0;
        text-transform: none;
      }

      /* 5) Grid */
      .vm-grid {
        display: grid;
        gap: 1.25rem;
        grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
      }


      /* 6b) Settings panel — for page-wide controls (the discount rate). Deliberately
         NOT .vm-card: that is a grid tile that lifts on hover and clips overflow.
         This is a quiet, wide panel that reads as configuration. */
      .vm-setting { background: var(--vm-card-bg); border: 1px solid var(--vm-border); border-radius: var(--vm-radius); box-shadow: var(--vm-shadow); margin-bottom: 1.6rem; }
      .vm-setting-main { display: flex; align-items: center; gap: 1.4rem; padding: 1.15rem 1.35rem; }
      .vm-setting-rate { flex: none; display: flex; align-items: baseline; gap: 0.3rem; min-width: 6.5rem; }
      .vm-setting-rate span { font-size: 2.35rem; font-weight: 760; letter-spacing: -0.03em; line-height: 1; color: #1f2a44; }
      .vm-setting-rate small { font-size: 0.8rem; font-weight: 600; color: #8a857d; text-transform: uppercase; letter-spacing: 0.05em; }
      .vm-setting-copy { flex: 1 1 320px; min-width: 0; }
      .vm-setting-title { display: flex; align-items: center; gap: 0.55rem; font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin-bottom: 0.25rem; }
      .vm-setting-copy p { margin: 0; font-size: 0.82rem; line-height: 1.55; color: #6b675f; max-width: 60ch; }
      .vm-setting-act { flex: none; }
      .vm-setting-form { display: flex; flex-wrap: wrap; gap: 0.9rem 1.2rem; align-items: flex-end; padding: 1rem 1.35rem 1.1rem; border-top: 1px solid var(--vm-border); background: #fff; }
      .vm-setting-field { display: flex; flex-direction: column; gap: 0.3rem; }
      .vm-setting-field-wide { flex: 1 1 320px; min-width: 0; }
      .vm-setting-field label { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: #8a857d; }
      .vm-setting-field label .req { color: #b23a3a; }
      .vm-setting-field input { border: 1px solid var(--vm-border); border-radius: 9px; padding: 0.5rem 0.7rem; font: inherit; font-size: 0.9rem; background: #fff; }
      .vm-setting-field input:focus { outline: none; border-color: #1f2a44; }
      .vm-setting-pct { display: flex; align-items: center; gap: 0.4rem; }
      .vm-setting-pct input { width: 5.5rem; text-align: right; font-weight: 640; }
      .vm-setting-pct span { color: #8a857d; font-weight: 600; }
      .vm-setting-buttons { display: flex; gap: 0.5rem; }
      .vm-setting-msg { flex-basis: 100%; font-size: 0.8rem; color: #b23a3a; }
      .vm-setting-msg.ok { color: #1c7a4f; }
      .vm-setting-hist { padding: 0.9rem 1.35rem 1.1rem; border-top: 1px solid var(--vm-border); }
      .vm-setting-hist-title { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: #8a857d; margin-bottom: 0.5rem; }
      .vm-setting-hist-empty { font-size: 0.82rem; color: #8a857d; }
      .vm-setting-row { display: grid; grid-template-columns: auto 1fr; gap: 0.15rem 1rem; padding: 0.5rem 0; border-top: 1px solid #f1eee8; font-size: 0.82rem; line-height: 1.5; }
      .vm-setting-row:first-child { border-top: 0; padding-top: 0; }
      .vm-setting-row b { color: #1f2a44; font-weight: 680; white-space: nowrap; }
      .vm-setting-row .who { color: #8a857d; font-size: 0.76rem; }
      .vm-setting-row .why { grid-column: 2; color: #4a463f; }
      @media (max-width: 760px) { .vm-setting-main { flex-wrap: wrap; } .vm-setting-act { flex-basis: 100%; } }
      /* 6) Card base */
      .vm-card {
        background: var(--vm-card-bg);
        border: 1px solid var(--vm-border);
        border-radius: var(--vm-radius);
        box-shadow: var(--vm-shadow);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition:
          box-shadow 0.18s ease,
          transform 0.18s ease;
      }

      .vm-card:hover {
        box-shadow: var(--vm-shadow-hover);
        transform: translateY(-3px);
      }

      .vm-thumb {
        background: linear-gradient(135deg, #eef1f5 0%, #dfe4ea 100%);
        height: 160px;
        position: relative;
      }

      .vm-thumb-icon {
        align-items: center;
        color: #b7bfc9;
        display: flex;
        height: 100%;
        justify-content: center;
      }

      /* 7) Venue card */
      .vm-venue-body {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1rem 1.1rem 1.1rem;
      }

      .vm-loc-badge {
        align-items: center;
        color: var(--vm-muted);
        display: inline-flex;
        font-size: 0.75rem;
        font-weight: 700;
        gap: 0.3rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      .vm-venue-name {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
      }

      .vm-venue-desc {
        color: var(--vm-muted);
        font-size: 0.85rem;
        margin: 0;
      }

      .vm-roomcount {
        color: var(--vm-text);
        font-size: 0.8rem;
        font-weight: 600;
      }

      .vm-staff-row {
        align-items: center;
        border-top: 1px solid var(--vm-border);
        display: flex;
        gap: 0.75rem;
        margin-top: 0.35rem;
        padding-top: 0.85rem;
      }

      .vm-avatars {
        display: flex;
      }

      .vm-avatar {
        align-items: center;
        background: var(--vm-dark);
        border: 2px solid #ffffff;
        border-radius: 50%;
        color: #ffffff;
        display: flex;
        font-size: 0.7rem;
        font-weight: 700;
        height: 30px;
        justify-content: center;
        margin-left: -8px;
        width: 30px;
      }

      .vm-avatar:first-child {
        margin-left: 0;
      }

      .vm-avatar-more {
        background: var(--vm-strip);
        color: var(--vm-muted);
      }

      .vm-staff-label {
        color: var(--vm-muted);
        font-size: 0.75rem;
      }

      .vm-card-foot {
        border-top: 1px solid var(--vm-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1.1rem;
      }

      .vm-edit-link {
        align-items: center;
        color: var(--vm-text);
        display: inline-flex;
        font-size: 0.85rem;
        font-weight: 700;
        gap: 0.4rem;
        text-decoration: none;
      }

      .vm-edit-link:hover {
        color: var(--vm-warn);
      }

      .vm-edit-link .plus {
        align-items: center;
        background: var(--vm-strip);
        border-radius: 50%;
        display: inline-flex;
        height: 22px;
        justify-content: center;
        width: 22px;
      }

      /* 8) Room card */
      .vm-name-bar {
        background: linear-gradient(to top, rgba(0, 0, 0, 0.72) 0%, rgba(0, 0, 0, 0.12) 100%);
        bottom: 0;
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 700;
        left: 0;
        letter-spacing: 0.02em;
        padding: 1.6rem 1.1rem 0.65rem;
        position: absolute;
        right: 0;
        text-align: right;
      }

      .vm-room-body {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        padding: 0.9rem 1.1rem 0.5rem;
      }

      .vm-room-loc {
        align-items: center;
        color: var(--vm-muted);
        display: inline-flex;
        font-size: 0.78rem;
        font-weight: 600;
        gap: 0.3rem;
      }

      .vm-room-meta {
        color: var(--vm-text);
        font-size: 0.85rem;
      }

      .vm-status {
        align-items: center;
        display: inline-flex;
        font-size: 0.8rem;
        font-weight: 600;
        gap: 0.4rem;
      }

      /* Hostel occupancy — takes the slot a venue room uses for capacity. */
      .vm-beds {
        align-items: center;
        color: var(--vm-text);
        display: inline-flex;
        font-size: 0.78rem;
        gap: 0.5rem;
      }
      .vm-bedstrip { display: inline-flex; flex: none; gap: 2px; }
      .vm-bedpip {
        background: #dcd9d3;
        border-radius: 2px;
        height: 8px;
        width: 8px;
      }
      .vm-bedpip.is-taken { background: var(--vm-dark); }

      .vm-dot {
        border-radius: 50%;
        height: 8px;
        width: 8px;
      }

      /* No "ok" dot: a room with nothing wrong shows no line at all. The green
         "Available" pill was the enum's whole reason for existing, and it was
         only ever restating the booking calendar. */
      .vm-dot-warn { background: var(--vm-warn); }
      .vm-dot-off { background: var(--vm-danger); }

      /* The review nag on an indefinite closure. Deliberately a question with
         its answer attached, not a status line — it is asking staff to act.
         Neutral surface + hairline + a dot, same as every other notice here. */
      .vm-review {
        align-items: center;
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        color: var(--vm-text);
        display: inline-flex;
        font-size: 0.75rem;
        gap: 0.4rem;
        margin-top: 0.15rem;
        padding: 0.35rem 0.55rem;
      }
      .vm-review::before {
        background: var(--vm-warn);
        border-radius: 50%;
        content: '';
        flex: none;
        height: 6px;
        width: 6px;
      }
      .vm-review a { color: var(--vm-text); font-weight: 600; text-decoration: underline; }

      .vm-price {
        background: var(--vm-dark);
        border-radius: 999px;
        color: #ffffff;
        font-size: 0.8rem;
        font-weight: 700;
        padding: 0.3rem 0.75rem;
      }

      /* 9) Carousel dots */
      .vm-dots {
        display: flex;
        gap: 6px;
        left: 12px;
        position: absolute;
        top: 10px;
      }

      .vm-dot-nav {
        background: rgba(255, 255, 255, 0.6);
        border: 0;
        border-radius: 50%;
        cursor: pointer;
        height: 7px;
        padding: 0;
        transition: background-color 0.18s ease, width 0.18s ease;
        width: 7px;
      }

      .vm-dot-nav.active {
        background: #ffffff;
        width: 18px;
        border-radius: 4px;
      }

      @media (max-width: 640px) {
        .vm-header {
          flex-direction: column;
        }
        .vm-actions {
          width: 100%;
        }
        .vm-actions .vm-btn {
          flex: 1;
          justify-content: center;
        }
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
           [4] PAGE CONTENT — the Venue Management page itself:
           title bar + "Add" buttons, search box, sort dropdown,
           then the VENUE cards grid and the ROOM cards grid.
           Cards link to venue-form.php / room-form.php.
           [SIM] every venue/room card below is hard-coded sample
           data — the real app will generate these cards from the
           database, so the hard-coded ones get deleted then.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
      <!-- Header -->
      <div class="vm-header">
        <div class="vm-heading">
          <h1>Venue Management</h1>
          <p>Manage locations and the rooms available under them.</p>
        </div>
        <div class="vm-actions">
          <a class="vm-btn vm-btn-primary" href="venue-form.php">+ Add Venue</a>
          <a class="vm-btn vm-btn-outline" href="room-form.php">+ Add Room</a>
        </div>
      </div>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
      <!-- Search + Sort -->
      <div class="vm-toolbar">
        <div class="vm-search-wrap">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#606a75" stroke-width="2">
            <circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" />
          </svg>
          <input class="vm-search" type="search" placeholder="Search rooms or venues..." aria-label="Search" />
        </div>
        <details class="vm-dd" id="venueSort">
          <summary class="vm-dd-trigger">All Venues</summary>
          <!-- Filter options come from includes/venues.php — add a venue there
               and it appears here (and in the room-form Location) automatically. -->
          <ul class="vm-dd-menu" aria-label="Sort by venue">
            <li class="vm-dd-option" data-venue="all">All Venues</li>
<?php foreach ($venues as $v): ?>
            <li class="vm-dd-option" data-venue="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars($v); ?></li>
<?php endforeach; ?>
          </ul>
        </details>
      </div>


      <!-- ============================================================
           USeP DISCOUNT RATE — ONE rate for every venue and the hostel
           (DB-DECISIONS #2: system_settings.discount_percent). It sits HERE,
           where the fees live, and ABOVE the venue cards so nobody reads it as
           per-venue. Admin only; staff will REQUEST changes once a staff UI
           exists (agreed 2026-09-14). A change is an EVENT — who/when/from/to/
           why — which is both the audit trail and the shape a request takes.
           [SIM] value → cookie; history → localStorage. Gone at DB time.
           Its own panel class, not .vm-card: that one is a grid tile that
           lifts on hover and clips overflow — wrong for a settings control. -->
      <div class="vm-section-title">Pricing <span>&mdash; one rate, every venue and the hostel</span></div>
      <section class="vm-setting" id="vmDiscount">
        <div class="vm-setting-main">
          <div class="vm-setting-rate"><span id="dcCurrent"><?php echo (int) $DISCOUNT_PERCENT; ?>%</span><small>off</small></div>
          <div class="vm-setting-copy">
            <div class="vm-setting-title">USeP discount rate <span class="vm-loc-badge">Admin only</span></div>
            <p>For USeP students, faculty and employees, once staff verify their USeP ID. Changing it affects <strong>new bookings only</strong> &mdash; existing bookings keep the rate they were quoted. <strong>0%</strong> switches the discount off.</p>
          </div>
          <div class="vm-setting-act">
            <button class="vm-btn vm-btn-primary" type="button" id="dcOpen" onclick="dcToggle(true)">Change rate</button>
          </div>
        </div>

        <form class="vm-setting-form" id="dcForm" hidden onsubmit="return false">
          <div class="vm-setting-field">
            <label for="dcRate">New rate</label>
            <div class="vm-setting-pct"><input id="dcRate" type="number" min="0" max="100" step="1" inputmode="numeric" value="<?php echo (int) $DISCOUNT_PERCENT; ?>"><span>%</span></div>
          </div>
          <div class="vm-setting-field vm-setting-field-wide">
            <label for="dcReason">Reason <span class="req">*</span></label>
            <input id="dcReason" type="text" placeholder="Required &mdash; recorded in the history below">
          </div>
          <div class="vm-setting-buttons">
            <button class="vm-btn vm-btn-primary" type="button" onclick="dcSave()">Save</button>
            <button class="vm-btn vm-btn-outline" type="button" onclick="dcToggle(false)">Cancel</button>
          </div>
          <div class="vm-setting-msg" id="dcErr" hidden></div>
          <div class="vm-setting-msg ok" id="dcSaved" hidden>Saved &mdash; new bookings now use this rate.</div>
        </form>

        <div class="vm-setting-hist">
          <div class="vm-setting-hist-title">Change history</div>
          <div id="dcHistory"></div>
        </div>
      </section>
      <!-- VENUES -->
      <div class="vm-section-title">Venues <span>&mdash; locations that hold rooms</span></div>
      <div class="vm-grid">
        <!-- Venue: Bahay Alumni (venues.id = 1) -->
        <div class="vm-card">
          <div class="vm-thumb">
<?php $venueCover = vp_cover_url(1); if ($venueCover): ?>
            <img src="<?php echo htmlspecialchars($venueCover); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
<?php else: ?>
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
              </svg>
            </div>
<?php endif; ?>
            <div class="vm-name-bar">Bahay Alumni</div>
          </div>
          <div class="vm-venue-body">
            <p class="vm-venue-desc">Heritage location for alumni events and functions.</p>
            <span class="vm-roomcount"><?php echo count(venueRoomsFor($venueRooms, 'Bahay Alumni')); ?> rooms</span>
            <div class="vm-staff-row">
              <div class="vm-avatars">
                <div class="vm-avatar">JD</div>
                <div class="vm-avatar">MS</div>
                <div class="vm-avatar">PR</div>
                <div class="vm-avatar vm-avatar-more">+2</div>
              </div>
              <span class="vm-staff-label">Assigned staff</span>
            </div>
          </div>
          <div class="vm-card-foot">
            <a class="vm-edit-link" href="venue-form.php?id=1"><span class="plus">✎</span> Edit Details</a>
          </div>
        </div>

        <!-- Venue: USeP Venues (venues.id = 2) -->
        <div class="vm-card">
          <div class="vm-thumb">
<?php $venueCover = vp_cover_url(2); if ($venueCover): ?>
            <img src="<?php echo htmlspecialchars($venueCover); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
<?php else: ?>
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
              </svg>
            </div>
<?php endif; ?>
            <div class="vm-name-bar">USeP Venues</div>
          </div>
          <div class="vm-venue-body">
            <p class="vm-venue-desc">Main campus location with halls and function rooms.</p>
            <span class="vm-roomcount"><?php echo count(venueRoomsFor($venueRooms, 'USeP Venues')); ?> rooms</span>
            <div class="vm-staff-row">
              <div class="vm-avatars">
                <div class="vm-avatar">AL</div>
                <div class="vm-avatar">CT</div>
                <div class="vm-avatar vm-avatar-more">+1</div>
              </div>
              <span class="vm-staff-label">Assigned staff</span>
            </div>
          </div>
          <div class="vm-card-foot">
            <a class="vm-edit-link" href="venue-form.php?id=2"><span class="plus">✎</span> Edit Details</a>
          </div>
        </div>

        <!-- Venue: USeP Hostel (venues.id = 3) — a venue like any other in the
             data model, so it is listed here rather than on a page of its own.
             What differs is how its rooms are BOOKED (per bed) and PAID
             (through CEDU), not what a venue is. Rooms come from
             includes/hostel-rooms.php. -->
        <div class="vm-card">
          <div class="vm-thumb">
<?php $venueCover = vp_cover_url(3); if ($venueCover): ?>
            <img src="<?php echo htmlspecialchars($venueCover); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
<?php else: ?>
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6M2 18h20M2 18v2M22 18v2M6 10V8a2 2 0 0 1 2-2h3v4" />
              </svg>
            </div>
<?php endif; ?>
            <div class="vm-name-bar"><?php echo htmlspecialchars($HOSTEL_VENUE); ?></div>
          </div>
          <div class="vm-venue-body">
            <p class="vm-venue-desc">Dormitory rooms booked per bed, not per room. Payment goes through CEDU.</p>
            <span class="vm-roomcount"><?php echo count($hostelRooms); ?> rooms · <?php echo array_sum(array_column($hostelRooms, 'beds')); ?> beds</span>
            <div class="vm-staff-row">
              <div class="vm-avatars">
                <div class="vm-avatar">RD</div>
                <div class="vm-avatar">TM</div>
              </div>
              <span class="vm-staff-label">Assigned staff</span>
            </div>
          </div>
          <div class="vm-card-foot">
            <a class="vm-edit-link" href="venue-form.php?id=3"><span class="plus">✎</span> Edit Details</a>
          </div>
        </div>
      </div>

      <!-- ROOMS -->
      <div class="vm-section-title">Rooms <span>&mdash; bookable units</span></div>
      <div class="vm-grid">
<?php $rn = 0; foreach ($rooms as $room): $rn++;
        /* the ONE place a room's maintenance line is worked out */
        $mt = vmMaint($room['maintenance'], $TODAY); ?>
        <div class="vm-card">
          <div class="vm-thumb" data-room="<?php echo $rn; ?>">
<?php $roomCover = rp_cover_url($room['id']); if ($roomCover): ?>
            <img src="<?php echo htmlspecialchars($roomCover); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
<?php else: ?>
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6M3 18h18M3 18v2M21 18v2M6 10V7a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3" />
              </svg>
            </div>
<?php endif; ?>
            <div class="vm-dots">
<?php for ($p = 1; $p <= $room['photos']; $p++): ?>
              <button class="vm-dot-nav<?php echo $p === 1 ? ' active' : ''; ?>" aria-label="Photo <?php echo $p; ?>"></button>
<?php endfor; ?>
            </div>
            <div class="vm-name-bar"><?php echo htmlspecialchars($room['name']); ?></div>
          </div>
          <div class="vm-room-body">
            <span class="vm-room-loc"><?php echo htmlspecialchars($room['venue']); ?></span>
            <span class="vm-room-meta">Capacity: <?php echo (int) $room['capacity']; ?> persons</span>
<?php if ($mt): ?>
            <span class="vm-status" title="<?php echo htmlspecialchars($mt['reason']); ?>"><span class="vm-dot <?php echo $mt['dot']; ?>"></span> <?php echo htmlspecialchars($mt['label']); ?></span>
<?php if ($mt['review']): ?>
            <!-- The rot guard. An indefinite closure is the only window that
                 cannot end on its own, so this is the only thing that will ever
                 ask about it. It asks, and it carries the answer (Reopen) —
                 a passive count would just become furniture on the page. -->
            <span class="vm-review">Closed <?php echo $mt['days']; ?> days &mdash; still closed?
              <a href="room-form.php?id=<?php echo urlencode($room['id']); ?>" title="[SIM] opens the room's maintenance window">Reopen</a>
            </span>
<?php endif; ?>
<?php endif; ?>
          </div>
          <div class="vm-card-foot">
            <a class="vm-edit-link" href="room-form.php?id=<?php echo urlencode($room['id']); ?>"><span class="plus">✎</span> Edit Room</a>
            <span class="vm-price">₱<?php echo number_format($room['fee'], 2); ?></span>
          </div>
        </div>
<?php endforeach; ?>

<?php /* ---------------------------------------------------------------
     HOSTEL ROOMS — same grid, same card, same maintenance line and the
     same review nag. Only two things differ, and both come straight from
     "booking is per bed":
       · CAPACITY is replaced by OCCUPANCY. "Capacity: 150 persons" is a
         property of the room; "4 of 6 beds free tonight" is a room x NIGHT
         fact, so the card has to say which night it means.
       · the price is per head per night, not per day.
     They link to hostel-room-form.php because the FIELDS differ (cr_type,
     beds, rate/head) — split where the data differs, share the machinery.
     --------------------------------------------------------------- */
      $hn = 0;
      foreach ($hostelRooms as $room): $hn++;
        $mt      = vmMaint($room['maintenance'], $TODAY);       // the very same helper
        $free    = hostelBedsFree($room, $TODAY);
        $taken   = hostelBedsTaken($room, $TODAY);
        $mix     = hostelGenderMix($room, $TODAY);
        $closedNow = $room['maintenance'] && $room['maintenance']['blocks'] && hostelMaintCovers($room['maintenance'], $TODAY); ?>
        <div class="vm-card">
          <div class="vm-thumb" data-room="<?php echo 100 + $hn; ?>">
<?php $roomCover = rp_cover_url($room['id']); if ($roomCover): ?>
            <img src="<?php echo htmlspecialchars($roomCover); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
<?php else: ?>
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6M2 18h20M2 18v2M22 18v2M6 10V8a2 2 0 0 1 2-2h3v4" />
              </svg>
            </div>
<?php endif; ?>
            <div class="vm-dots">
<?php for ($p = 1; $p <= $room['photos']; $p++): ?>
              <button class="vm-dot-nav<?php echo $p === 1 ? ' active' : ''; ?>" aria-label="Photo <?php echo $p; ?>"></button>
<?php endfor; ?>
            </div>
            <div class="vm-name-bar"><?php echo htmlspecialchars($room['name']); ?></div>
          </div>
          <div class="vm-room-body">
            <span class="vm-room-loc"><?php echo htmlspecialchars($HOSTEL_VENUE); ?></span>
            <span class="vm-room-meta"><?php echo (int) $room['beds']; ?> beds · <?php echo htmlspecialchars($HOSTEL_CR_LABEL[$room['cr_type']]); ?></span>
<?php if (!$closedNow): ?>
            <span class="vm-beds" title="Counted from the guest roster — never a stored number">
              <?php echo vmBedStrip($taken, $room['beds']); ?>
              <span><?php echo $free ? $free . ' of ' . (int) $room['beds'] . ' free tonight' : 'full tonight'; ?><?php echo $taken ? ' · ' . htmlspecialchars(vmMixLabel($mix)) : ''; ?></span>
            </span>
<?php endif; ?>
<?php if ($mt): ?>
            <span class="vm-status" title="<?php echo htmlspecialchars($mt['reason']); ?>"><span class="vm-dot <?php echo $mt['dot']; ?>"></span> <?php echo htmlspecialchars($mt['label']); ?></span>
<?php if ($mt['review']): ?>
            <span class="vm-review">Closed <?php echo $mt['days']; ?> days &mdash; still closed?
              <a href="hostel-room-form.php?id=<?php echo urlencode($room['id']); ?>" title="[SIM] opens the room's maintenance window">Reopen</a>
            </span>
<?php endif; ?>
<?php endif; ?>
          </div>
          <div class="vm-card-foot">
            <a class="vm-edit-link" href="hostel-room-form.php?id=<?php echo urlencode($room['id']); ?>"><span class="plus">✎</span> Edit Room</a>
            <span class="vm-price">₱<?php echo number_format($HOSTEL_RATES[$room['cr_type']]); ?>/head</span>
          </div>
        </div>
<?php endforeach; ?>
      </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — this page's own JS (UI-only, no backend):
         · photo carousel dots on each card
           [SIM] the dot clicks swap placeholder gradient "photos" —
           replace with real room images from the database later
         · live search filter over the cards
         · "venue" sort/filter dropdown + click-outside to close
         ============================================================ -->
    <script>
      // UI-only: carousel dots + simple search filter. No backend.
      document.querySelectorAll('.vm-dots').forEach((group) => {
        const dots = group.querySelectorAll('.vm-dot-nav');
        const thumb = group.closest('.vm-thumb');
        const shades = [
          'linear-gradient(135deg,#eef1f5,#dfe4ea)',
          'linear-gradient(135deg,#f3eee9,#e6ddd3)',
          'linear-gradient(135deg,#e9eef3,#d5e0ea)',
          'linear-gradient(135deg,#eef3ee,#d9e6da)',
          'linear-gradient(135deg,#f3eef1,#e6d5de)',
        ];
        dots.forEach((dot, i) => {
          dot.addEventListener('click', () => {
            dots.forEach((d) => d.classList.remove('active'));
            dot.classList.add('active');
            thumb.style.background = shades[i] || shades[0];
          });
        });
      });

      const search = document.querySelector('.vm-search');
      search.addEventListener('keyup', () => {
        const q = search.value.toLowerCase();
        document.querySelectorAll('.vm-card').forEach((card) => {
          card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      });

      // Sort / filter by venue (UI-only, same dropdown behavior as the calendar).
      const venueSort = document.getElementById('venueSort');
      if (venueSort) {
        venueSort.querySelectorAll('.vm-dd-option').forEach((opt) => {
          opt.addEventListener('click', () => {
            const venue = opt.dataset.venue;
            venueSort.querySelector('.vm-dd-trigger').textContent = opt.textContent;
            venueSort.removeAttribute('open');
            document.querySelectorAll('.vm-grid .vm-card').forEach((card) => {
              if (venue === 'all') {
                card.style.display = '';
                return;
              }
              const roomLoc = card.querySelector('.vm-room-loc');
              const venueName = card.querySelector('.vm-venue-body')
                ? card.querySelector('.vm-name-bar')
                : null;
              const name = roomLoc
                ? roomLoc.textContent.trim()
                : venueName
                  ? venueName.textContent.trim()
                  : '';
              card.style.display = name === venue ? '' : 'none';
            });
          });
        });
      }

      // Close any open dropdown when clicking outside of it.
      document.addEventListener('click', (e) => {
        document.querySelectorAll('.vm-dd[open]').forEach((dd) => {
          if (!dd.contains(e.target)) dd.removeAttribute('open');
        });
      });
    </script>
    <!-- [6b] DISCOUNT RATE SCRIPT [SIM] — the form is hidden until "Change rate"
         is clicked, so the resting state is one number and one sentence. A save
         records an EVENT and writes the cookie includes/pricing.php reads. -->
    <script>
      (function () {
        const HIST = 'venusep_discount_history';
        const $ = function (id) { return document.getElementById(id); };
        const esc = function (s) { return String(s == null ? "" : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };
        const readHist = function () { try { const v = JSON.parse(localStorage.getItem(HIST) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
        const fmt = function (iso) { const d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); };
        const drawHist = function () {
          const list = readHist().slice().reverse().slice(0, 6);
          $('dcHistory').innerHTML = list.length
            ? list.map(function (h) {
                return '<div class="vm-setting-row"><b>' + esc(h.from) + '% &rarr; ' + esc(h.to) + '%</b>'
                  + '<span class="who">' + esc(h.by) + ' &middot; ' + esc(fmt(h.at)) + '</span>'
                  + '<span class="why">' + esc(h.reason) + '</span></div>';
              }).join('')
            : '<div class="vm-setting-hist-empty">No changes yet &mdash; the rate is the default from system settings.</div>';
        };
        window.dcToggle = function (show) {
          $('dcForm').hidden = !show;
          $('dcOpen').hidden = show;
          $('dcErr').hidden = true; $('dcSaved').hidden = true;
          if (show) { $('dcRate').value = parseInt($('dcCurrent').textContent, 10); $('dcReason').value = ""; $('dcRate').focus(); }
        };
        window.dcSave = function () {
          const err = $('dcErr'), ok = $('dcSaved');
          err.hidden = true; ok.hidden = true;
          const raw = $('dcRate').value.trim(), reason = $('dcReason').value.trim();
          const cur = parseInt($('dcCurrent').textContent, 10);
          if (!/^\d{1,3}$/.test(raw) || parseInt(raw, 10) > 100) { err.textContent = 'Enter a whole number from 0 to 100.'; err.hidden = false; return; }
          const next = parseInt(raw, 10);
          if (next === cur) { err.textContent = 'That is already the current rate.'; err.hidden = false; return; }
          if (!reason) { err.textContent = 'A reason is required — it goes into the change history.'; err.hidden = false; return; }
          /* the value: a cookie, so the PHP listing and the JS booking pages read
             the same number (includes/pricing.php validates it again) */
          document.cookie = 'venusep_discount_percent=' + next + '; path=/; max-age=31536000; SameSite=Lax';
          /* the event: who / when / from / to / why */
          const hist = readHist();
          hist.push({ at: new Date().toISOString(), by: 'Administrator', from: cur, to: next, reason: reason });
          try { localStorage.setItem(HIST, JSON.stringify(hist)); } catch (e) { /* history is a nicety; the rate still saved */ }
          $('dcCurrent').textContent = next + '%';
          drawHist();
          $('dcForm').hidden = true; $('dcOpen').hidden = false;
          ok.hidden = false;
          setTimeout(function () { ok.hidden = true; }, 4000);
        };
        drawHist();
      })();
    </script>
  </body>
</html>
