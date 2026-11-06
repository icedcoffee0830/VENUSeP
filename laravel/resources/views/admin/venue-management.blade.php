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
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/venue-management.css') }}" />
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
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <script>
  /* Values from PHP for assets/js/admin/venue-management.js */
  var venueManagementPage = {
    catalogDeleteUrl: {!! json_encode(route('admin.catalog-delete')) !!},
    roomToggleActiveUrl: {!! json_encode(route('admin.room-toggle-active')) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/venue-management.js') }}"></script>

@endpush
