{{-- TRANSACTION HISTORY (admin view) (was admin/transaction-history.php). Rows: transaction_rows(), every customer. --}}
@extends('layouts.admin')
@section('title', 'Transaction History')
@section('active', 'Transaction History')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous" />
@endpush
@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/transaction-history-shell.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/tabulator-pager.css') }}" />
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/transaction-history.css') }}" />
@endpush
@section('content')
      <main class="app-main">
        @include('partials.no-halls-note')
        <div class="app-content">
          <div class="container-fluid">
            <div class="th-page">
              <div class="th-head">
                <div>
                  <h1>Transaction History</h1>
                  <p>Review booking and payment transaction records.</p>
                </div>
                <a class="th-back" href="{{ route('admin.dashboard') }}"><i class="bi bi-arrow-left"></i>Back to Dashboard</a>
              </div>

              <div class="th-panel">
                <div class="th-panel-head">
                  <h3>All Transactions</h3>
                  <div class="th-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="table-filter" type="search" placeholder="Search customer, venue or booking no.…" aria-label="Search customer, venue or booking number" />
                  </div>
                </div>

                <div class="th-toolbar">
                  <button id="export-csv" type="button" class="th-btn"><i class="bi bi-filetype-csv"></i>Export CSV</button>
                  <button id="export-json" type="button" class="th-btn"><i class="bi bi-filetype-json"></i>Export JSON</button>
                  <button id="print-table" type="button" class="th-btn"><i class="bi bi-printer"></i>Print</button>
                </div>

                <div class="th-filters" aria-label="Transaction filters">
                  <div class="th-field">
                    <label for="payment-status-filter">Payment Status</label>
                    <select id="payment-status-filter">
                      <option value="">All</option>
                      @foreach ($paymentLabels as $label)
                      <option value="{{ $label }}">{{ $label }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="booking-status-filter">Booking Status</label>
                    <select id="booking-status-filter">
                      <option value="">All</option>
                      @foreach ($bookingLabels as $label)
                      <option value="{{ $label }}">{{ $label }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="payment-method-filter">Payment Method</label>
                    <select id="payment-method-filter">
                      <option value="">All</option>
                      <option value="Cash">Cash</option>
                      <option value="GCash">GCash</option>
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="date-from-filter">Date From</label>
                    <input id="date-from-filter" type="date" />
                  </div>
                  <div class="th-field">
                    <label for="date-to-filter">Date To</label>
                    <input id="date-to-filter" type="date" />
                  </div>
                  <div class="th-field-reset">
                    <button id="reset-transaction-filters" type="button" class="th-btn"><i class="bi bi-arrow-counterclockwise"></i>Reset</button>
                  </div>
                </div>

                <div class="th-table-wrap">
                  <div id="transaction-history-table"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <!-- Tabulator library (the only third-party script this page needs) -->
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
    <script src="{{ asset_stamped('assets/js/table-page-size.js') }}"></script>

    <!-- ============================================================
         [6] PAGE SCRIPT — builds the table, filters, exports.
         ============================================================ -->
    <script>
  /* Values from PHP for assets/js/admin/transaction-history.js */
  var adminTransactionsPage = {
    receiptUrl: @json(route('admin.receipt')),
    transactionRowsJson: {!! $transactionRowsJson !!},
    chipTones: @json(\App\Support\Status::CHIP_TONES),
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/transaction-history.js') }}"></script>
    @php include base_path('legacy/includes/booking-details-modal.php'); /* "View" */ @endphp
@endpush
