{{-- ROOM FORM — event rooms and hostel rooms ($isHostel), one view: the two used to be
     ~1,150 identical lines. --}}
@extends('layouts.admin')
@section('title', $isHostel ? 'Hostel Room Form' : 'Room Form')
@section('active', 'Venue Management')

@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <!--begin::Pannellum 360 panorama viewer-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.css" />
    <script src="https://cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.js"></script>
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <!--end::Pannellum 360 panorama viewer-->
@endpush

@push('styles')
    <!-- ============================================================
         [1] PAGE CSS — every style this page needs, kept inline so
         the file is self-contained. Grouped: gallery / fields / chip
         pools / panorama / lightbox / dropdowns / footer (see the
         /* … */ labels below).
         ============================================================ -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/room-form.css') }}" />
@endpush

@section('content')
      <!-- ==========================================================
           [4] PAGE CONTENT — the room form itself: back link, title,
           photo gallery + 360° panorama tile, room fields (name,
           location, capacity, rate — or a hostel room's beds, CR type,
           rate per head/night), the Maintenance window, At-a-glance +
           Amenities chip pools, then the Save/Cancel footer.
           [SIM] the pre-filled values and placeholder photos are
           sample data — the real app will load the room by its ?id=
           from the database (blank form = Add, loaded form = Edit).
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="vm-page">
      <a class="vm-back" href="{{ route('admin.venue-management') }}">&larr; Back to Venue Management</a>
      @if ($isHostel)
      <h1 class="vm-title">{{ $roomId ? 'Edit Hostel Room' : 'Add Hostel Room' }}</h1>
      <p class="vm-subtitle">Hostel rooms hold beds. Guests book a bed, not the room — so there is no capacity here, only how many beds exist and what one costs per night.@if (!$roomId) Photos can be added once the room is saved and has an id.@endif</p>
      @else
      <h1 class="vm-title">{{ $roomId ? 'Edit Room Details' : 'Add Room' }}</h1>
      <p class="vm-subtitle">Rooms are the bookable units. Customers see these photos.@if (!$roomId) Photos can be added once the room is saved and has an id.@endif</p>
      @endif

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
            <label class="vm-edit-photos vm-pano-btn" title="JPG, PNG or WEBP, up to 7 MB">
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
            <input id="roomName" class="vm-control" type="text" value="{{ $roomFormRoom ? $roomFormRoom['name'] : '' }}" placeholder="{{ $isHostel ? 'e.g. Hostel Room 1' : 'e.g. Function Hall' }}" />
          </div>
          <div class="vm-field">
@if ($rfFixedVenue)
            <label for="roomLoc">Location (Venue)</label>
            <input id="roomLoc" class="vm-control" type="text" value="{{ $roomFormRoom['venue'] ?? head($rfVenues) }}" readonly title="{{ $isHostel ? 'A hostel room is always at the hostel' : 'Only an administrator can move a room' }}" />
@else
            <label>Location (Venue)</label>
            <!-- Options come from includes/venues.php — the event venues, data-driven.
                 The summary + saved value come from rooms.venue_id via the DB
                 overlay in includes/venue-rooms.php. -->
            <details class="vm-dd" id="roomLoc">
              <summary class="vm-dd-trigger">{{ $roomFormRoom && isset($roomFormRoom['venue']) ? $roomFormRoom['venue'] : 'Select a venue' }}</summary>
              <ul class="vm-dd-menu" aria-label="Location options">
                {!! venueOptions($rfVenues) !!}
              </ul>
            </details>
@endif
          </div>
        </div>
@if ($rfStaff)
@include('partials.room-form-staff')
@endif

        <hr class="vm-divider" />

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="{{ route('admin.venue-management') }}">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary" onclick="vmSaveChanges()">Save Changes</button>
        </div>
        </div>

        <div class="vm-top-fields">
        @if ($isHostel)
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
            <input id="roomRate" class="vm-control" type="number" @if ($roomId && session('account_type') !== 'admin') readonly title="Only an administrator can change a price." @endif min="0" step="0.01" value="{{ $roomFormRoom ? number_format((float) $roomFormRoom['rate'], 2, '.', '') : '350.00' }}" placeholder="0.00" />
            <div class="vm-hint" style="margin:.4rem 0 0">Per bed, per night. A new rate applies to new bookings; existing stays keep their price.</div>
          </div>
        </div>
        @else
        <div class="vm-row">
          <!-- the room's REAL capacity and rate (2026-10-27) — these used to open
               at 150 / ₱4,000 for every room, whatever it actually held -->
          <div class="vm-field">
            <label for="roomCap">Capacity (persons)</label>
            <input id="roomCap" class="vm-control" type="number" min="1" value="{{ $roomFormRoom ? (int) $roomFormRoom['capacity'] : '' }}" placeholder="e.g. 150" />
          </div>
          <div class="vm-field">
            <label for="roomRate">Rate per day (₱)</label>
            <input id="roomRate" class="vm-control" type="number" @if ($roomId && session('account_type') !== 'admin') readonly title="Only an administrator can change a price." @endif min="0" step="0.01" value="{{ $roomFormRoom ? number_format((float) $roomFormRoom['fee'], 2, '.', '') : '' }}" placeholder="0.00" />
            <div class="vm-hint" style="margin:.4rem 0 0">A new rate applies to new bookings; existing ones keep their price.</div>
          </div>
        </div>
        @endif
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
        @if ($isHostel)
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
        @else
        <p class="vm-hint">Shown on the customer's room page. Fill the free-text fields, then tap the attributes that apply. (Reservation fee uses the Rate above.)</p>

        <!-- "At a glance" — one line each of prose about the room (2026-10-27:
             editable, stored in rooms.best_for / catering / accessibility; empty
             falls back to the old text in includes/amenities.php). The hours are
             the booking page's own (7 AM – 10 PM), shown here, not set here. -->
        <div class="vm-row">
          <div class="vm-field">
            <label for="roomBestFor">Best for</label>
            <input id="roomBestFor" class="vm-control" type="text" maxlength="255" value="{{ $roomFormRoom ? $roomFormRoom['bestFor'] : '' }}" placeholder="e.g. Seminars · Trainings · Ceremonies" />
          </div>
          <div class="vm-field">
            <label for="roomHours">Bookable hours</label>
            <input id="roomHours" class="vm-control" type="text" value="7 AM – 10 PM daily" readonly title="Set by the booking page for every room" />
          </div>
        </div>
        <div class="vm-row">
          <div class="vm-field">
            <label for="roomCatering">Catering</label>
            <input id="roomCatering" class="vm-control" type="text" maxlength="255" value="{{ $roomFormRoom ? $roomFormRoom['catering'] : '' }}" placeholder="e.g. Outside catering allowed" />
          </div>
          <div class="vm-field">
            <label for="roomAccess">Accessibility</label>
            <input id="roomAccess" class="vm-control" type="text" maxlength="255" value="{{ $roomFormRoom ? $roomFormRoom['accessible'] : '' }}" placeholder="e.g. Wheelchair accessible" />
          </div>
        </div>
        @endif

        <hr class="vm-divider" />

        <h2 class="vm-section-head">Amenities</h2>
        <p class="vm-hint">Tap every amenity this room offers. Each carries its own icon on the {{ $isHostel ? "guest's" : 'customer' }} page.</p>
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
  /* Values from PHP for assets/js/admin/room-form.js */
  var VM_API = {!! json_encode(route('admin.room-photos-api')) !!};
  var roomFormPage = {
    roomMaintenance: {!! json_encode($roomFormRoom ? $roomFormRoom['maintenance'] : null) !!},
    rfFixedVenue: @json($rfFixedVenue),
    isHostel: @json($isHostel),
    roomSaveUrl: {!! json_encode(route('admin.room-save')) !!},
    roomFormUrl: {!! json_encode(route('admin.room-form')) !!},
    venueManagementUrl: {!! json_encode(route('admin.venue-management')) !!},
    rfPool: {!! json_encode($rfPool, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/room-form.js') }}"></script>
  
@endpush
