{{-- Admin Dashboard (was admin/Admin_Dashboard.php). Header + sidebar come from the layout. --}}
@extends('layouts.admin')
@section('title', 'Dashboard')
@section('active', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/dashboard.css') }}" />
    
@endpush

@section('content')
      <div class="app-container">
        <main class="main">
          @include('partials.no-halls-note')
            <div class="header">
                <div>
                    <h1>Admin Dashboard</h1>
                    <p>Welcome, {{ $adWho }} | Managing: {{ $adManaging }}</p>
                </div>
                <!-- Counter booking. Opens the CUSTOMER booking page in counter
                     mode (includes/auth.php) rather than a form of its own, so
                     the walk-in screen and the customer screen can never drift
                     apart. The stub modal that used to live here booked nothing
                     and has been removed. -->
                <a class="btn btn-primary" href="{{ route('customer.venues', ['counter' => 1]) }}">+ New Booking</a>
            </div>

            <!-- Overview cards: the FIRST FOUR are the exact same needs-action
                 buckets as the tabs on booking-requests.php — clicking one opens
                 the queue pre-filtered to that bucket, so dashboard numbers and
                 queue tabs can never disagree.
                 Counts come from booking_queue_rows() — the same rows the queue shows. -->
            @fragment('ad-stats')
            <div class="stats-grid" data-live="ad-stats">
                <a class="stat-card" href="{{ route('admin.booking-requests', ['tab' => 'id']) }}">
                    <span class="stat-icon"><i class="bi bi-person-vcard"></i></span>
                    <h3>Pending ID Review</h3>
                    <div class="number" id="statId">–</div>
                    <div class="info">Approve ID + reservation →</div>
                </a>
                <a class="stat-card" href="{{ route('admin.booking-requests', ['tab' => 'confirm']) }}">
                    <span class="stat-icon"><i class="bi bi-receipt"></i></span>
                    <h3>Receipts to Confirm</h3>
                    <div class="number" id="statConfirm">–</div>
                    <div class="info">Match the ref in GCash →</div>
                </a>
                <a class="stat-card" href="{{ route('admin.booking-requests', ['tab' => 'review']) }}">
                    <span class="stat-icon"><i class="bi bi-eye"></i></span>
                    <h3>Manual Review</h3>
                    <div class="number" id="statReview">–</div>
                    <div class="info">Flagged receipts →</div>
                </a>
                <a class="stat-card" href="{{ route('admin.booking-requests', ['tab' => 'overdue']) }}">
                    <span class="stat-icon"><i class="bi bi-alarm"></i></span>
                    <h3>Payment Overdue</h3>
                    <div class="number" id="statOverdue">–</div>
                    <div class="info">Needs follow-up →</div>
                </a>
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-calendar2-check"></i></span>
                    <h3>Today's Bookings</h3>
                    <div class="number">{{ (int) $adToday }}</div>
                    <div class="info">Rooms in use today</div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
                    <h3>Revenue</h3>
                    <div class="number">₱{{ number_format($adRevenue) }}</div>
                    <div class="info">{{ $adRevenueNote }}</div>
                </div>
            </div>
            @endfragment

            <div class="content-grid">
                <div class="panel">
                    <div class="panel-header">
                        <h3>Booking Requests — latest first</h3>
                        <a href="{{ route('admin.booking-requests') }}" class="btn btn-small btn-outline">View All Requests</a>
                    </div>
                    <!-- Preview only, NO action buttons on purpose: approving needs
                         the evidence (ID image, receipt, flags) and writes to the
                         timeline — that lives in booking-request.php. Each row is
                         one link there. Rows are built by the page script below. -->
                    <div id="adRows"></div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3>Venue Management</h3>
                        <a href="{{ route('admin.venue-management') }}" class="btn btn-small btn-outline">Manage Venues</a>
                    </div>
                    <!-- Venue = location container in the new model (no base rate,
                    <!-- Venue = a location that holds rooms (no base rate; a venue
                         is not itself bookable). From the database — this list was
                         hard-coded to match venue-management.php, so adding a venue
                         there left the dashboard quietly describing the old world. -->
                    <div>
@foreach ($adVenues as $adV)
                        <div class="ad-venue">
                            <div>
                                <div class="r-name" style="font-weight:600;font-size:13.5px;color:#1f1e1e">{{ $adV['name'] }}</div>
                                <div class="r-sub" style="font-size:11.5px;color:#6e6a64;margin-top:2px">{{ (string) $adV['description'] }}</div>
                            </div>
                            <span style="font-size:12px;color:#6b675f;white-space:nowrap">{{ (int) $adV['rooms'] }} room{{ (int) $adV['rooms'] === 1 ? '' : 's' }}</span>
                        </div>
@endforeach
                    </div>
                </div>
            </div>
        </main>
    </div>
@endsection

@push('scripts')
@fragment('ad-rows')<script type="application/json" data-live="ad-rows">{!! json_encode($adRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
<script>
  /* Values from PHP for assets/js/admin/dashboard.js */
  const BR_DETAIL = @json(route('admin.booking-request')); /* Laravel: the detail page's URL */
</script>
<script src="{{ asset_stamped('assets/js/admin/dashboard.js') }}"></script>
@endpush
