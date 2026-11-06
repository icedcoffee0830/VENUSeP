@extends('layouts.admin')
@section('title', 'Customer Management')
@section('active', 'Customer Management')

@push('head')
  <meta name="theme-color" content="#7d1120" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Customer Management"/>
  <meta name="supported-color-schemes" content="light"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}"/>
@endpush

@push('styles')
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/people-shell.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/list-page.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/customer-management.css') }}" />
  <link rel="stylesheet" href="{{ asset_stamped('assets/css/tabulator-pager.css') }}" />
@endpush

@section('content')
    <main class="app-main">
      <div class="app-content staff-content">
        <div class="container-fluid">
          <header class="lp-head">
            <div>
              <h1>Customer Management</h1>
              <p>Customers who booked with your offices. Blocks apply to one office only.</p>
            </div>
          </header>

          <section class="staff-panel">
            <div class="lp-tools">
              <label class="lp-search"><i class="bi bi-search" aria-hidden="true"></i><input id="table-filter" type="search" placeholder="Name, email or phone…" aria-label="Search name, email or phone" autocomplete="off"/></label>
              <button type="button" class="lp-filters-btn" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i>Filters</button>
              <div class="lp-field"><label for="cmStatus">Status</label><select id="cmStatus"><option value="">All statuses</option><option>Active</option><option>Suspended</option></select></div>
              <div class="lp-field"><label for="cmUsep">USeP</label><select id="cmUsep"><option value="">Everyone</option><option value="yes">USeP only</option><option value="no">Outside USeP</option></select></div>
              <div class="lp-field"><label for="cmBlocks">Blocks</label><select id="cmBlocks"><option value="">Any</option><option value="yes">Blocked somewhere</option></select></div>
            </div>
            <div id="customers-table" class="lp-table"></div>
          </section>
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
  @fragment('cm-rows')<script type="application/json" data-live="cm-rows">{!! json_encode($cmRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
  <script>
  /* Values from PHP for assets/js/admin/customer-management.js */
  var customerManagementPage = {
    customerSaveUrl: @json(route('admin.customer-save')),
    bookingRequestsUrl: @json(route('admin.booking-requests')),
    suspendClass: @json($cmMaySuspend ? 'btn-cust-toggle' : null),   /* null = no Suspend button on this page */
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/customer-management.js') }}"></script>
@endpush
