@extends('layouts.admin')
@section('title', 'Hostel Room Form')
@section('active', 'Venue Management')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    <!--begin::Pannellum 360 panorama viewer-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css" />
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <!--end::Pannellum 360 panorama viewer-->
@endpush

@push('styles')
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
        /* 2 side shots + 3 thumbs = 5 tiles total, matching the 5-photo cap
           (ROOM_PHOTO_MAX_COUNT) — no tile is ever left with nothing to show. */
        grid-template-columns: repeat(3, 1fr);
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
@endpush

@section('content')
      <!-- ==========================================================
           [4] PAGE CONTENT — the room form itself: back link, title,
           photo gallery + 360° panorama tile, room fields (name,
           venue, BEDS, CR type, rate per head/night), the Maintenance
           window, At-a-glance + Amenities chip pools (the HOSTEL
           catalogs), then the Save/Cancel footer.
           [SIM] the pre-filled values and placeholder photos are
           sample data — the real app will load the room by its ?id=
           from the database (blank form = Add, loaded form = Edit).
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="vm-page">
      <a class="vm-back" href="{{ route('admin.venue-management') }}">&larr; Back to Venue Management</a>
      <h1 class="vm-title">{{ $roomId ? 'Edit Hostel Room' : 'Add Hostel Room' }}</h1>
      <p class="vm-subtitle">Hostel rooms hold beds. Guests book a bed, not the room — so there is no capacity here, only how many beds exist and what one costs per night.@if (!$roomId) Photos can be added once the room is saved and has an id.@endif</p>

      <form class="vm-panel" onsubmit="return false">
        <input type="hidden" id="vmRoomId" value="{{ $roomId }}" />
        <input type="hidden" id="vmCsrf" value="{{ $roomFormCsrf }}" />
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
          <div class="vm-shot" id="vmSideShot0">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot" id="vmSideShot1">
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
          <div class="vm-shot" id="vmThumb0">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot" id="vmThumb1">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
              <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" />
            </svg>
          </div>
          <div class="vm-shot" id="vmThumb2">
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
            <input id="roomName" class="vm-control" type="text" value="{{ $roomFormRoom ? $roomFormRoom['name'] : '' }}" placeholder="e.g. Hostel Room 1" />
          </div>
          <div class="vm-field">
            <label>Location (Venue)</label>
            <!-- Options come from includes/venues.php — every venue, data-driven.
                 The summary + saved value come from rooms.venue_id via the DB
                 overlay in includes/hostel-rooms.php. -->
            <details class="vm-dd" id="roomLoc">
              <summary class="vm-dd-trigger">{{ $roomFormRoom && isset($roomFormRoom['venue']) ? $roomFormRoom['venue'] : 'Select a venue' }}</summary>
              <ul class="vm-dd-menu" aria-label="Location options">
                {!! venueOptions($venues) !!}
              </ul>
            </details>
          </div>
        </div>

        <hr class="vm-divider" />

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="{{ route('admin.venue-management') }}">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary" onclick="vmSaveChanges()">Save Changes</button>
        </div>
        </div>

        <div class="vm-top-fields">
        <!-- No "Capacity". A hostel room's capacity IS its bed count, and how
             full it is on a given night is counted from the guest roster — not
             typed in here. Occupancy is a room x NIGHT fact; this form only
             describes the room. -->
        <div class="vm-row-3">
          <div class="vm-field">
            <label for="roomBeds">Beds</label>
            <!-- the room's REAL values (2026-10-27) — these used to open at
                 6 beds / ₱350 / communal for every room -->
            <input id="roomBeds" class="vm-control" type="number" min="1" max="20" value="{{ $roomFormRoom ? (int) $roomFormRoom['beds'] : 6 }}" placeholder="e.g. 6" />
            <div class="vm-hint" style="margin:.4rem 0 0">How many beds exist in the room. Beds with upcoming guests cannot be removed.</div>
          </div>
          <div class="vm-field">
            <label>CR type</label>
            <details class="vm-dd" id="roomCr">
              <summary class="vm-dd-trigger">{{ ($roomFormRoom && $roomFormRoom['cr_type'] === 'private') ? 'Private CR' : 'Communal CR' }}</summary>
              <ul class="vm-dd-menu" aria-label="CR type options">
                <li class="vm-dd-option" data-rate="350">Communal CR</li>
                <li class="vm-dd-option" data-rate="400">Private CR</li>
              </ul>
            </details>
            <div class="vm-hint" style="margin:.4rem 0 0">Sets the rate and the room layout.</div>
          </div>
          <div class="vm-field">
            <label for="roomRate">Rate (₱ per head, per night)</label>
            <input id="roomRate" class="vm-control" type="number" min="0" step="0.01" value="{{ $roomFormRoom ? number_format((float) $roomFormRoom['rate'], 2, '.', '') : '350.00' }}" placeholder="0.00" />
            <div class="vm-hint" style="margin:.4rem 0 0">Per bed, per night. A new rate applies to new bookings; existing stays keep their price.</div>
          </div>
        </div>
        <div class="vm-field">
          <label for="roomDesc">Description</label>
          <textarea id="roomDesc" class="vm-control" rows="3" placeholder="What the room is like, shown at the top of its page">{{ $roomFormRoom ? $roomFormRoom['description'] : '' }}</textarea>
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
             Saved by admin/room-save.php.
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
        <p class="vm-hint">Shown on the guest's room page. A hostel room has no "best for" or "bookable hours" — nobody books a bed for an event, and a stay is counted in nights.</p>

        <div class="vm-row">
          <div class="vm-field">
            <label for="roomCheckIn">Check-in from</label>
            <input id="roomCheckIn" class="vm-control" type="time" value="{{ $roomFormRoom ? $roomFormRoom['checkIn'] : '14:00' }}" />
            <div class="vm-hint" style="margin:.4rem 0 0">Saved with the room. Nights are counted from the dates, not the clock.</div>
          </div>
          <div class="vm-field">
            <label for="roomCheckOut">Check-out by</label>
            <input id="roomCheckOut" class="vm-control" type="time" value="{{ $roomFormRoom ? $roomFormRoom['checkOut'] : '12:00' }}" />
          </div>
        </div>

        <!-- The pool that sat here was a vocabulary that mapped to nothing and
             showed the same selections for every room. A hostel room is
             described by its CR type, its bed count and its amenities, all of
             which are real fields on this form. -->

        <hr class="vm-divider" />

        <h2 class="vm-section-head">Amenities</h2>
        <p class="vm-hint">Tap every amenity this room offers. Each carries its own icon on the guest's page.</p>
        <div id="vmAmenityPool" class="vm-poolgrid"></div>
        </div>
        </div>
      </form>
            </div>
          </div>
        </div>
      </main>
@endsection

@push('scripts')
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
      // The rate follows the CR type: communal P350, private P400. Letting staff
      // set them independently would create two facts about one price.
      var CR_RATE = { 'Communal CR': '350.00', 'Private CR': '400.00' };
      document.addEventListener('DOMContentLoaded', function () {
        var cr = document.getElementById('roomCr');
        if (!cr) return;
        cr.querySelectorAll('.vm-dd-option').forEach(function (opt) {
          opt.addEventListener('click', function () {
            var rate = CR_RATE[opt.textContent.trim()];
            var box = document.getElementById('roomRate');
            if (rate && box) box.value = rate;
          });
        });
      });

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
    <!-- 360 panorama: upload an equirectangular image, saved to the server -->
    <!-- ============================================================
         [6b] PAGE SCRIPT: 360° panorama — loads the room's saved
         panorama (or a sample, if none yet) into a live Pannellum
         viewer; "Add / replace" and "Remove" call room-photos-api.php
         so the file is actually stored under
         assets/img/venues/rooms/<id>/pano.<ext> and survives a reload.
         ============================================================ -->
    <script>
      /* The window as admin/room-save.php reads it (2026-10-27). */
      function mtValue() {
        if (!document.getElementById('mtOn').checked) return { on: false };
        return { on: true,
          tier: document.querySelector('input[name="mtTier"]:checked').value,
          from: document.getElementById('mtFrom').value,
          until: document.getElementById('mtIndef').checked ? null : (document.getElementById('mtUntil').value || ''),
          reason: document.getElementById('mtReason').value.trim() };
      }
      /* The saved window opens as it is — this section used to open switched
         off for every room, even one that was closed. */
      (function () {
        var m = {!! json_encode($roomFormRoom ? $roomFormRoom['maintenance'] : null) !!};
        if (!m) return;
        document.getElementById('mtOn').checked = true;
        document.querySelector('input[name="mtTier"][value="' + (m.blocks ? 'hard' : 'medium') + '"]').checked = true;
        document.getElementById('mtFrom').value = m.from;
        if (m.until) { document.getElementById('mtUntil').value = m.until; } else { document.getElementById('mtIndef').checked = true; }
        document.getElementById('mtReason').value = m.reason || '';
        mtSync();
      })();

      var VM_ROOM_ID = document.getElementById('vmRoomId').value;
      var VM_CSRF = document.getElementById('vmCsrf').value;
      var VM_API = {!! json_encode(route('admin.room-photos-api')) !!};
      var VM_SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';
      var VM_ICON_28 = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';
      var VM_ICON_22 = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';

      function vmNeedsRoom() {
        if (VM_ROOM_ID) return false;
        venusepAlert('Save this room first — photos are attached to a saved room.');
        return true;
      }
      /* Parses the response as JSON, but if the server sent something else
         (an HTML error page, a stray PHP warning ahead of the JSON, a
         redirect to the login page) the failure says exactly what came
         back instead of the generic "could not reach the server" — that
         message used to fire for this case too, which hid the real cause. */
      function vmParseApiResponse(r) {
        return r.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Unexpected response from the server (HTTP ' + r.status + '): ' + text.slice(0, 300)); }
        });
      }
      function vmApiGet(action) {
        return fetch(VM_API + '?action=' + encodeURIComponent(action) + '&room_id=' + encodeURIComponent(VM_ROOM_ID), { credentials: 'same-origin' })
          .then(vmParseApiResponse);
      }
      function vmApiPost(action, extra) {
        var body = extra instanceof FormData ? extra : new FormData();
        body.append('action', action);
        body.append('room_id', VM_ROOM_ID || '');
        body.append('_token', VM_CSRF);
        return fetch(VM_API, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vmParseApiResponse);
      }

      var vmPano = null;
      function vmInitPano(src) {
        if (typeof pannellum === 'undefined') { return; }
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML = '';
        vmPano = pannellum.viewer('vmPanoView', {
          type: 'equirectangular', panorama: src, autoLoad: true, showControls: true,
          compass: true, hfov: 100, minHfov: 45, maxHfov: 120, mouseZoom: true, draggable: true,
        });
      }
      function vmPanoPlaceholder() {
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML =
          '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';
      }
      function vmLoadPano(input) {
        var f = input.files && input.files[0];
        input.value = '';
        if (!f) return;
        if (vmNeedsRoom()) return;
        vmInitPano(URL.createObjectURL(f));   // instant local preview
        var body = new FormData();
        body.append('file', f);
        vmApiPost('upload_pano', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not save the 360° photo.'); return; }
          vmInitPano(res.pano || VM_SAMPLE_PANO);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
      function vmClearPano() {
        if (vmNeedsRoom()) return;
        vmApiPost('delete_pano').then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove the 360° photo.'); return; }
          vmPanoPlaceholder();
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
    </script>
    <!-- Photo gallery manager: upload / delete / pick the cover — all saved
         to the server via room-photos-api.php -->
    <!-- ============================================================
         [6c] PAGE SCRIPT: photo gallery manager — the "Room photos"
         lightbox overlay: upload photos, click a thumbnail to choose
         the Cover image (persisted immediately as the new photo #1),
         and remove a photo. The thumbnail strip + side shots on the
         page itself mirror the same list, cover-first.
         ============================================================ -->
    <script>
      var VMPHOTOS = [];   // [{file, url}], cover-first — mirrors the server
      var VMSEL = 0;
      var VM_MAX_PHOTOS = 5;   // overwritten from the server's state on load (ROOM_PHOTO_MAX_COUNT)

      function vmSetShot(el, url, iconSvg) {
        if (!el) return;
        el.innerHTML = url ? '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover" alt="" />' : iconSvg;
      }
      function vmRefreshThumbs() {
        var urls = VMPHOTOS.map(function (p) { return p.url; });
        vmSetShot(document.getElementById('vmSideShot0'), urls[0], VM_ICON_28);
        vmSetShot(document.getElementById('vmSideShot1'), urls[1], VM_ICON_28);
        for (var i = 0; i < 3; i++) {
          var el = document.getElementById('vmThumb' + i);
          if (!el) continue;
          var url = urls[i + 2];
          if (i === 2) {
            el.innerHTML = (url ? '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0" alt="" />' : VM_ICON_22)
              + '<button type="button" class="vm-edit-photos" onclick="vmOpenGallery()">✎ Edit photos</button>';
          } else {
            vmSetShot(el, url, VM_ICON_22);
          }
        }
      }
      function vmApplyState(state) {
        VMPHOTOS = state.photos || [];
        if (state.maxPhotos) VM_MAX_PHOTOS = state.maxPhotos;
        if (VMSEL >= VMPHOTOS.length) VMSEL = 0;
        vmRefreshThumbs();
        vmGalRender();
        vmGalRenderAdd();
      }
      function vmLoadPhotos() {
        vmInitPano(VM_SAMPLE_PANO);   // shown immediately; swapped for the real one below if saved
        if (!VM_ROOM_ID) return;
        vmApiGet('list').then(function (res) {
          if (!res.ok) { console.error('vmLoadPhotos: server replied not-ok:', res); return; }
          vmApplyState(res);
          if (res.pano) vmInitPano(res.pano);
        }).catch(function (err) { console.error('vmLoadPhotos failed — photos/360 may not reflect what is actually saved:', err); });
      }

      function vmOpenGallery() {
        if (document.getElementById('vmGalOverlay')) return;
        var ov = document.createElement('div');
        ov.id = 'vmGalOverlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(20,18,15,.75);z-index:3000;display:flex;align-items:center;justify-content:center;padding:24px';
        ov.innerHTML =
          '<div style="background:#fff;border-radius:14px;max-width:960px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;font-family:var(--vm-font);color:var(--vm-text)">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--vm-border)"><div style="font-weight:700">Room photos</div><button type="button" onclick="vmCloseGallery()" style="background:none;border:none;font-size:20px;color:#606a75;cursor:pointer">&times;</button></div>' +
          '<div style="padding:16px 18px;overflow:auto">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><div style="font-weight:700;font-size:.95rem">Venue uploads <span id="vmGalCount" style="font-weight:400;color:#8a857d"></span></div><label id="vmGalAddWrap" class="vm-btn vm-btn-outline vm-pano-btn" style="min-height:2rem;padding:0 .9rem;display:inline-flex;align-items:center"><span id="vmGalAddLabel">Add photos</span><input id="vmGalAddInput" type="file" accept="image/*" multiple onchange="vmGalAdd(this)" /></label></div>' +
          '<div id="vmGalBig" class="vm-galbig"></div>' +
          '<div id="vmGalGrid" class="vm-galgrid"></div>' +
          '<p style="color:#8a857d;font-size:.78rem;margin:10px 2px 0">Click a photo to make it the cover. The &times; on a thumbnail removes it. Up to ' + VM_MAX_PHOTOS + ' photos per room.</p>' +
          '</div></div>';
        ov.addEventListener('click', function (e) { if (e.target === ov) vmCloseGallery(); });
        document.body.appendChild(ov);
        vmGalRender();
        vmGalRenderAdd();
      }
      function vmGalRenderAdd() {
        var count = document.getElementById('vmGalCount');
        var wrap = document.getElementById('vmGalAddWrap');
        var label = document.getElementById('vmGalAddLabel');
        var input = document.getElementById('vmGalAddInput');
        if (!count || !wrap || !label || !input) return;   // overlay not open
        var full = VMPHOTOS.length >= VM_MAX_PHOTOS;
        count.textContent = '(' + VMPHOTOS.length + ' of ' + VM_MAX_PHOTOS + ')';
        label.textContent = full ? 'Maximum reached' : 'Add photos';
        input.disabled = full;
        wrap.style.opacity = full ? '.5' : '1';
        wrap.style.cursor = full ? 'default' : 'pointer';
        wrap.style.pointerEvents = full ? 'none' : 'auto';
      }
      function vmGalAdd(input) {
        // input.files is a LIVE FileList, not a snapshot — clearing
        // input.value before finishing with `files` empties this same
        // reference too, silently turning the whole upload into a no-op.
        // Everything that needs to read `files` (the length checks, the
        // FormData loop) must happen before input.value is touched.
        var files = input.files;
        if (!files || !files.length) { input.value = ''; return; }
        if (vmNeedsRoom()) { input.value = ''; return; }
        var room = VMPHOTOS.length;
        if (room >= VM_MAX_PHOTOS) { venusepAlert('This room already has the maximum of ' + VM_MAX_PHOTOS + ' photos.'); input.value = ''; return; }
        if (room + files.length > VM_MAX_PHOTOS) {
          venusepAlert('Only ' + (VM_MAX_PHOTOS - room) + ' more photo(s) fit — a room can have at most ' + VM_MAX_PHOTOS + '. The rest will be skipped.');
        }
        var body = new FormData();
        for (var i = 0; i < files.length; i++) body.append('files[]', files[i]);
        input.value = '';   // safe now — the File objects are already captured in `body`
        vmApiPost('upload', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not upload those photos.'); return; }
          VMSEL = (res.photos || []).length - 1;
          vmApplyState(res);
          if (res.warning) venusepAlert(res.warning);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
      function vmGalSelect(i) {
        VMSEL = i;
        vmGalRender();
        if (i === 0 || vmNeedsRoom()) return;
        var order = VMPHOTOS.map(function (p) { return p.file; });
        var picked = order.splice(i, 1)[0];
        order.unshift(picked);
        var body = new FormData();
        order.forEach(function (f) { body.append('order[]', f); });
        vmApiPost('reorder', body).then(function (res) {
          if (!res.ok) return;
          VMSEL = 0;
          vmApplyState(res);
        }).catch(function (err) { console.error('vmGalSelect reorder failed:', err); });
      }
      async function vmGalDelete(i, e) {
        if (e) e.stopPropagation();
        if (vmNeedsRoom()) return;
        var photo = VMPHOTOS[i];
        if (!photo || !(await venusepConfirm('This cannot be undone.', { title: 'Remove this photo?', okLabel: 'Remove', danger: true }))) return;
        var body = new FormData();
        body.append('file', photo.file);
        vmApiPost('delete', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove that photo.'); return; }
          vmApplyState(res);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
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
        if (big) big.innerHTML = '<img src="' + VMPHOTOS[VMSEL].url + '" style="width:100%;height:100%;object-fit:cover" alt="" />';
        grid.innerHTML = VMPHOTOS.map(function (p, i) {
          return '<button type="button" class="vm-galthumb' + (i === VMSEL ? ' sel' : '') + '" onclick="vmGalSelect(' + i + ')">'
            + '<img src="' + p.url + '" style="width:100%;height:100%;object-fit:cover" alt="" />'
            + (i === VMSEL ? '<span class="vm-galfeat">Cover</span>' : '')
            + '<span onclick="vmGalDelete(' + i + ', event)" style="position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(20,18,15,.75);color:#fff;font-size:13px;line-height:20px;text-align:center;cursor:pointer">&times;</span>'
            + '</button>';
        }).join('');
      }
      function vmCloseGallery() { var ov = document.getElementById('vmGalOverlay'); if (ov) ov.remove(); }

      /* "Save Changes" — photos and the 360° tour already save themselves the
         moment you add/remove/reorder them (see vmApiPost above); Name and
         Location save here, to rooms.name / rooms.venue_id via
         admin/room-save.php — and so, since 2026-10-27, is everything else on
         the form, on create and on edit: beds, CR type, rate, check-in/out
         times, description, maintenance and amenities. */
      function vmSaveChanges() {
        /* No room id = CREATE. The screen always offered "Add Room"; it used
           to answer with an alert saying it could not. room-save.php generates
           the room_code (r9, h6) rather than letting anyone type one, because
           that code is also the photo folder on disk. */
        var isNew = !VM_ROOM_ID;
        var name = document.getElementById('roomName').value.trim();
        var venueTrigger = document.querySelector('#roomLoc .vm-dd-trigger');
        var venue = venueTrigger ? venueTrigger.textContent.trim() : '';
        if (!name) { venusepAlert('Enter a room name.'); return; }
        if (!venue || venue === 'Select a venue') { venusepAlert('Choose a location.'); return; }
        var mtv = mtValue();
        if (mtv.on && mtv.until === '') { venusepAlert('Set the maintenance end date, or tick "No set return date".'); return; }
        var crTrigger = document.querySelector('#roomCr .vm-dd-trigger');

        var body = new FormData();
        body.append('room_id', VM_ROOM_ID || '');
        body.append('name', name);
        body.append('venue', venue);
        body.append('room_type', 'hostel');
        /* every detail on the form, on create AND edit (2026-10-27) */
        body.append('beds', document.getElementById('roomBeds').value);
        body.append('cr_type', crTrigger && /private/i.test(crTrigger.textContent) ? 'private' : 'communal');
        body.append('rate', document.getElementById('roomRate').value);
        body.append('check_in_time', document.getElementById('roomCheckIn').value);
        body.append('check_out_time', document.getElementById('roomCheckOut').value);
        body.append('description', document.getElementById('roomDesc').value);
        body.append('maintenance', JSON.stringify(mtv));
        body.append('amenities', JSON.stringify(window.selectedAmenities()));
        body.append('_token', VM_CSRF);
        fetch({!! json_encode(route('admin.room-save')) !!}, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Could not save the room.'); return; }
            /* move on only once OK is clicked — unlike alert(), the modal does
               not block, and navigating at once wiped it off the screen */
            venusepAlert(res.message || 'Room saved.', { tone: 'ok', title: 'Saved' }).then(function () {
              location.href = {!! json_encode(route('admin.venue-management')) !!};
            });
          })
          .catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }

      vmLoadPhotos();
    </script>
    <!-- ============================================================
         [6d] PAGE SCRIPT: At-a-glance & Amenities pools — the fixed
         hard-coded {label, icon} catalogs, chip toggling (staff just
         pick what applies), and the "Other" free-text chip. Same
         pools the customer page reads from.
         Chip selections ARE saved, as amenity KEYS in rooms.amenities. The
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
        // Hostel catalog — NOT the event-room list. A guest picking a bed cares
        // about the bathroom, the aircon and whether their bag is safe.
        /* The hostel vocabulary, grouped, with this room's own selections. */
        var AMENITY_POOL = {!! json_encode($rfPool, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};

        var CHECK = '<svg class="vm-chip-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

        /* Chips carry their KEY, never their label — rewording a label must not
           orphan the rooms that have it. */
        function buildAmenities() {
          var el = document.getElementById('vmAmenityPool');
          if (!el) return;
          el.innerHTML = AMENITY_POOL.map(function (g) {
            return '<div class="vm-pool-group" style="grid-column:1/-1;margin:.5rem 0 .15rem;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a857d">' +
                   esc(g.group) + '</div>' +
                   g.items.map(function (o) {
                     return '<button type="button" class="vm-chip' + (o.on ? ' selected' : '') +
                            '" data-amenity="' + esc(o.key) + '" aria-pressed="' + (o.on ? 'true' : 'false') + '">' +
                            '<span>' + esc(o.label) + '</span>' + CHECK + '</button>';
                   }).join('');
          }).join('');
        }

        /* On window, like room-form: the Save handler lives in another script scope */
        window.selectedAmenities = function () {
          return Array.prototype.slice
            .call(document.querySelectorAll('#vmAmenityPool .vm-chip.selected'))
            .map(function (c) { return c.getAttribute('data-amenity'); })
            .filter(Boolean);
        };

        buildAmenities();

        // Toggle a chip on click (mockup: just flips the visual selected state).
        document.addEventListener('click', function (e) {
          var chip = e.target.closest ? e.target.closest('.vm-chip') : null;
          if (!chip) return;
          var on = chip.classList.toggle('selected');
          chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      })();
    </script>
  
@endpush
