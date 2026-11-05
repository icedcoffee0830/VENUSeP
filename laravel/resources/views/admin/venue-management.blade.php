@extends('layouts.admin')
@section('title', 'Venue Management')
@section('active', 'Venue Management')

{{-- The page's own helpers (were functions at the top of admin/venue-management.php).
     Guarded: a test renders the page more than once per process. --}}
@php
if (!function_exists('vmMaint')) {
function vmStaffInitials($name) {
  $parts = preg_split('/\s+/', trim((string) $name));
  $initials = '';
  foreach ($parts as $part) {
    if ($part === '') continue;
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($initials) >= 2) break;
  }
  return $initials !== '' ? $initials : '?';
}


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
}
@endphp

@push('head')
{!! $pricingScript !!}
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
@endpush

@push('styles')
    <style>
      .app-content-header { padding: 26px 0 0; } /* title row starts 26px below the header, same as other pages */
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
        flex-wrap: wrap;
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

      .vm-section-title .vm-btn {
        letter-spacing: 0;
        margin-left: auto;
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
      .vm-setting-form[hidden], .vm-setting-msg[hidden] { display: none; }   /* display:flex above would beat the hidden attribute */
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
        flex-wrap: wrap;                 /* three actions + a price no longer fit one line at the narrow grid width */
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem 0.9rem;
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
        white-space: nowrap;
      }

      .vm-edit-link:hover {
        color: var(--vm-warn);
      }

      .vm-card-actions {
        align-items: center;
        display: flex;
        gap: 0.9rem;
      }

      .vm-delete-link {
        margin: -0.5rem 0;
        padding: 0.5rem 0 !important;   /* a bigger tap area, same line height */
        background: transparent;
        border: 0;
        color: #b42318;
        cursor: pointer;
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 700;
        padding: 0;
      }

      .vm-delete-link:hover {
        color: #7a1710;
        text-decoration: underline;
      }

      .vm-delete-link:disabled {
        cursor: wait;
        opacity: 0.55;
      }

      /* Disable/Enable — reversible, so it gets its own look rather than
         borrowing the delete link's red. Admin AND staff can use this one. */
      .vm-toggle-link {
        background: transparent;
        border: 0;
        color: var(--vm-warn);
        cursor: pointer;
        font-family: inherit;
        font-size: 0.85rem;
        font-weight: 700;
        margin: -0.5rem 0;
        padding: 0.5rem 0;   /* a bigger tap area, same line height */
      }

      .vm-toggle-link:hover {
        color: #cc5300;
        text-decoration: underline;
      }

      .vm-toggle-link:disabled {
        cursor: wait;
        opacity: 0.55;
      }

      .vm-toggle-link.is-off {
        color: #2f9e63;
      }

      .vm-toggle-link.is-off:hover {
        color: #1c7a4f;
      }

      /* A disabled room is still fully visible here (staff have to be able to
         find it to turn it back on) — just visibly muted, the same way the
         rest of this page never invents a status pill for the normal case. */
      .vm-card.is-inactive .vm-thumb {
        filter: grayscale(0.35);
        opacity: 0.55;
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
        flex: none;                      /* never shrink onto the action links */
        font-size: 0.8rem;
        font-weight: 700;
        margin-left: auto;               /* stays right-aligned even when it wraps to its own line */
        padding: 0.3rem 0.75rem;
        white-space: nowrap;
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
@endpush

@section('content')
      <!-- ==========================================================
           [4] PAGE CONTENT — the Venue Management page itself:
           title bar + "Add" buttons, search box, sort dropdown,
           then the VENUE cards grid and the ROOM cards grid.
           Cards link to venue-form.php / room-form.php.
           Every venue/room card below is drawn from the database, not from
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
@if ($vmAddHall)
          <a class="vm-btn vm-btn-outline" href="{{ route('admin.room-form') }}">+ Add Room</a>
@endif
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
@foreach ($venues as $v)
            <li class="vm-dd-option" data-venue="{{ $v }}">{{ $v }}</li>
@endforeach
          </ul>
        </details>
      </div>


      @fragment('vm-cards')
      <div data-live="vm-cards">
      <!-- VENUES -->
      <div class="vm-section-title">Venues <span>&mdash; locations that hold rooms</span></div>
      <div class="vm-grid">
@foreach ($vmVenueRows as $venue)
@php
        $venueId = (int) $venue['id'];
        $venueName = (string) $venue['name'];
        $venueCover = $venue['cover_photo'];
        $venueRoomCount = (int) $venue['room_count'];
        $venueBedCount = (int) $venue['bed_count'];
        $venueStaffCount = (int) $venue['staff_count'];
        $venueStaff = isset($vmStaffByVenue[$venueId]) ? $vmStaffByVenue[$venueId] : [];
        $visibleStaff = array_slice($venueStaff, 0, 3);
@endphp
        <div class="vm-card">
          <div class="vm-thumb">
@if ($venueCover)
            <img src="{{ $venueCover }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
@else
            <div class="vm-thumb-icon">
@if ($venue['venue_type'] === 'hostel')
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6M2 18h20M2 18v2M22 18v2M6 10V8a2 2 0 0 1 2-2h3v4" />
              </svg>
@else
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
              </svg>
@endif
            </div>
@endif
            <div class="vm-name-bar">{{ $venueName }}</div>
          </div>
          <div class="vm-venue-body">
            <p class="vm-venue-desc">{{ (string) $venue['description'] }}</p>
            <span class="vm-roomcount">{{ $venueRoomCount }} {{ $venueRoomCount === 1 ? 'room' : 'rooms' }}@if ($venue['venue_type'] === 'hostel') &middot; {{ $venueBedCount }} {{ $venueBedCount === 1 ? 'bed' : 'beds' }}@endif</span>
            <div class="vm-staff-row">
@if ($venueStaff)
              <div class="vm-avatars">
@foreach ($visibleStaff as $staffName)
                <div class="vm-avatar" title="{{ $staffName }}">{{ vmStaffInitials($staffName) }}</div>
@endforeach
@if ($venueStaffCount > count($visibleStaff))
                <div class="vm-avatar vm-avatar-more">+{{ $venueStaffCount - count($visibleStaff) }}</div>
@endif
              </div>
              <span class="vm-staff-label">Assigned staff</span>
@else
              <span class="vm-staff-label">No assigned staff</span>
@endif
            </div>
          </div>
          <div class="vm-card-foot">
            <div class="vm-card-actions">
@if ($vmMyVenue === null || in_array($venueName, $vmMyVenue, true)) {{-- staff: own venue only --}}
              <a class="vm-edit-link" href="{{ route('admin.venue-form', ['id' => $venueId]) }}"><span class="plus">✎</span> Edit Details</a>
@endif
            </div>
          </div>
        </div>
@endforeach
      </div>

      <!-- ROOMS -->
      <div class="vm-section-title">Rooms <span>&mdash; bookable units</span></div>
      <div class="vm-grid">
@php $rn = 0; @endphp
@foreach ($rooms as $room)
@php $rn++;
        /* the ONE place a room's maintenance line is worked out */
        $mt = vmMaint($room['maintenance'], $TODAY); @endphp
        <div class="vm-card{{ $room['active'] ? '' : ' is-inactive' }}">
          <div class="vm-thumb" data-room="{{ $rn }}">
@php /* One read of the gallery feeds BOTH the cover and the nav dots, so the
           dots can never claim more photos than the room actually has (they
           used to be drawn from a hard-coded 'photos' => N that drifted). */
      $roomPhotos = rp_gallery_urls($room['id']);
      $roomCover  = $roomPhotos ? $roomPhotos[0] : rp_cover_url($room['id']);   // fall back to the legacy single file
      $roomDots   = count($roomPhotos) ?: ($roomCover ? 1 : 0); @endphp
@if ($roomCover)
            <img src="{{ $roomCover }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
@else
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6M3 18h18M3 18v2M21 18v2M6 10V7a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v3" />
              </svg>
            </div>
@endif
            <div class="vm-dots">
@for ($p = 1; $p <= $roomDots; $p++)
              <button class="vm-dot-nav{{ $p === 1 ? ' active' : '' }}" aria-label="Photo {{ $p }}"></button>
@endfor
            </div>
            <div class="vm-name-bar">{{ $room['name'] }}</div>
          </div>
          <div class="vm-room-body">
            <span class="vm-room-loc">{{ $room['venue'] }}</span>
            <span class="vm-room-meta">Capacity: {{ (int) $room['capacity'] }} persons</span>
@if (!$room['active'])
            <span class="vm-status"><span class="vm-dot vm-dot-off"></span> Hidden from customers</span>
@endif
@if ($mt)
            <span class="vm-status" title="{{ $mt['reason'] }}"><span class="vm-dot {{ $mt['dot'] }}"></span> {{ $mt['label'] }}</span>
@if ($mt['review'] && ($vmMyVenue === null || in_array($room['venue'], $vmMyVenue, true))) {{-- staff: own venue's rooms only --}}
            <!-- The rot guard. An indefinite closure is the only window that
                 cannot end on its own, so this is the only thing that will ever
                 ask about it. It asks, and it carries the answer (Reopen) —
                 a passive count would just become furniture on the page. -->
            <span class="vm-review">Closed {{ $mt['days'] }} days &mdash; still closed?
              <a href="{{ route('admin.room-form', ['id' => $room['id']]) }}" title="[SIM] opens the room's maintenance window">Reopen</a>
            </span>
@endif
@endif
          </div>
          <div class="vm-card-foot">
            <div class="vm-card-actions">
@if ($vmMyVenue === null || in_array($room['venue'], $vmMyVenue, true)) {{-- staff: own venue's rooms only --}}
              <a class="vm-edit-link" href="{{ route('admin.room-form', ['id' => $room['id']]) }}"><span class="plus">✎</span> Edit Room</a>
              <button type="button" class="vm-toggle-link{{ $room['active'] ? '' : ' is-off' }}" data-toggle-id="{{ $room['id'] }}" data-toggle-name="{{ $room['name'] }}">{{ $room['active'] ? 'Disable' : 'Enable' }}</button>
@endif
@if (admin_is_admin())
              <button type="button" class="vm-delete-link" data-delete-entity="room" data-delete-id="{{ $room['id'] }}" data-delete-name="{{ $room['name'] }}">Delete</button>
@endif
            </div>
            <span class="vm-price">₱{{ number_format($room['fee'], 2) }}</span>
          </div>
        </div>
@endforeach

{{-- ---------------------------------------------------------------
     HOSTEL ROOMS — same grid, same card, same maintenance line and the
     same review nag. Only two things differ, and both come straight from
     "booking is per bed":
       · CAPACITY is replaced by OCCUPANCY. "Capacity: 150 persons" is a
         property of the room; "4 of 6 beds free tonight" is a room x NIGHT
         fact, so the card has to say which night it means.
       · the price is per head per night, not per day.
     They link to hostel-room-form.php because the FIELDS differ (cr_type,
     beds, rate/head) — split where the data differs, share the machinery.
     --------------------------------------------------------------- --}}
@if ($hostelRooms || $vmAddHostel)
      </div>
      <div class="vm-section-title">Hostel rooms <span>&mdash; booked per bed</span>
@if ($vmAddHostel)
        <a class="vm-btn vm-btn-outline" href="{{ route('admin.hostel-room-form') }}">+ Add Hostel Room</a>
@endif
      </div>
      <div class="vm-grid">
@endif
@php $hn = 0; @endphp
@foreach ($hostelRooms as $room)
@php $hn++;
        $mt      = vmMaint($room['maintenance'], $TODAY);       // the very same helper
        $free    = hostelBedsFree($room, $TODAY);
        $taken   = hostelBedsTaken($room, $TODAY);
        $mix     = hostelGenderMix($room, $TODAY);
        $closedNow = $room['maintenance'] && $room['maintenance']['blocks'] && hostelMaintCovers($room['maintenance'], $TODAY); @endphp
        <div class="vm-card{{ $room['active'] ? '' : ' is-inactive' }}">
          <div class="vm-thumb" data-room="{{ 100 + $hn }}">
@php /* One read of the gallery feeds BOTH the cover and the nav dots, so the
           dots can never claim more photos than the room actually has (they
           used to be drawn from a hard-coded 'photos' => N that drifted). */
      $roomPhotos = rp_gallery_urls($room['id']);
      $roomCover  = $roomPhotos ? $roomPhotos[0] : rp_cover_url($room['id']);   // fall back to the legacy single file
      $roomDots   = count($roomPhotos) ?: ($roomCover ? 1 : 0); @endphp
@if ($roomCover)
            <img src="{{ $roomCover }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
@else
            <div class="vm-thumb-icon">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M2 18v-6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v6M2 18h20M2 18v2M22 18v2M6 10V8a2 2 0 0 1 2-2h3v4" />
              </svg>
            </div>
@endif
            <div class="vm-dots">
@for ($p = 1; $p <= $roomDots; $p++)
              <button class="vm-dot-nav{{ $p === 1 ? ' active' : '' }}" aria-label="Photo {{ $p }}"></button>
@endfor
            </div>
            <div class="vm-name-bar">{{ $room['name'] }}</div>
          </div>
          <div class="vm-room-body">
            <span class="vm-room-loc">{{ $HOSTEL_VENUE }}</span>
            <span class="vm-room-meta">{{ (int) $room['beds'] }} beds · {{ $HOSTEL_CR_LABEL[$room['cr_type']] }}</span>
@if (!$room['active'])
            <span class="vm-status"><span class="vm-dot vm-dot-off"></span> Hidden from customers</span>
@endif
@if (!$closedNow)
            <span class="vm-beds" title="Counted from the guest roster — never a stored number">
              {!! vmBedStrip($taken, $room['beds']) !!}
              <span>{{ $free ? $free . ' of ' . (int) $room['beds'] . ' free tonight' : 'full tonight' }}{{ $taken ? ' · ' . vmMixLabel($mix) : '' }}</span>
            </span>
@endif
@if ($mt)
            <span class="vm-status" title="{{ $mt['reason'] }}"><span class="vm-dot {{ $mt['dot'] }}"></span> {{ $mt['label'] }}</span>
@if ($mt['review'] && ($vmMyVenue === null || in_array($room['venue'], $vmMyVenue, true))) {{-- staff: own venue's rooms only --}}
            <span class="vm-review">Closed {{ $mt['days'] }} days &mdash; still closed?
              <a href="{{ route('admin.hostel-room-form', ['id' => $room['id']]) }}" title="[SIM] opens the room's maintenance window">Reopen</a>
            </span>
@endif
@endif
          </div>
          <div class="vm-card-foot">
            <div class="vm-card-actions">
@if ($vmMyVenue === null || in_array($room['venue'], $vmMyVenue, true)) {{-- staff: own venue's rooms only --}}
              <a class="vm-edit-link" href="{{ route('admin.hostel-room-form', ['id' => $room['id']]) }}"><span class="plus">✎</span> Edit Room</a>
              <button type="button" class="vm-toggle-link{{ $room['active'] ? '' : ' is-off' }}" data-toggle-id="{{ $room['id'] }}" data-toggle-name="{{ $room['name'] }}">{{ $room['active'] ? 'Disable' : 'Enable' }}</button>
@endif
@if (admin_is_admin())
              <button type="button" class="vm-delete-link" data-delete-entity="room" data-delete-id="{{ $room['id'] }}" data-delete-name="{{ $room['name'] }}">Delete</button>
@endif
            </div>
            <span class="vm-price">₱{{ usep_peso($room['rate']) }}/head</span>
          </div>
        </div>
@endforeach
      </div>
      </div>
      @endfragment
          </div>
        </div>
      </main>
@endsection

@push('scripts')
<!-- ============================================================
         [6] PAGE SCRIPT — this page's own JS (UI-only, no backend):
         · photo carousel dots on each card
           [SIM] the dot clicks swap placeholder gradient "photos" —
           replace with real room images from the database later
         · live search filter over the cards
         · "venue" sort/filter dropdown + click-outside to close
         ============================================================ -->
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <script>
      // UI-only: carousel dots + simple search filter. No backend.
      // The cards are redrawn after a change, so their clicks are read on the document.
      const shades = [
        'linear-gradient(135deg,#eef1f5,#dfe4ea)',
        'linear-gradient(135deg,#f3eee9,#e6ddd3)',
        'linear-gradient(135deg,#e9eef3,#d5e0ea)',
        'linear-gradient(135deg,#eef3ee,#d9e6da)',
        'linear-gradient(135deg,#f3eef1,#e6d5de)',
      ];
      document.addEventListener('click', (e) => {
        const dot = e.target.closest('.vm-dot-nav');
        if (!dot) return;
        const dots = Array.from(dot.closest('.vm-dots').querySelectorAll('.vm-dot-nav'));
        dots.forEach((d) => d.classList.remove('active'));
        dot.classList.add('active');
        dot.closest('.vm-thumb').style.background = shades[dots.indexOf(dot)] || shades[0];
      });

      /* the filter last used, run again over redrawn cards */
      let vmFilter = () => {};
      const search = document.querySelector('.vm-search');
      search.addEventListener('keyup', () => {
        vmFilter = () => {
          const q = search.value.toLowerCase();
          document.querySelectorAll('.vm-card').forEach((card) => {
            card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
          });
        };
        vmFilter();
      });

      // Sort / filter by venue (UI-only, same dropdown behavior as the calendar).
      const venueSort = document.getElementById('venueSort');
      if (venueSort) {
        venueSort.querySelectorAll('.vm-dd-option').forEach((opt) => {
          opt.addEventListener('click', () => {
            const venue = opt.dataset.venue;
            venueSort.querySelector('.vm-dd-trigger').textContent = opt.textContent;
            venueSort.removeAttribute('open');
            vmFilter = () => document.querySelectorAll('.vm-grid .vm-card').forEach((card) => {
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
            vmFilter();
          });
        });
      }

      // Close any open dropdown when clicking outside of it.
      document.addEventListener('click', (e) => {
        document.querySelectorAll('.vm-dd[open]').forEach((dd) => {
          if (!dd.contains(e.target)) dd.removeAttribute('open');
        });
      });

      document.addEventListener('click', async (e) => {
        const button = e.target.closest('[data-delete-entity]');
        if (!button) return;
        const name = button.dataset.deleteName;
        const ok = await venusepConfirm(name + ' disappears from every list and can no longer be booked. Past bookings, receipts and reports keep its name. This cannot be undone.',
          { title: 'Delete ' + name + '?', okLabel: 'Delete', danger: true });
        if (!ok) return;

        button.disabled = true;
        await Live.send({!! json_encode(route('admin.catalog-delete')) !!},
          { confirm: 'delete', entity: button.dataset.deleteEntity, room_id: button.dataset.deleteId }, vmFilter);
        button.disabled = false;
      });

      // Disable hides the room from customers, so it asks first (Aron 2026-11-01);
      // Enable just flips it. Then the cards are redrawn, so the card, its "Hidden" line and every
      // count on this page come back from the database rather than being patched by hand.
      document.addEventListener('click', async (e) => {
        const button = e.target.closest('[data-toggle-id]');
        if (!button) return;
        if (!button.classList.contains('is-off') && !(await venusepConfirm(
              '"' + button.dataset.toggleName + '" will be hidden from customers until you enable it again.',
              { title: 'Disable this room?', okLabel: 'Disable' }))) return;
        button.disabled = true;
        const result = await Live.post({!! json_encode(route('admin.room-toggle-active')) !!}, { room_id: button.dataset.toggleId });
        button.disabled = false;
        if (!result.ok) { venusepAlert(result.message || 'Nothing changed.'); return; }
        // disabled with upcoming bookings: the reply names them — show it first
        if (result.notes && result.notes.length) await venusepAlert(result.message, { title: 'Room disabled' });
        else Live.say(result.message || 'Saved.');
        await Live.refresh();
        vmFilter();
      });
    </script>
  
@endpush
