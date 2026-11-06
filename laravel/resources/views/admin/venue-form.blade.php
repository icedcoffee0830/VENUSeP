@extends('layouts.admin')
@section('title', 'Venue Form')
@section('active', 'Venue Management')

{{-- The page's own helper (was a function at the top of admin/venue-form.php). --}}
@php
if (!function_exists('vf_initials')) {
    function vf_initials($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    }
}
@endphp

@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
@endpush

@push('styles')
    <!-- ============================================================
         [1] PAGE CSS — every style this page needs, kept inline so
         the file is self-contained. Grouped: image / fields / staff /
         staff picker / footer (see the /* … */ labels below).
         ============================================================ -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/venue-form.css') }}" />
@endpush

@section('content')
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
      <a class="vm-back" href="{{ route('admin.venue-management') }}">&larr; Back to Venue Management</a>
      <h1 class="vm-title">Edit Venue (Location)</h1>
      <p class="vm-subtitle">A venue is a location that holds rooms. It is not bookable.</p>

      <form class="vm-panel" onsubmit="return false">
        <input type="hidden" id="vpVenueId" value="{{ (int) $venueId }}" />
        <input type="hidden" id="vpCsrf" value="{{ $venueFormCsrf }}" />
        <!-- Single image -->
        <div class="vm-image" id="vpImageArea">
@php $venueCover = $venueFormRow ? $venueFormRow['cover_photo'] : null; @endphp
          <div id="vpImageSlot" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center">
@if ($venueCover)
            <img id="vpCoverImg" src="{{ $venueCover }}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px" />
@else
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6" />
            </svg>
@endif
          </div>
          <label class="vm-photo-btn" style="cursor:pointer">✎ Change photo<input id="vpPhotoInput" type="file" accept="image/*" onchange="vpChangePhoto(this)" style="display:none" /></label>
          <button type="button" class="vm-photo-btn" id="vpRemoveBtn" style="right:auto;left:12px" onclick="vpRemovePhoto()"{{ $venueCover ? '' : ' hidden' }}>Remove photo</button>
        </div>
        <p class="vm-staff-hint" style="margin:.5rem 0 0">Photo: JPG, PNG or WEBP, up to 7 MB.</p>

        <hr class="vm-divider" />

        <div class="vm-field">
          <label for="venueName">Venue Name</label>
          <input id="venueName" class="vm-control" type="text" value="{{ $venueFormRow ? $venueFormRow['name'] : '' }}" placeholder="e.g. Bahay Alumni" />
        </div>

        <div class="vm-field">
          <label for="venueDesc">Description</label>
          <textarea id="venueDesc" class="vm-control" rows="3" placeholder="Short description of the location">{{ $venueFormRow ? $venueFormRow['description'] : '' }}</textarea>
        </div>

        <hr class="vm-divider" />

        <!-- Assigned staff -->
        <p class="vm-staff-title">Assigned Staff</p>
        <p class="vm-staff-hint">Staff handle only their own venue's bookings, and each staff member belongs to exactly one venue. Adding someone here moves them from the venue they handle now.</p>
        @fragment('vf-staff')
        <div data-live="vf-staff">
        <div class="vm-staff-list" id="staffList">
          @if (!$vfHere)
            <div class="vm-staff-empty" id="staffEmpty">No staff handle this venue yet — add someone below.</div>
          @endif
          @foreach ($vfHere as $s)
            <div class="vm-staff-item">
              <span class="vm-avatar">{{ vf_initials($s['full_name']) }}</span>
              <span class="vm-staff-name">{{ $s['full_name'] }}
                <span class="vm-staff-sub">{{ $s['position_role'] ?: 'Staff' }}{{ $s['is_active'] ? '' : ' · Suspended' }}</span></span>
            </div>
          @endforeach
        </div>

        <button type="button" class="vm-add-staff" onclick="togglePicker()">➕ Add Staff</button>

        <div class="vm-picker" id="staffPicker" hidden>
          <input class="vm-picker-search" type="search" placeholder="Search staff..." oninput="filterPicker(this.value)" />
          @foreach ($vfOthers as $s)
            <div class="vm-picker-item" data-search="{{ mb_strtolower($s['full_name'] . ' ' . $s['venue_name']) }}">
              <span class="vm-avatar">{{ vf_initials($s['full_name']) }}</span>
              <span class="vm-staff-name">{{ $s['full_name'] }}
                <span class="vm-staff-sub">handles {{ $s['venue_name'] ?: 'no venue' }}{{ $s['is_active'] ? '' : ' · Suspended' }}</span></span>
              <button type="button" class="vm-picker-add" onclick="addStaff(this)"
                      data-id="{{ (int) $s['id'] }}"
                      data-name="{{ $s['full_name'] }}"
                      data-initials="{{ vf_initials($s['full_name']) }}"
                      data-from="{{ $s['venue_name'] ?: 'no venue' }}">+ Add</button>
            </div>
          @endforeach
          <div class="vm-staff-empty" id="pickerEmpty"{{ $vfOthers ? ' hidden' : '' }}>No other staff to add. Create a staff account in Staff Management first.</div>
          <button type="button" class="vm-picker-done" onclick="togglePicker()">Done</button>
        </div>
        </div>
        @endfragment

        <div class="vm-form-foot">
          <a class="vm-btn vm-btn-outline" href="{{ route('admin.venue-management') }}">Cancel</a>
          <button type="button" class="vm-btn vm-btn-primary" onclick="vpSaveChanges()">Save Changes</button>
        </div>
      </form>
            </div>
          </div>
        </div>
      </main>
@endsection

@push('scripts')
<!-- ============================================================
         [6a] PAGE SCRIPT: venue photo — "Change photo" / "Remove
         photo" call admin/venue-photo-api.php so the file is actually
         stored under assets/img/venues/covers/<venue id>.<ext> and the
         venue's cover_photo column, surviving a reload.
         ============================================================ -->
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
    <script>
  /* Values from PHP for assets/js/admin/venue-form.js */
  var venueFormPage = {
    venuePhotoApiUrl: {!! json_encode(route('admin.venue-photo-api')) !!},
    venueSaveUrl: {!! json_encode(route('admin.venue-save')) !!},
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/venue-form.js') }}"></script>
  
@endpush
