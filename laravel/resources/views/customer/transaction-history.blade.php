@extends('layouts.customer')
{{-- TRANSACTION HISTORY (was customer/transaction-history.php). Rows come from transaction_rows() in legacy bookings.php, scoped to the session customer. --}}
@section('title', 'Transaction History')
@section('active', 'Transaction History')
@section('body-class', 'customer-transaction-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous" />
@endpush
@push('styles')
    <!-- [1] PAGE CSS -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/list-page.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/customer/transaction-history.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/tabulator-pager.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/print-letterhead.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/transaction-print.css') }}" />
@endpush
@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="th-page">
              <header class="lp-head">
                <div>
                  <h1>Transaction History</h1>
                  <p>What you paid, how, and the receipt for each booking.</p>
                </div>
                <div class="th-head-acts">
                  <button type="button" class="th-tool" popovertarget="thDownload"><i class="bi bi-download" aria-hidden="true"></i>Download<i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                  <div id="thDownload" class="lp-menu" popover>
                    <button id="export-csv" type="button" data-export="csv">CSV</button>
                    <button id="export-json" type="button" data-export="json">JSON</button>
                    <button id="print-table" type="button" data-export="print">Print</button>
                  </div>
                  <a class="th-tool" href="{{ route('customer.venues') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Venues</a>
                </div>
              </header>

              <div class="th-panel">
                <div class="lp-tools">
                  <label class="lp-search"><i class="bi bi-search" aria-hidden="true"></i><input id="table-filter" type="search" placeholder="Venue or booking no.…" aria-label="Search venue or booking number" autocomplete="off" /></label>
                  <button type="button" class="lp-filters-btn" id="thFilters"><i class="bi bi-sliders" aria-hidden="true"></i>Filters <span class="th-count" id="thCount" hidden></span></button>
                  <dialog class="th-sheet" id="thSheet" aria-labelledby="thSheetTitle">
                    <div class="th-sheet-head"><h2 id="thSheetTitle">Filters</h2><button type="button" data-sheet-close aria-label="Close filters"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                    <div class="lp-field">
                      <label for="payment-status-filter">Payment</label>
                      <select id="payment-status-filter">
                        <option value="">All</option>
                        @foreach ($paymentLabels as $label)
                        <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="lp-field">
                      <label for="booking-status-filter">Booking</label>
                      <select id="booking-status-filter">
                        <option value="">All</option>
                        @foreach ($bookingLabels as $label)
                        <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="lp-field">
                      <label for="payment-method-filter">Method</label>
                      <select id="payment-method-filter">
                        <option value="">All</option>
                        <option value="Cash">Cash</option>
                        <option value="GCash">GCash</option>
                      </select>
                    </div>
                    <div class="th-two">
                      <div class="lp-field"><label for="date-from-filter">Paid from</label><input id="date-from-filter" type="date" /></div>
                      <div class="lp-field"><label for="date-to-filter">to</label><input id="date-to-filter" type="date" /></div>
                    </div>
                    <div class="th-sheet-foot">
                      <button id="reset-transaction-filters" type="button" class="th-tool"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset</button>
                      <button id="thShow" type="button" class="th-tool" data-sheet-close>Show payments</button>
                    </div>
                    <div class="th-sheet-dl">Download <button type="button" class="th-link" data-export="csv">CSV</button><button type="button" class="th-link" data-export="json">JSON</button><button type="button" class="th-link" data-export="print">Print</button></div>
                  </dialog>
                </div>
                <div id="transaction-history-table" class="lp-table"></div>
              </div>
            </div>

            @include('partials.transaction-print', ['letterhead' => $thLetterhead, 'printedBy' => $thPrintedBy])
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
    <script src="{{ asset_stamped('assets/js/table-page-size.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/list-page.js') }}"></script>
    <script src="{{ asset_stamped('assets/js/transaction-print.js') }}"></script>

    <!-- [6] PAGE SCRIPT -->
    <script>
  /* Values from PHP for assets/js/customer/transaction-history.js */
  var transactionHistoryPage = {
    transactionRowsJson: {!! $transactionRowsJson !!},
    receiptUrl: @json(route('customer.receipt')),
    chipTones: @json(\App\Support\Status::CHIP_TONES),
    hostelReservationUrl: @json(route('customer.hostel-reservation')),
    roomReservationUrl: @json(route('customer.room-reservation')),
  };
</script>
<script src="{{ asset_stamped('assets/js/customer/transaction-history.js') }}"></script>
    @php include base_path('legacy/includes/booking-details-modal.php'); /* "View" */ @endphp
@endpush
