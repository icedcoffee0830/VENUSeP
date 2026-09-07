<?php
/* Venues come from the ONE shared source so the Location dropdown lists every
   venue that exists — add a venue there and it appears here automatically. */
include __DIR__ . '/../includes/venues.php';
?>
<!DOCTYPE html>
<!-- ==================================================================
  ROOM FORM — VENUSeP merged system (ported from the AdminLTE mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  add/edit room form (gallery, 360°, fields, chip pools)
    [6] PAGE SCRIPT   dropdowns · panorama · gallery · chip pools [6a–6d]

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
    <title>VENUSeP | Room Form</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <!--begin::Pannellum 360 panorama viewer-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css" />
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
    <!--end::Pannellum 360 panorama viewer-->

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
         the file is self-contained. Grouped: gallery / fields / chip
         pools / panorama / lightbox / dropdowns / footer (see the
         /* … */ labels below).
         ============================================================ -->
    <style>
      /*
        Room form (Add / Edit) page UI CSS
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

      /* Gallery, matched to the customer UI layout: big 360 tile on the left,
         two stacked photos on the right, thumbnail strip underneath */
      .vm-gallery {
        display: grid;
        gap: 8px;
        /* every tile is a square: 400x400 main + two 196x196 stacked */
        grid-template-columns: 400px 196px;
        grid-template-rows: 196px 196px;
        max-width: 100%;
        position: relative;
        width: 604px;
      }

      .vm-shot {
        align-items: center;
        border-radius: 8px;
        color: #b7bfc9;
        display: flex;
        justify-content: center;
        overflow: hidden;
        position: relative;
      }

      .vm-gallery .vm-shot:nth-child(1) {
        grid-column: 1;
        grid-row: 1 / span 2;
        background: linear-gradient(135deg, #eef1f5, #dfe4ea);
      }
      .vm-gallery .vm-shot:nth-child(2) { background: linear-gradient(135deg, #f3eee9, #e6ddd3); }
      .vm-gallery .vm-shot:nth-child(3) { background: linear-gradient(135deg, #e9eef3, #d5e0ea); }

      .vm-thumbs {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(5, 1fr);
        margin-top: 8px;
        max-width: 100%;
        width: 604px;
      }
      .vm-thumbs .vm-shot { aspect-ratio: 1 / 1; border-radius: 10px; }

      /* Top section: pictures + Amenities on the left, primary fields +
         At a glance on the right, separated by a vertical hairline;
         stacks on narrow screens */
      .vm-top {
        display: grid;
        gap: 1.75rem;
        grid-template-columns: 604px minmax(0, 1fr);
      }

      .vm-top-fields {
        border-left: 1px solid var(--vm-border);
        padding-left: 1.75rem;
      }

      @media (max-width: 1280px) {
        .vm-top { grid-template-columns: 1fr; }
        .vm-top-fields { border-left: 0; border-top: 1px solid var(--vm-border); padding-left: 0; padding-top: 1.5rem; }
      }
      .vm-thumbs .vm-shot:nth-child(1) { background: linear-gradient(135deg, #eef3ee, #d9e6da); }
      .vm-thumbs .vm-shot:nth-child(2) { background: linear-gradient(135deg, #f3eef1, #e6d5de); }
      .vm-thumbs .vm-shot:nth-child(3) { background: linear-gradient(135deg, #eaf0f3, #d5e2ea); }
      .vm-thumbs .vm-shot:nth-child(4) { background: linear-gradient(135deg, #f2efe8, #e4dccf); }
      .vm-thumbs .vm-shot:nth-child(5) { background: linear-gradient(135deg, #eef3f0, #dbe7df); }

      .vm-edit-photos {
        align-items: center;
        background: rgba(31, 30, 30, 0.85);
        border: 0;
        border-radius: 999px;
        bottom: 10px;
        color: #ffffff;
        cursor: pointer;
        display: inline-flex;
        font-family: inherit;
        font-size: 0.78rem;
        font-weight: 600;
        gap: 0.35rem;
        padding: 0.4rem 0.8rem;
        position: absolute;
        right: 10px;
      }

      .vm-divider {
        border: 0;
        border-top: 1px solid var(--vm-border);
        margin: 1.5rem 0;
      }

      /* Fields */
      .vm-row {
        display: grid;
        gap: 1.1rem;
        grid-template-columns: 1fr 1fr;
      }

      .vm-row-3 {
        display: grid;
        gap: 1.1rem;
        grid-template-columns: repeat(3, 1fr);
      }

      .vm-field { margin-bottom: 1.1rem; }

      /* Maintenance section (replaced the Availability Status dropdown) */
      .vm-mt-toggle {
        display: inline-flex;
        align-items: center;
        gap: .6rem;
        font-size: .9rem;
        font-weight: 600;
        cursor: pointer;
        margin-bottom: .2rem;
      }
      .vm-mt-toggle input { width: 16px; height: 16px; accent-color: #1f1e1e; cursor: pointer; }
      .vm-mt-body { margin-top: 1.1rem; }
      .vm-mt-tiers { display: grid; gap: .6rem; grid-template-columns: 1fr 1fr; }
      .vm-mt-tier {
        display: flex;
        align-items: flex-start;
        gap: .6rem;
        border: 1px solid var(--vm-border);
        border-radius: 10px;
        padding: .7rem .8rem;
        cursor: pointer;
        transition: border-color .15s, background .15s;
      }
      .vm-mt-tier:hover { background: #fbfbfa; }
      .vm-mt-tier:has(input:checked) { border-color: #1f1e1e; background: #fbfbfa; }
      .vm-mt-tier input { margin-top: .15rem; accent-color: #1f1e1e; cursor: pointer; }
      .vm-mt-tier span { display: block; }
      .vm-mt-tier strong { display: block; font-size: .85rem; font-weight: 650; }
      .vm-mt-tier em { display: block; font-style: normal; font-size: .76rem; line-height: 1.45; color: #8a857d; margin-top: .15rem; }
      .vm-mt-indef {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        font-size: .78rem;
        color: #6b675f;
        margin-top: .45rem;
        cursor: pointer;
      }
      .vm-mt-indef input { accent-color: #1f1e1e; cursor: pointer; }
      .vm-mt-echo {
        border: 1px solid var(--vm-border);
        border-radius: 10px;
        padding: .75rem .85rem;
        font-size: .8rem;
        line-height: 1.5;
        color: #6b675f;
      }
      .vm-mt-echo b { color: #1f1e1e; font-weight: 650; }
      .vm-mt-echo .vm-mt-warn { color: #8a5a12; }
      @media (max-width: 700px) { .vm-mt-tiers { grid-template-columns: 1fr; } }

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

      /* At-a-glance / Amenities pools (multi-select icon chips) */
      .vm-section-head {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 0.25rem;
      }

      .vm-hint {
        color: var(--vm-muted);
        font-size: 0.82rem;
        margin: 0 0 0.9rem;
      }

      .vm-poolgrid {
        display: grid;
        gap: 0.55rem;
        grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      }

      .vm-chip {
        align-items: center;
        background: #ffffff;
        border: 1px solid var(--vm-border);
        border-radius: 8px;
        color: var(--vm-muted);
        cursor: pointer;
        display: flex;
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 400;
        gap: 0.55rem;
        padding: 0.55rem 0.7rem;
        text-align: left;
        transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
      }

      .vm-chip svg { flex: none; }

      .vm-chip:hover {
        background: var(--vm-hover);
        border-color: #cfcac2;
      }

      .vm-chip.selected {
        background: #eef7f1;
        border-color: #d3ebe0;
        box-shadow: none;
        color: #3a9b6e;
        font-weight: 400;
      }

      .vm-chip .vm-chip-check {
        margin-left: auto;
        opacity: 0;
      }

      .vm-chip.selected .vm-chip-check { opacity: 1; }

      /* 360 panorama controls (overlaid on the main gallery tile) */
      .vm-pano-tools-abs {
        bottom: 10px;
        display: flex;
        gap: 6px;
        left: 10px;
        position: absolute;
        z-index: 5;
      }

      .vm-pano-btn {
        cursor: pointer;
        position: static;
      }

      .vm-pano-btn input { display: none; }

      /* Photo gallery manager (lightbox) */
      .vm-galbig {
        align-items: center;
        aspect-ratio: 16 / 9;
        background: var(--vm-strip);
        border-radius: 12px;
        display: flex;
        justify-content: center;
        overflow: hidden;
      }

      .vm-galempty { color: #9a958c; font-size: 0.9rem; }

      .vm-galgrid {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        margin-top: 14px;
      }

      .vm-galthumb {
        aspect-ratio: 4 / 3;
        background: var(--vm-strip);
        border: 2px solid transparent;
        border-radius: 8px;
        cursor: pointer;
        overflow: hidden;
        padding: 0;
        position: relative;
      }

      .vm-galthumb.sel { border-color: var(--vm-dark); }

      .vm-galfeat {
        background: var(--vm-dark);
        border-radius: 5px;
        bottom: 5px;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        left: 5px;
        padding: 2px 6px;
        position: absolute;
      }

      /* Custom dropdowns styled exactly like the calendar "Date" sort dropdown */
      .vm-dd {
        position: relative;
        width: 100%;
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
        font-size: 0.9rem;
        font-weight: 700;
        list-style: none;
        padding: 0.6rem 2.2rem 0.6rem 0.8rem;
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
        font-size: 0.9rem;
        padding: 0.55rem 0.8rem;
      }

      .vm-dd-option:hover {
        background: var(--vm-hover);
      }

      /* Footer */
      .vm-form-foot {
        display: flex;
        gap: 0.6rem;
        justify-content: flex-end;
        margin-top: 1.75rem;
      }

      /* under the images the divider already provides the spacing */
      .vm-top-media .vm-form-foot { margin-top: 0; }

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
        align-items: center;
        background: #ffffff;
        color: var(--vm-text);
        display: inline-flex;
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

      @media (max-width: 560px) {
        .vm-row, .vm-row-3 { grid-template-columns: 1fr; }
        .vm-gallery { grid-template-columns: 240px 116px; grid-template-rows: 116px 116px; width: 364px; }
        .vm-thumbs { width: 364px; }
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
           [4] PAGE CONTENT — the room form itself: back link, title,
           photo gallery + 360° panorama tile, room fields (name,
           location, capacity, rate), the Maintenance window,
           At-a-glance + Amenities chip pools, then the Save/Cancel footer.
           [SIM] the pre-filled values and placeholder photos are
           sample data — the real app will load the room by its ?id=
           from the database (blank form = Add, loaded form = Edit).
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="vm-page">
      <a class="vm-back" href="venue-management.php">&larr; Back to Venue Management</a>
      <h1 class="vm-title">Edit Room Details</h1>
      <p class="vm-subtitle">Rooms are the bookable units. Customers see these photos.</p>

      <form class="vm-panel" onsubmit="return false">
        <!-- top section: pictures left, primary fields fill the space beside them -->
        <div class="vm-top">
        <div class="vm-top-media">
        <!-- gallery, customer-UI layout: big 360 left + two stacked photos right -->
        <div class="vm-gallery">
          <div class="vm-shot" id="vmPanoView">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-pano-tools-abs">
            <label class="vm-edit-photos vm-pano-btn">
              <input type="file" accept="image/*" onchange="vmLoadPano(this)" />
              ◐ Add / replace 360°
            </label>
            <button type="button" class="vm-edit-photos vm-pano-btn" onclick="vmClearPano()">Remove 360°</button>
          </div>
        </div>
        <!-- thumbnail strip, last tile carries the photo manager button -->
        <div class="vm-thumbs">
          <div class="vm-shot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
            <button type="button" class="vm-edit-photos" onclick="vmOpenGallery()">✎ Edit photos</button>
          </div>
        </div>

        <hr class="vm-divider" />

        <div class="vm-row">
          <div class="vm-field">
            <label for="roomName">Room Name</label>
            <input id="roomName" class="vm-control" type="text" value="Function Hall" placeholder="e.g. Function Hall" />
          </div>
          <div class="vm-field">
            <label>Location (Venue)</label>
            <!-- Options come from includes/venues.php — every venue, data-driven.
                 [SIM] the summary shows the room's current venue; the DB loads it. -->
            <details class="vm-dd" id="roomLoc">
              <summary class="vm-dd-trigger">Bahay Alumni</summary>
              <ul class="vm-dd-menu" aria-label="Location options">
                <?php echo venueOptions($venues); ?>
              </ul>
            </details>
          </div>
        </div>

        <hr class="vm-divider" />

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="venue-management.php">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary">Save Changes</button>
        </div>
        </div>

        <div class="vm-top-fields">
        <div class="vm-row">
          <div class="vm-field">
            <label for="roomCap">Capacity (persons)</label>
            <input id="roomCap" class="vm-control" type="number" value="150" placeholder="e.g. 150" />
          </div>
          <div class="vm-field">
            <label for="roomRate">Rate (₱)</label>
            <input id="roomRate" class="vm-control" type="number" step="0.01" value="4000.00" placeholder="0.00" />
          </div>
        </div>

        <hr class="vm-divider" />

        <!-- ==========================================================
             MAINTENANCE — this replaced the old "Availability Status"
             dropdown (Available / Occupied / Maintenance).
             Available and Occupied were never settings: they are just
             what the booking data already says, so a staff member
             choosing them could only ever DISAGREE with the calendar.
             (A room really did sit at "Maintenance" while its booking
             form happily took reservations.) Only a closure is a real
             decision, and a closure has DATES — so that is what is
             stored, and the customer-facing label is derived from it.
             [SIM] nothing saves yet.
             ========================================================== -->
        <h2 class="vm-section-head">Maintenance</h2>
        <p class="vm-hint">A closure is a <strong>date window</strong>, not a label — the window is what actually stops bookings. Leave this off and the room behaves normally; there is no separate &ldquo;Available&rdquo; setting to keep in sync.</p>

        <label class="vm-mt-toggle">
          <input type="checkbox" id="mtOn" onchange="mtSync()" />
          <span>Put this room under maintenance</span>
        </label>

        <div class="vm-mt-body" id="mtBody" hidden>
          <div class="vm-field">
            <label>Type</label>
            <div class="vm-mt-tiers">
              <label class="vm-mt-tier">
                <input type="radio" name="mtTier" value="medium" checked onchange="mtSync()" />
                <span><strong>Partial</strong><em>Still bookable. The customer is shown a notice before they reserve.</em></span>
              </label>
              <label class="vm-mt-tier">
                <input type="radio" name="mtTier" value="hard" onchange="mtSync()" />
                <span><strong>Closed</strong><em>Cannot be booked on these dates. Bookings already inside the window are listed for you to resolve — nothing is cancelled automatically.</em></span>
              </label>
            </div>
          </div>

          <div class="vm-row">
            <div class="vm-field">
              <label for="mtFrom">From</label>
              <input id="mtFrom" class="vm-control" type="date" onchange="mtSync()" />
            </div>
            <div class="vm-field">
              <label for="mtUntil">Until</label>
              <input id="mtUntil" class="vm-control" type="date" onchange="mtSync()" />
              <label class="vm-mt-indef">
                <input type="checkbox" id="mtIndef" onchange="mtSync()" />
                <span>No set return date (indefinite)</span>
              </label>
            </div>
          </div>

          <div class="vm-field">
            <label for="mtReason">Reason</label>
            <input id="mtReason" class="vm-control" type="text" placeholder="e.g. Roof repair" oninput="mtSync()" />
            <div class="vm-hint" style="margin:.4rem 0 0">Staff see this on the room card; customers see it on the notice.</div>
          </div>

          <!-- Preview: proves the point of the whole section — the label is
               computed from the window above, never typed or stored. -->
          <div class="vm-mt-echo" id="mtEcho"></div>
        </div>

        <hr class="vm-divider" />

        <h2 class="vm-section-head">At a glance</h2>
        <p class="vm-hint">Shown on the customer's room page. Fill the free-text fields, then tap the attributes that apply. (Reservation fee uses the Rate above.)</p>

        <div class="vm-row">
          <div class="vm-field">
            <label for="roomBestFor">Best for</label>
            <input id="roomBestFor" class="vm-control" type="text" value="Seminars · Trainings · Org events" placeholder="e.g. Seminars · Trainings · Ceremonies" />
          </div>
          <div class="vm-field">
            <label for="roomHours">Bookable hours</label>
            <input id="roomHours" class="vm-control" type="text" value="7 AM – 10 PM daily" placeholder="e.g. 7 AM – 10 PM daily" />
          </div>
        </div>

        <div class="vm-field">
          <label>Attributes</label>
          <div id="vmGlancePool" class="vm-poolgrid"></div>
        </div>

        <hr class="vm-divider" />

        <h2 class="vm-section-head">Amenities</h2>
        <p class="vm-hint">Tap every amenity this room offers. Each carries its own icon on the customer page.</p>
        <div id="vmAmenityPool" class="vm-poolgrid"></div>
        </div>
        </div>
      </form>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6a] PAGE SCRIPT: custom dropdowns — picking an option sets
         the trigger text and closes the dropdown; clicking outside
         closes any open dropdown. (UI-only, no backend.)
         ============================================================ -->
    <script>
      // UI-only custom dropdowns (same behavior as the calendar sort dropdown). No backend.
      document.querySelectorAll('.vm-dd').forEach((dd) => {
        dd.querySelectorAll('.vm-dd-option').forEach((opt) => {
          opt.addEventListener('click', () => {
            dd.querySelector('.vm-dd-trigger').textContent = opt.textContent;
            dd.removeAttribute('open');
          });
        });
      });
      // Close any open dropdown when clicking outside of it.
      document.addEventListener('click', (e) => {
        document.querySelectorAll('.vm-dd[open]').forEach((dd) => {
          if (!dd.contains(e.target)) dd.removeAttribute('open');
        });
      });
    </script>
    <!-- ============================================================
         [6a2] PAGE SCRIPT: maintenance window — shows/hides the window
         fields and previews the customer-facing label. The preview is
         COMPUTED from the dates below it, which is the whole point of
         the section: there is no label to store, so there is nothing
         that can disagree with the booking calendar. (UI-only.)
         ============================================================ -->
    <script>
      var MT_FMT = { month: 'short', day: 'numeric', year: 'numeric' };
      function mtDate(v) {
        if (!v) return '';
        var d = new Date(v + 'T00:00:00');
        return isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-US', MT_FMT);
      }
      function mtSync() {
        var on = document.getElementById('mtOn').checked;
        document.getElementById('mtBody').hidden = !on;
        if (!on) return;

        var hard   = document.querySelector('input[name="mtTier"][value="hard"]').checked;
        var indef  = document.getElementById('mtIndef').checked;
        var from   = document.getElementById('mtFrom').value;
        var until  = document.getElementById('mtUntil');
        var reason = document.getElementById('mtReason').value.trim();

        // "Indefinite" means no END date — not no date. The Until field is the
        // only thing it switches off.
        until.disabled = indef;
        if (indef) until.value = '';

        var echo = document.getElementById('mtEcho');
        if (!from) { echo.innerHTML = 'Pick a <b>From</b> date to see what the customer will be shown.'; return; }

        var when = indef ? 'no set return date'
                 : (until.value ? 'until ' + mtDate(until.value) : '…');
        var head = hard ? 'Closed for maintenance' : 'Partial maintenance';
        var says = hard
          ? 'The room cannot be booked on these dates. Customers picking one are told why.'
          : 'The room stays bookable. Customers are shown a notice before they reserve.';
        var warn = (indef && hard)
          ? '<div class="vm-mt-warn" style="margin-top:.45rem">Indefinite closures are listed on Venue Management once they pass 14 days, so the room cannot be closed and forgotten.</div>'
          : '';
        echo.innerHTML = 'Customers will see: <b>' + head + ' · ' + when + '</b>'
          + (reason ? ' <b>(' + reason.replace(/</g, '&lt;') + ')</b>' : '')
          + '<div style="margin-top:.3rem">' + says + '</div>' + warn;
      }
      mtSync();
    </script>
    <!-- 360 panorama: upload an equirectangular image and preview it live -->
    <!-- ============================================================
         [6b] PAGE SCRIPT: 360° panorama — loads a sample panorama on
         start; "Add / replace panorama" feeds an uploaded image into
         a live Pannellum viewer (drag / zoom to check it works).
         [SIM] the start-up sample image is a demo, and an uploaded
         panorama lives only in the browser — the real app will save
         the upload to the server / database.
         ============================================================ -->
    <script>
      var vmPano = null;
      var VM_SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';
      function vmInitPano(src) {
        if (typeof pannellum === 'undefined') { return; }
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML = '';
        vmPano = pannellum.viewer('vmPanoView', {
          type: 'equirectangular', panorama: src, autoLoad: true, showControls: true,
          compass: true, hfov: 100, minHfov: 45, maxHfov: 120, mouseZoom: true, draggable: true,
        });
      }
      function vmLoadPano(input) {
        var f = input.files && input.files[0];
        if (!f) return;
        vmInitPano(URL.createObjectURL(f));
      }
      function vmClearPano() {
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML =
          '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';
      }
      // Load a sample on start so the viewer is visibly working (Edit state).
      vmInitPano(VM_SAMPLE_PANO);
    </script>
    <!-- Photo gallery manager: add photos (venue uploads only) + pick the cover -->
    <!-- ============================================================
         [6c] PAGE SCRIPT: photo gallery manager — the "Room photos"
         lightbox overlay: upload photos, click a thumbnail to choose
         the Cover image.
         [SIM] uploaded photos live only in the browser (gone on
         refresh) — the real app will save them to the server.
         ============================================================ -->
    <script>
      var VMPHOTOS = [];
      var VMSEL = 0;
      function vmOpenGallery() {
        if (document.getElementById('vmGalOverlay')) return;
        var ov = document.createElement('div');
        ov.id = 'vmGalOverlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(20,18,15,.75);z-index:3000;display:flex;align-items:center;justify-content:center;padding:24px';
        ov.innerHTML =
          '<div style="background:#fff;border-radius:14px;max-width:960px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;font-family:var(--vm-font);color:var(--vm-text)">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--vm-border)"><div style="font-weight:700">Room photos</div><button type="button" onclick="vmCloseGallery()" style="background:none;border:none;font-size:20px;color:#606a75;cursor:pointer">&times;</button></div>' +
          '<div style="padding:16px 18px;overflow:auto">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><div style="font-weight:700;font-size:.95rem">Venue uploads</div><label class="vm-btn vm-btn-outline vm-pano-btn" style="min-height:2rem;padding:0 .9rem;display:inline-flex;align-items:center">Add photos<input type="file" accept="image/*" multiple onchange="vmGalAdd(this)" /></label></div>' +
          '<div id="vmGalBig" class="vm-galbig"></div>' +
          '<div id="vmGalGrid" class="vm-galgrid"></div>' +
          '</div></div>';
        ov.addEventListener('click', function (e) { if (e.target === ov) vmCloseGallery(); });
        document.body.appendChild(ov);
        vmGalRender();
      }
      function vmGalAdd(input) {
        var files = input.files; if (!files) return;
        for (var i = 0; i < files.length; i++) VMPHOTOS.push(URL.createObjectURL(files[i]));
        VMSEL = VMPHOTOS.length - 1;
        vmGalRender();
      }
      function vmGalSelect(i) { VMSEL = i; vmGalRender(); }
      function vmGalRender() {
        var big = document.getElementById('vmGalBig');
        var grid = document.getElementById('vmGalGrid');
        if (!grid) return;
        if (VMPHOTOS.length === 0) {
          if (big) big.innerHTML = '<div class="vm-galempty">No photos yet — click "Add photos".</div>';
          grid.innerHTML = '';
          return;
        }
        if (VMSEL >= VMPHOTOS.length) VMSEL = 0;
        if (big) big.innerHTML = '<img src="' + VMPHOTOS[VMSEL] + '" style="width:100%;height:100%;object-fit:cover" alt="" />';
        grid.innerHTML = VMPHOTOS.map(function (u, i) {
          return '<button type="button" class="vm-galthumb' + (i === VMSEL ? ' sel' : '') + '" onclick="vmGalSelect(' + i + ')"><img src="' + u + '" style="width:100%;height:100%;object-fit:cover" alt="" />' + (i === VMSEL ? '<span class="vm-galfeat">Cover</span>' : '') + '</button>';
        }).join('');
      }
      function vmCloseGallery() { var ov = document.getElementById('vmGalOverlay'); if (ov) ov.remove(); }
    </script>
    <!-- ============================================================
         [6d] PAGE SCRIPT: At-a-glance & Amenities pools — the fixed
         hard-coded {label, icon} catalogs, chip toggling (staff just
         pick what applies), and the "Other" free-text chip. Same
         pools the customer page reads from.
         [SIM] chip selections are not saved anywhere yet — the real
         app will store the chosen tags on the room record. (The
         catalogs themselves are meant to stay hard-coded.)
         ============================================================ -->
    <script>
      (function () {
        var S = function (inner) {
          return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>';
        };
        var I = {
          dome: S('<path d="M12 5a7 7 0 0 0-7 7v3h14v-3a7 7 0 0 0-7-7z"/><line x1="12" y1="3" x2="12" y2="5"/><line x1="2" y1="19" x2="22" y2="19"/>'),
          large: S('<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>'),
          small: S('<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/>'),
          car: S('<path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/><line x1="5" y1="11" x2="19" y2="11"/>'),
          access: S('<circle cx="16" cy="4" r="1"/><path d="m18 19 1-7-6 1"/><path d="m5 8 3-3 5.5 3-2.36 3.5"/><path d="M4.24 14.5a5 5 0 0 0 6.88 6"/><path d="M13.76 17.5a5 5 0 0 0-6.88-6"/>'),
          wind: S('<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M12.59 19.41A2 2 0 1 0 14 16H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/>'),
          sun: S('<circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>'),
          window: S('<rect x="4" y="3" width="16" height="18" rx="1"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="4" y1="12" x2="20" y2="12"/>'),
          stage: S('<rect x="3" y="4" width="18" height="11" rx="1"/><line x1="12" y1="15" x2="12" y2="19"/><line x1="8" y1="19" x2="16" y2="19"/>'),
          wifi: S('<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>'),
          door: S('<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><circle cx="15" cy="12" r="1"/>'),
          monitor: S('<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'),
          speaker: S('<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/>'),
          mic: S('<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>'),
          podium: S('<rect x="7" y="4" width="10" height="7" rx="1"/><path d="M9 21V11h6v10"/><path d="M7 21h10"/><line x1="12" y1="11" x2="12" y2="13"/>'),
          board: S('<rect x="3" y="3" width="18" height="14" rx="1"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="7" y1="21" x2="17" y2="21"/>'),
          chair: S('<path d="M5 19h14"/><path d="M6 19v2"/><path d="M18 19v2"/><path d="M6 15V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v9"/><path d="M6 12h12"/>'),
          table: S('<rect x="3" y="7" width="18" height="4" rx="1"/><line x1="6" y1="11" x2="6" y2="18"/><line x1="18" y1="11" x2="18" y2="18"/>'),
          coffee: S('<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'),
          power: S('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
          camera: S('<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>'),
          box: S('<path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/>'),
        };

        // Hard-coded pools. `on:true` = pre-selected (this is the Edit state).
        var GLANCE = [
          { l: 'Catering', i: I.dome, on: true },
          { l: 'Large area', i: I.large, on: true },
          { l: 'Small area', i: I.small },
          { l: 'On-site parking', i: I.car },
          { l: 'Wheelchair accessible', i: I.access, on: true },
          { l: 'Air-conditioned', i: I.wind, on: true },
          { l: 'Outdoor / semi-outdoor', i: I.sun },
          { l: 'Natural lighting', i: I.window },
          { l: 'Stage available', i: I.stage, on: true },
          { l: 'Wi-Fi', i: I.wifi, on: true },
          { l: 'Near restrooms', i: I.door },
        ];
        var AMENITIES = [
          { l: 'Projector &amp; screen', i: I.monitor, on: true },
          { l: 'TV / HDMI', i: I.monitor },
          { l: 'Sound system', i: I.speaker, on: true },
          { l: 'Microphones', i: I.mic, on: true },
          { l: 'Podium', i: I.podium },
          { l: 'Whiteboard', i: I.board },
          { l: 'Chairs', i: I.chair, on: true },
          { l: 'Tables', i: I.table, on: true },
          { l: 'Coffee station', i: I.coffee },
          { l: 'Power outlets', i: I.power, on: true },
          { l: 'Video-conference camera', i: I.camera },
          { l: 'Backstage / prep room', i: I.door },
          { l: 'Load-in access', i: I.box },
        ];

        var CHECK = '<svg class="vm-chip-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        function build(id, arr) {
          var el = document.getElementById(id);
          if (!el) return;
          el.innerHTML = arr
            .map(function (o) {
              return (
                '<button type="button" class="vm-chip' + (o.on ? ' selected' : '') + '" aria-pressed="' + (o.on ? 'true' : 'false') + '">' +
                o.i + '<span>' + o.l + '</span>' + CHECK + '</button>'
              );
            })
            .join('');
        }

        build('vmGlancePool', GLANCE);
        build('vmAmenityPool', AMENITIES);

        // Toggle a chip on click (mockup: just flips the visual selected state).
        document.addEventListener('click', function (e) {
          var chip = e.target.closest ? e.target.closest('.vm-chip') : null;
          if (!chip) return;
          var on = chip.classList.toggle('selected');
          chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      })();
    </script>
  </body>
</html>
