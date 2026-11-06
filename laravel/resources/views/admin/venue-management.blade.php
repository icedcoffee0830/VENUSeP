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
      /* Venue Management: one section per venue, its rooms under it. */
      .app-content-header { padding: 26px 0 0; } /* title row starts 26px below the header, same as other pages */
      .vm-head { margin-bottom: 14px; }
      .vm-head h1 { margin: 0; color: #1f1e1e; font-size: 22px; font-weight: 700; letter-spacing: -.01em; text-wrap: balance; }
      .vm-head p { margin: 4px 0 0; color: #6e6a64; font-size: 13.5px; }
      .vm-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

      /* the jump bar stays under the header while the rooms scroll */
      .vm-jump { position: sticky; top: var(--venusep-header-height); z-index: 20; display: flex; align-items: center; gap: 10px; padding: 10px 0; background: #fff; border-bottom: 1px solid #e5e5e5; }
      .vm-jump nav { display: flex; flex: 1; gap: 6px; min-width: 0; overflow-x: auto; scrollbar-width: none; }
      .vm-jump nav a { display: inline-flex; flex: none; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: 1px solid #d7d7d7; border-radius: 999px; color: #1f1e1e; font-size: 13.5px; font-weight: 600; text-decoration: none; }
      .vm-jump nav a:hover { border-color: #a11626; color: #a11626; }
      .vm-jump nav a[aria-current] { border-color: #a11626; background: #a11626; color: #fff; }
      .vm-jump nav span { font-variant-numeric: tabular-nums; font-weight: 500; opacity: .75; }
      .vm-search-wrap { position: relative; flex: none; width: 240px; }
      .vm-search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6e6a64; font-size: 14px; }
      .vm-search { width: 100%; height: 38px; padding: 0 10px 0 34px; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; color: #1f1e1e; font: inherit; font-size: 13.5px; }

      .vm-venue { margin-top: 26px; scroll-margin-top: 130px; }
      .vm-venue-head { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; }
      .vm-venue-cover { flex: none; width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: #f1eee9; }
      div.vm-venue-cover { display: grid; place-items: center; color: #8a7f72; font-size: 24px; }
      .vm-venue-text { flex: 1; min-width: 0; }
      .vm-venue-head h2 { margin: 0; color: #1f1e1e; font-size: 18px; font-weight: 700; }
      .vm-venue-head p { margin: 2px 0 0; color: #6e6a64; font-size: 13px; overflow-wrap: anywhere; }
      .vm-staff-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 6px; color: #1f1e1e; font-size: 13px; }
      .vm-avatars { display: inline-flex; }
      .vm-avatar { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; margin-left: -3px; border: 2px solid #fff; border-radius: 50%; background: #3c0c14; color: #fff; font-size: 12px; font-weight: 600; }
      .vm-avatar:first-child { margin-left: 0; }
      .vm-venue-acts { display: flex; flex: none; gap: 8px; }
      .vm-empty { margin: 0; padding: 14px; border: 1px dashed #d7d7d7; border-radius: 12px; color: #6e6a64; font-size: 13.5px; }

      .vm-btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; height: 32px; padding: 0 .75rem; border: 1px solid #d7d7d7; border-radius: 8px; background: #fff; color: #1f1e1e; font: inherit; font-size: 12.5px; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
      .vm-btn:hover { background: #f4f2ee; }
      .vm-btn:focus-visible, .vm-delete-link:focus-visible, .vm-jump nav a:focus-visible { outline: 2px solid #a11626; outline-offset: 2px; }

      .vm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
      .vm-card[hidden] { display: none; }
      .vm-card { display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e5e5e5; border-radius: 14px; background: #fff; }
      .vm-thumb { position: relative; display: grid; place-items: center; aspect-ratio: 16 / 10; background: linear-gradient(135deg, #f6f3ef, #ebe5de); color: #8a7f72; font-size: 30px; }
      .vm-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
      .vm-card.is-inactive .vm-thumb img { filter: grayscale(.6); opacity: .6; }
      .vm-thumb .vz-chip { position: absolute; left: 10px; top: 10px; background: rgba(255, 255, 255, .95); }
      .vm-room-body { display: flex; flex: 1; flex-direction: column; gap: 6px; padding: 12px 14px 10px; }
      .vm-room-body h3 { margin: 0; color: #1f1e1e; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
      .vm-facts { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0 8px; color: #6e6a64; font-size: 13px; }
      .vm-facts b { color: #1f1e1e; font-weight: 600; font-variant-numeric: tabular-nums; }
      .vm-note { display: flex; align-items: flex-start; gap: 6px; color: #8a5a12; font-size: 13px; }
      .vm-note i { margin-top: 2px; }
      .vm-note-grey { color: #55606b; }
      .vm-review { padding: 8px 10px; border-radius: 10px; background: #fdf3e6; color: #8a5a12; font-size: 13px; }
      .vm-review a { color: #8a5a12; font-weight: 700; }
      .vm-beds { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; color: #6e6a64; font-size: 13px; }
      .vm-bedstrip { display: inline-flex; gap: 3px; }
      .vm-bedpip { width: 9px; height: 9px; border-radius: 2px; background: #dcd9d3; }
      .vm-bedpip.is-taken { background: #3c0c14; }
      .vm-card-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 10px 12px; border-top: 1px solid #f0efec; }
      .vm-card-foot:empty { display: none; }
      .vm-card-foot .vm-btn { padding: 0 .6rem; }
      .vm-delete-link { margin-left: auto; padding: 8px 2px; border: 0; background: none; color: #b23a3a; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }

      @media (max-width: 767.98px) {
        .container-fluid { padding-inline: 16px; }
        .app-content-header { padding-top: 16px; }
        .vm-jump { position: static; flex-direction: column; align-items: stretch; }
        .vm-jump nav { flex-wrap: wrap; overflow: visible; }
        .vm-jump nav a { height: 44px; }
        .vm-search-wrap { width: auto; }
        .vm-search { height: 44px; }
        .vm-venue-head { flex-wrap: wrap; }
        .vm-venue-cover { width: 48px; height: 48px; }
        .vm-venue-text { flex: 1 1 0; }
        .vm-venue-acts { width: 100%; }
        .vm-venue-acts .vm-btn { flex: 1; height: 44px; }

        /* a room is a row: the photo at the left, like the landing page on a phone */
        .vm-grid { grid-template-columns: minmax(0, 1fr); gap: 10px; }
        .vm-card { display: grid; grid-template-columns: 112px minmax(0, 1fr); }
        .vm-thumb { aspect-ratio: auto; height: 100%; min-height: 104px; }
        .vm-thumb .vz-chip { left: 6px; top: 6px; padding: 2px 8px; }
        .vm-room-body { padding: 10px 12px; }
        .vm-card-foot { grid-column: 1 / -1; padding: 8px 12px; }
        .vm-card-foot .vm-btn { flex: 1 1 auto; height: 44px; font-size: 13.5px; }
        .vm-delete-link { min-height: 44px; }
      }
    </style>
@endpush

@section('content')
      <main class="app-main">
        @include('partials.no-halls-note')
        <div class="app-content-header">
          <div class="container-fluid">
            <header class="vm-head">
              <h1>Venue Management</h1>
              <p>Your venues and every room customers can book in them.</p>
            </header>
          </div>
        </div>
        <div class="app-content">
          <div class="container-fluid">
      <div class="vm-jump">
@fragment('vm-jump')
@php
        /* Rooms group under their own venue. $hostel tells the card which kind it draws. */
        $vmGroups = [];
        foreach ([[$rooms, false], [$hostelRooms, true]] as [$vmList, $vmIsHostel]) {
          foreach ($vmList as $vmRoom) {
            $vmGroups[$vmRoom['venue']][] = ['room' => $vmRoom, 'hostel' => $vmIsHostel, 'mt' => vmMaint($vmRoom['maintenance'], $TODAY)];
          }
        }
        $vmAttention = 0;
        foreach ($vmGroups as $vmGroup) {
          $vmAttention += count(array_filter($vmGroup, fn ($g) => !$g['room']['active'] || $g['mt']));
        }
@endphp
        <nav aria-label="Jump to a venue" data-live="vm-jump">
          <a href="#" data-jump="all">All <span>{{ count($rooms) + count($hostelRooms) }}</span></a>
@foreach ($vmVenueRows as $venue)
          <a href="#venue-{{ $venue['id'] }}" data-jump="venue-{{ $venue['id'] }}">{{ $venue['name'] }} <span>{{ count($vmGroups[$venue['name']] ?? []) }}</span></a>
@endforeach
          <a href="#" data-jump="attention">Needs attention <span>{{ $vmAttention }}</span></a>
        </nav>
@endfragment
        <label class="vm-search-wrap"><i class="bi bi-search" aria-hidden="true"></i><input class="vm-search" type="search" placeholder="Search a room…" aria-label="Search a room" autocomplete="off" /></label>
      </div>

      @fragment('vm-cards')
      <div data-live="vm-cards">
@foreach ($vmVenueRows as $venue)
@php
        $venueStaff = $vmStaffByVenue[(int) $venue['id']] ?? [];
        $isHostel = $venue['venue_type'] === 'hostel';
@endphp
      <section class="vm-venue" id="venue-{{ $venue['id'] }}" aria-labelledby="venue-{{ $venue['id'] }}-name">
        <div class="vm-venue-head">
@if ($venue['cover_photo'])
          <img class="vm-venue-cover" src="{{ $venue['cover_photo'] }}" alt="" width="64" height="64" />
@else
          <div class="vm-venue-cover"><i class="bi {{ $isHostel ? 'bi-house' : 'bi-building' }}" aria-hidden="true"></i></div>
@endif
          <div class="vm-venue-text">
            <h2 id="venue-{{ $venue['id'] }}-name">{{ $venue['name'] }}</h2>
            <p>{{ (string) $venue['description'] }}</p>
            <div class="vm-staff-row">
@if ($venueStaff)
              <span class="vm-avatars" aria-hidden="true">
@foreach (array_slice($venueStaff, 0, 3) as $staffName)
                <span class="vm-avatar">{{ vmStaffInitials($staffName) }}</span>
@endforeach
              </span>
              <span>{{ implode(', ', $venueStaff) }}</span>
@else
              <span>No assigned staff</span>
@endif
            </div>
          </div>
          <div class="vm-venue-acts">
@if ($venue['may_edit']) {{-- admins only --}}
            <a class="vm-btn" href="{{ route('admin.venue-form', ['id' => $venue['id']]) }}"><i class="bi bi-pencil" aria-hidden="true"></i>Edit venue</a>
@endif
@if ($isHostel ? $vmAddHostel : $vmAddHall)
            <a class="vm-btn" href="{{ route($isHostel ? 'admin.hostel-room-form' : 'admin.room-form') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ $isHostel ? 'Add hostel room' : 'Add room' }}</a>
@endif
          </div>
        </div>
@if (empty($vmGroups[$venue['name']]))
        <p class="vm-empty">No rooms here yet.</p>
@else
        <div class="vm-grid">
@foreach ($vmGroups[$venue['name']] as $vmItem)
          @include('partials.venue-room-card', $vmItem)
@endforeach
        </div>
@endif
      </section>
@endforeach
      </div>
      @endfragment
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <script>
      /* The jump bar and the search work together, and run again over redrawn cards. */
      let vmJump = 'all';
      const search = document.querySelector('.vm-search');
      const vmFilter = () => {
        const q = search.value.toLowerCase();
        document.querySelectorAll('[data-jump]').forEach((a) => {
          if (a.dataset.jump === vmJump) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
        document.querySelectorAll('.vm-venue').forEach((section) => {
          let shown = 0;
          section.querySelectorAll('.vm-card').forEach((card) => {
            const show = card.textContent.toLowerCase().includes(q) && (vmJump !== 'attention' || card.dataset.attention === '1');
            card.hidden = !show;
            if (show) shown++;
          });
          /* a venue with nothing to show goes too, unless nothing is narrowing the list */
          const narrowed = q !== '' || vmJump === 'attention';
          section.hidden = (vmJump.startsWith('venue-') && section.id !== vmJump) || (narrowed && !shown);
        });
      };
      search.addEventListener('input', vmFilter);
      document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-jump]');
        if (!link) return;
        e.preventDefault();
        vmJump = link.dataset.jump;
        vmFilter();
      });
      vmFilter();

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

      // Hiding a room asks first (Aron 2026-11-01); showing it just flips it. Then the cards are
      // redrawn, so the card, its chip and every count on this page come back from the database.
      document.addEventListener('click', async (e) => {
        const button = e.target.closest('[data-toggle-id]');
        if (!button) return;
        if (!button.classList.contains('is-off') && !(await venusepConfirm(
              '"' + button.dataset.toggleName + '" will be hidden from customers until you show it again.',
              { title: 'Hide this room?', okLabel: 'Hide' }))) return;
        button.disabled = true;
        const result = await Live.post({!! json_encode(route('admin.room-toggle-active')) !!}, { room_id: button.dataset.toggleId });
        button.disabled = false;
        if (!result.ok) { venusepAlert(result.message || 'Nothing changed.'); return; }
        // hidden with upcoming bookings: the reply names them — show it first
        if (result.notes && result.notes.length) await venusepAlert(result.message, { title: 'Room hidden' });
        else Live.say(result.message || 'Saved.');
        await Live.refresh();
        vmFilter();
      });
    </script>

@endpush
