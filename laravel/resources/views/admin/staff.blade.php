@extends('layouts.admin')
@section('title', 'Staff Management')
@section('active', 'Staff Management')

@push('head')
  <meta name="theme-color" content="#7d1120" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Staff Management"/>
  <meta name="supported-color-schemes" content="light"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}"/>
@endpush

@push('styles')
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/people-shell.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/list-page.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/staff.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/tabulator-pager.css') }}" />
@endpush

@section('content')
    <main class="app-main">
      <div class="app-content staff-content">
        <div class="container-fluid">
          <header class="lp-head">
            <div>
              <h1>Staff Management</h1>
              <p>Accounts, halls and covers for the offices you manage.</p>
            </div>
            <!-- the way to the account form (2026-10-28): it is admin-only, and so is this page -->
            <a class="btn-staff-add" href="{{ route('admin.admin-register') }}"><i class="bi bi-person-plus" aria-hidden="true"></i>Add account</a>
          </header>

@php $smTab = in_array(request('tab'), ['covers', 'moves'], true) ? request('tab') : 'people'; @endphp
          @fragment('sm-tabs')
          @php
            $smWaiting = fn ($list) => count(array_filter($list, fn ($x) => $x['canAnswer']));
            $smTabs = [['people', 'People', count($smRows), 0], ['covers', 'Covers', count($smCover['covers']), $smWaiting($smCover['covers'])],
                       ['moves', 'Moves between groups', count($smCover['moves']), $smWaiting($smCover['moves'])]];
          @endphp
          <nav class="lp-seg" aria-label="Staff Management sections" data-live="sm-tabs">
@foreach ($smTabs as [$key, $label, $count, $waiting])
            <a href="{{ route('admin.staff', ['tab' => $key]) }}" data-tab="{{ $key }}"@if ($smTab === $key) aria-current="page"@endif>{{ $label }}
@if ($waiting)
              <span class="vz-chip vz-chip-amber">{{ $waiting }} waiting for you</span>
@else
              <span class="vz-chip vz-chip-grey">{{ $count }}</span>
@endif
            </a>
@endforeach
          </nav>
          @endfragment

          <section class="staff-panel" data-tab-panel="people"@if ($smTab !== 'people') hidden @endif>
            <div class="lp-tools">
              <label class="lp-search"><i class="bi bi-search" aria-hidden="true"></i><input id="table-filter" type="search" placeholder="Search name or email…" aria-label="Search name or email" autocomplete="off"/></label>
              <button type="button" class="lp-filters-btn" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i>Filters</button>
              <div class="lp-field"><label for="smRole">Role</label><select id="smRole" data-filter="role"><option value="">All roles</option><option>Staff</option><option>Admin</option><option>Super admin</option></select></div>
              <div class="lp-field"><label for="smOffice">Office</label><select id="smOffice" data-filter="venueId"><option value="">All offices</option>@foreach ($smVenues as $venue)<option value="{{ $venue['id'] }}">{{ $venue['name'] }}</option>@endforeach</select></div>
              <div class="lp-field"><label for="smStatus">Status</label><select id="smStatus" data-filter="status"><option value="">All statuses</option><option>Active</option><option>Suspended</option></select></div>
            </div>
            <div id="users-table" class="lp-table"></div>
          </section>
@include('partials.staff-covers')
        </div>
      </div>
    </main>
@endsection

@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
  <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
  <script src="{{ asset_stamped('assets/js/list-page.js') }}"></script>
  @fragment('sm-rows')<script type="application/json" data-live="sm-rows">{!! json_encode($smRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
  <script>
  /* Values from PHP for assets/js/admin/staff.js */
  var staffPage = {
    smVenues: {!! json_encode($smVenues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!},
    staffSaveUrl: @json(route('admin.staff-save')),
    adminLoginUrl: @json(route('admin.admin-login')),
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/staff.js') }}"></script>
@endpush
