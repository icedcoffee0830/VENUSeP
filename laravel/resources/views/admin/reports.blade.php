{{-- Quarterly Reports (was admin/Quarterly_Reports.php). Header + sidebar come from the layout. --}}
@extends('layouts.admin')
@section('title', 'Quarterly Reports')
@section('active', 'Reports')

@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css">
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/print-letterhead.css') }}" />
    <style>
        :root {
            --primary: #1f1e1e;
            --primary-light: #4b4742;
            --primary-dark: #111010;
            --accent: #ffffff;
            --bg-page: #ffffff;
            --bg-white: #ffffff;
            --text-primary: #1f1e1e;
            --text-secondary: #6f675d;
            --text-muted: #9a9083;
            --text-white: #ffffff;
            --border: #e5e5e5; /* neutral gray hairline — same as venue-management / booking-requests */
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg-page);
            color: var(--text-primary);
            font-family: "Inter", "Segoe UI", Arial, sans-serif;
            line-height: 1.5;
        }

        .app-sidebar {
            background-color: #1f1e1e !important;
            color: #fff;
        }

        .app-sidebar .nav-link {
            color: #fff;
        }

        .app-sidebar .nav-link.active,
        .app-sidebar .nav-link:hover {
            background: #333 !important;
            color: #fff !important;
        }

        .sidebar-brand {
            background: #1f1e1e;
        }

        .brand-link {
            color: #fff;
            text-decoration: none;
        }

        .brand-mark {
            align-items: center;
            background: #ffffff;
            border-radius: 8px;
            color: #1f1e1e;
            display: inline-flex;
            height: 34px;
            justify-content: center;
            margin-right: 10px;
            width: 34px;
        }

        .brand-text {
            color: #ffffff;
            font-weight: 700;
        }

        .app-header,
        .navbar {
            background: #1f1e1e !important;
            border-bottom: none !important;
        }

        .app-header .nav-link,
        .app-header .user-name,
        .app-header i {
            color: white !important;
        }

        .user-avatar {
            align-items: center;
            background: #fff;
            border-radius: 50%;
            color: #1f1e1e;
            display: inline-flex;
            font-size: 10px;
            font-weight: 700;
            height: 34px;
            justify-content: center;
            margin-right: 10px;
            width: 34px;
        }

        .app-container {
            background: transparent;
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .main {
            flex: 1;
            min-width: 0;   /* a wide table scrolls inside its box instead of widening the page */
            padding: 24px 28px;
        }

        .header {
            align-items: center;
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 10px 12px;
        }

        .header h1 {
            color: #1f1e1e;
            font-size: 24px;
            font-weight: 700;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 5px;
        }

        .btn {
            align-items: center;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            font-size: 14px;
            font-weight: 700;
            gap: 8px;
            padding: 10px 20px;
            transition: all 0.2s;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #ffffff;
            border: 1px solid #1f1e1e;
            color: #1f1e1e;
        }

        .btn-primary:hover {
            background: #353230;
            border: 1px solid #1f1e1e;
            color: #ffffff;
        }

        .report-controls {
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            border-radius: 8px;
            color: #1f1e1e;
            display: flex;
            gap: 15px;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px 18px;
        }

        .report-period-control {
            align-items: center;
            display: flex;
            gap: 12px;
        }

        .filter-select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.3);
            border-radius: 6px;
            color: #1f1e1e;
            font-size: 14px;
            padding: 8px 12px;
        }

        .filter-select option {
            background: #ffffff;
            color: #1f1e1e;
        }

        .tag {
            border-radius: 999px;
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 5px 10px;
        }

        /* the selected quarter's status (2026-10-28): still running, or over */
        .tag-progress {
            background: #fdf3e6;
            border: 1px solid #f1d9b5;
            color: #8a5a12;
        }

        .tag-closed {
            background: #e8f2ec;
            border: 1px solid #c9e2d3;
            color: #1c7a4f;
        }

        .stats-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(4, 1fr);
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--bg-white);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            min-height: 126px;
            overflow: hidden;
            padding: 20px;
            position: relative;
        }

        .stat-card::before {
            background: #1f1e1e;
            content: "";
            inset: 0 auto 0 0;
            position: absolute;
            width: 4px;
        }

        .stat-card .stat-icon {
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #1f1e1e;
            display: flex;
            font-size: 22px;
            height: 46px;
            justify-content: center;
            position: absolute;
            right: 16px;
            top: 16px;
            width: 46px;
        }

        .stat-card h3 {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 10px;
            padding-right: 58px;
            text-transform: uppercase;
        }

        .stat-card .number {
            color: #1f1e1e;
            font-size: 31px;
            font-weight: 700;
            line-height: 1.1;
            margin-top: 12px;
        }

        .stat-card .info {
            color: var(--text-secondary);
            font-size: 12px;
            margin-top: 5px;
        }

        .chart-grid {
            align-items: start;
            display: grid;
            gap: 20px;
            grid-template-columns: 2fr 1fr;
            margin-bottom: 20px;
        }

        .chart-grid.three {
            grid-template-columns: repeat(3, 1fr);
        }

        .panel {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-header {
            align-items: center;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            padding: 16px 18px;
        }

        .panel-header h3 {
            color: #1f1e1e;
            font-size: 17px;
            font-weight: 700;
            margin: 0;
        }

        .panel-header-subtitle {
            color: var(--text-secondary);
            font-size: 12px;
        }

        .panel-content {
            padding: 18px;
        }

        .chart-box {
            min-height: 300px;
        }

        .chart-box.compact {
            min-height: 260px;
        }

        table {
            background: #ffffff;
            border-collapse: collapse;
            width: 100%;
        }

        th {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            color: #6b6258;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 15px 20px;
            text-align: left;
            text-transform: uppercase;
        }

        td {
            border-bottom: 1px solid var(--border);
            color: #2b2928;
            font-size: 14px;
            padding: 15px 20px;
            vertical-align: middle;
        }

        tr:hover {
            background: #ffffff;
        }

        @media print {
            .app-header,
            .app-sidebar,
            .btn,
            .report-controls {
                display: none !important;
            }

            .app-wrapper,
            .app-main,
            .app-container,
            .main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .panel,
            .stat-card {
                box-shadow: none;
                break-inside: avoid;
            }
        }

        @media (max-width: 1024px) {
            .stats-grid,
            .chart-grid.three {
                grid-template-columns: repeat(2, 1fr);
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main {
                padding: 16px;
            }

            .header,
            .report-controls {
                align-items: flex-start;
                flex-direction: column;
                gap: 15px;
            }

            .stats-grid,
            .chart-grid.three {
                grid-template-columns: 1fr;
            }

            table {
                min-width: 720px;
            }

            .table-scroll {
                overflow-x: auto;
            }
        }
    </style>
    <style>
      /* layout patch: the shared sidebar (../includes/sidebar.php) is a fixed
         235px column and a fixed 58px top bar; shift the content beside/below them */
      /* plain block layout — nothing may re-grid the wrapper; width:auto stops
         the page from being one sidebar-width wider than the screen */
      .app-wrapper { display: block !important; width: auto !important; }
      .app-container { margin-left: 235px !important; padding-top: 58px !important; width: auto !important; }
      @media (max-width: 767.98px) { .app-container { margin-left: 0 !important; padding-top: 56px !important; } }
      body { overflow-x: hidden; }

      /* ============================================================
         VISUAL POLISH — same treatment as Admin_Dashboard.php:
         white cards, neutral gray hairlines, quiet typography.
         Loads last so it wins over the older look above — if you
         want to tweak how this page LOOKS, do it here.
         ============================================================ */
      .main { padding: 26px 30px; }

      /* page title row — open, no white strip behind it */
      .header { background: transparent; padding: 0 2px 4px; margin-bottom: 16px; }
      .header h1 { font-size: 22px; letter-spacing: -0.01em; }
      .header p { font-size: 13px; color: #8a857d; margin-top: 3px; }

      /* buttons — calmer: semibold, rounder, no jump on hover */
      .btn { font-weight: 600; font-size: 13.5px; border-radius: 10px; }
      .btn:hover { transform: none; }

      /* period selector bar — same card language as the panels */
      .report-controls { border-radius: 14px; padding: 13px 18px; }
      .filter-select { background: #ffffff; border: 1px solid #d7d7d7; border-radius: 8px; }

      /* stat cards — hairline borders, soft shadow, quiet labels,
         icon as a small muted mark instead of a boxed badge */
      .stats-grid { gap: 14px; margin-bottom: 22px; }
      .stat-card { border-radius: 14px; padding: 16px 18px; min-height: 116px; }
      .stat-card::before { display: none; } /* the black accent bar is gone */
      .stat-card .stat-icon { position: absolute; top: 14px; right: 16px; width: auto; height: auto; border: none; background: none; box-shadow: none; color: #c4bfb5; font-size: 17px; padding: 0; }
      .stat-card h3 { font-size: 10.5px; font-weight: 600; letter-spacing: 0.07em; color: #8a857d; margin-bottom: 0; padding-right: 30px; }
      .stat-card .number { font-size: 27px; font-weight: 700; letter-spacing: -0.01em; margin-top: 10px; }
      .stat-card .info { font-size: 11.5px; color: #8a857d; margin-top: 4px; }

      /* chart + table panels */
      .chart-grid { gap: 16px; }
      .panel { border-radius: 14px; }
      .panel-header { padding: 14px 18px; border-bottom: 1px solid #f0efec; }
      .panel-header h3 { font-size: 14.5px; font-weight: 650; }
      /* the halls and hostel-rooms tables, one under the other */
      .rp-detail + .rp-detail { margin-top: 16px; }
      .rp-detail td { font-variant-numeric: tabular-nums; }
      #bathroomTypes { font-size: 12.5px; color: #6f675d; }
      html.rp-print .rp-detail + .rp-detail { margin-top: 8px; }
      html.rp-print #bathroomTypes { font-size: 10px; padding: 4px 12px; }
      html.rp-print .stats-grid .stat-card { padding: 6px 10px; }
      html.rp-print .stats-grid .stat-card .number { font-size: 16px; margin-top: 2px; }
      html.rp-print .stats-grid .stat-card .info { font-size: 9px; margin-top: 1px; }

      /* ============================================================
         PRINT LAYOUT (2026-10-28). Paper is narrower than the 1024px
         breakpoint, so without this the page printed its PHONE layout —
         one tile per row, four mostly-empty pages, the charts cut off at
         the width they were drawn at on screen, and the table scrolling.
         html.rp-print is set by the script just before printing (Print
         PDF or Ctrl+P), so the charts can be measured and redrawn at the
         width they will have on paper; it is a class rather than only
         @media print so that measuring happens in the same layout.
         The content is 186mm wide — A4 or Letter with 12mm at each side.
         ============================================================ */
      .rp-print-meta { display: none; }
      @media print {
        html, body { background: #fff !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .app-header, .sidebar, .report-controls, .btn { display: none !important; }
      }
      html.rp-print .app-header, html.rp-print .sidebar,
      html.rp-print .report-controls, html.rp-print .btn { display: none !important; }
      html.rp-print body { overflow: visible; }
      html.rp-print .app-container { margin: 0 !important; padding: 0 !important; min-height: 0; display: block; }
      html.rp-print .main { width: 186mm; margin: 0 auto; padding: 0 !important; }
      html.rp-print .header { margin-bottom: 8px; padding: 0 0 6px; border-bottom: 1.5px solid #1f1e1e; display: block; }
      html.rp-print .header h1 { font-size: 20px; }
      html.rp-print .header p { font-size: 11.5px; }
      html.rp-print .rp-print-meta { display: flex; justify-content: space-between; gap: 12px; margin-top: 6px; font-size: 11.5px; color: #4a463f; }
      html.rp-print .rp-print-meta strong { color: #1f1e1e; }
      html.rp-print .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 8px; }
      html.rp-print .stat-card { min-height: 0; padding: 10px 12px; border-radius: 8px; box-shadow: none; break-inside: avoid; }
      html.rp-print .stat-card .stat-icon { display: none; }
      html.rp-print .stat-card h3 { font-size: 9px; padding-right: 0; }
      html.rp-print .stat-card .number { font-size: 19px; margin-top: 6px; }
      html.rp-print .stat-card .info { font-size: 9.5px; }
      html.rp-print .chart-grid { grid-template-columns: 3fr 2fr; gap: 8px; margin-bottom: 8px; align-items: stretch; }
      html.rp-print .chart-grid.three { grid-template-columns: repeat(3, 1fr); }
      html.rp-print .panel { border-radius: 8px; box-shadow: none; break-inside: avoid; }
      html.rp-print .panel-header { padding: 6px 12px; display: block; }
      html.rp-print .panel-header h3 { font-size: 12.5px; }
      html.rp-print .panel-header-subtitle { font-size: 10px; display: block; margin-top: 1px; }
      html.rp-print .panel-content { padding: 6px 8px; }
      html.rp-print .chart-box, html.rp-print .chart-box.compact { min-height: 0; }
      html.rp-print .table-scroll { overflow: visible; }
      html.rp-print table { min-width: 0; }
      html.rp-print th { padding: 5px 12px; font-size: 9.5px; }
      html.rp-print td { padding: 5px 12px; font-size: 11.5px; }
      html.rp-print tr { break-inside: avoid; }

      /* THE LETTERHEAD (2026-10-28; header 2026-11-08, first page only 2026-11-09) —
         USeP's own header on the first printed page and footer on every printed page
         (assets/css/print-letterhead.css): the seal, the university and the report's
         office at the top (App\Support\Letterhead),
         the vision / address / certifications strip at the bottom
         (assets/img/reports/). Charts print shorter so the whole report still
         fits on one page. */
      .rp-letterfoot { display: none; }
      html.rp-print .header p { display: none; }
      html.rp-print .main { --pl-foot-room: 34mm; }   /* the footer below: 7mm up, about 24mm tall */
      /* the old print rules above make .main the paper's full width; .pl-body's 12mm sides
         bring it back to the 186mm the charts are measured at */
      html.rp-print .rp-letterfoot { display: block; border-top: 1.5px solid #1f1e1e; padding-top: 6px; margin-top: 10px; }
      html.rp-print .rp-letterfoot img { display: block; width: 100%; height: auto; }
      @media print {
        html.rp-print .rp-letterfoot { margin: 0; }
      }
    </style>
@endpush

@section('content')
        <div class="app-container">
            <main class="main">
                <!-- printed only: the university letterhead, at the top of the first page -->
                <div class="pl-head"><img src="{{ asset($rpLetterhead[0]) }}" alt="{{ $rpLetterhead[1] }}"></div>
                <div class="pl-body">
                <div class="header">
                    <div>
                        <h1>Quarterly Reports</h1>
                        <p>Review revenue, booking counts, cancellations, and venue performance for the selected quarter.</p>
                        <!-- printed only: the period picker is hidden on paper, so say which quarter this is -->
                        <div class="rp-print-meta"><span>Reporting period: <strong id="rpPrintPeriod"></strong> &middot; Status: <span id="rpPrintStatus"></span></span><span id="rpPrintDate"></span></div>
                    </div>
                    <button class="btn btn-primary" onclick="printReport()">
                        <i class="bi bi-printer"></i>
                        Print PDF
                    </button>
                </div>
                @include('partials.report-office-toggle')

                <div class="report-controls">
                    <div class="report-period-control">
                        <strong>Reporting Period:</strong>
                        <!-- The same quarters the charts below are built from, newest
                             first. Hard-coded before, so it offered Q3 2025 – Q2 2026
                             long after the charts had moved on. -->
                        <select class="filter-select" id="reportPeriod" aria-label="Reporting period">
@foreach (array_reverse($rpQuarters) as $rpI => $rpQ)
                            <option value="{{ $rpQ['label'] }}"{{ $rpI === 0 ? ' selected' : '' }}>{{ $rpQ['label'] }}</option>
@endforeach
                        </select>
                    </div>
                    <!-- the SELECTED quarter: the current one is still running, an
                         earlier one is over — set by periodStatus() below -->
                    <span class="tag" id="rpStatus" title=""></span>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
                        <h3>Revenue Growth</h3>
                        <div class="number" id="revenueGrowthRate">0%</div>
                        <div class="info">vs prior quarter</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
                        <h3>Total Revenue</h3>
                        <div class="number" id="totalRevenue">₱0</div>
                        <div class="info">Paid transactions</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-calendar2-check"></i></span>
                        <h3>Events Held</h3>
                        <div class="number" id="totalEvents">0</div>
                        <div class="info">Approved bookings</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-receipt-cutoff"></i></span>
                        <h3>Avg. Paid / Booking</h3>
                        <div class="number" id="averageRevenue">₱0</div>
                        <div class="info">Selected quarter</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-journal-text"></i></span>
                        <h3>Bookings</h3>
                        <div class="number" id="totalBookings">0</div>
                        <div class="info">Cancelled ones left out</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-people"></i></span>
                        <h3>Customers</h3>
                        <div class="number" id="totalCustomers">0</div>
                        <div class="info">Each counted once</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-arrow-counterclockwise"></i></span>
                        <h3>Refunded</h3>
                        <div class="number" id="totalRefunded">₱0</div>
                        <div class="info" id="totalRefunds">0 bookings</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-x-octagon"></i></span>
                        <h3>Written Off</h3>
                        <div class="number" id="totalWrittenOff">₱0</div>
                        <div class="info" id="totalWriteOffs">0 bookings</div>
                    </div>
                </div>

                <div class="chart-grid">
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Revenue Growth by Quarter</h3>
                            <span class="panel-header-subtitle">Total revenue and average paid booking value</span>
                        </div>
                        <div class="panel-content">
                            <div id="revenueGrowthChart" class="chart-box"></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h3>Revenue by {{ $rpBy }}</h3>
                            <span class="panel-header-subtitle">Paid transaction share</span>
                        </div>
                        <div class="panel-content">
                            <div id="venueRevenueChart" class="chart-box"></div>
                        </div>
                    </div>
                </div>

                <div class="chart-grid three">
                    <div class="panel">
                        <div class="panel-header">
                            <h3>Events Held by {{ $rpBy }}</h3>
                            <span class="panel-header-subtitle">Approved bookings</span>
                        </div>
                        <div class="panel-content">
                            <div id="eventsByVenueChart" class="chart-box compact"></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h3>Discounted Bookings</h3>
                            <span class="panel-header-subtitle">Count by {{ strtolower($rpBy) }}</span>
                        </div>
                        <div class="panel-content">
                            <div id="discountedBookingsChart" class="chart-box compact"></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h3>Cancelled Reservations</h3>
                            <span class="panel-header-subtitle">Quarterly cancellation comparison</span>
                        </div>
                        <div class="panel-content">
                            <div id="cancelledReservationsChart" class="chart-box compact"></div>
                        </div>
                    </div>
                </div>

@if ($rpHalls)
                <div class="panel rp-detail">
                    <div class="panel-header">
                        <h3>Halls</h3>
                        <span class="panel-header-subtitle">Events held, with their booked days and hours</span>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Hall</th>
@if ($rpBy === 'Office')
                                    <th>Office</th>
@endif
                                    <th>Events Held</th>
                                    <th>Days</th>
                                    <th>Hours</th>
                                    <th>Discounted</th>
                                    <th>Cancelled</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="hallRows"></tbody>
                        </table>
                    </div>
                </div>
@endif
@if ($rpHostel)
                <div class="panel rp-detail">
                    <div class="panel-header">
                        <h3>Hostel Rooms</h3>
                        <span class="panel-header-subtitle">Stays held; bed-nights = nights &times; guests; occupancy = bed-nights &divide; (beds &times; days in the quarter)</span>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Bathroom</th>
                                    <th>Stays Held</th>
                                    <th>Bed-nights</th>
                                    <th>Occupancy</th>
                                    <th>Discounted</th>
                                    <th>Cancelled</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="hostelRows"></tbody>
                        </table>
                    </div>
                    <p class="panel-content" id="bathroomTypes"></p>
                </div>
@endif
                </div>
                <!-- printed only: the university footer — vision, address, certifications, at the foot of every page -->
                <div class="rp-letterfoot pl-foot"><img src="{{ asset('assets/img/reports/print-footer.png') }}" alt="USeP vision: Premier research university transforming communities in the ASEAN and beyond. University of Southeastern Philippines, Iñigo St., Bo. Obrero, Davao City, Philippines 8000 · (082) 227-8192 local 255 · www.usep.edu.ph · president@usep.edu.ph"></div>
            </main>
        </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js"></script>
    <script>

        /* Live aggregates for the chosen office(s) — see App\Queries\ReportQueries. */
        const quarterLabels = @json(array_column($rpQuarters, 'label'));
        const quarterRevenue = @json(array_map('floatval', array_column($rpQuarters, 'revenue')));
        const averageRevenuePerBooking = @json(array_map('floatval', array_column($rpQuarters, 'avg')));
        const cancelledByQuarter = @json(array_map('intval', array_column($rpQuarters, 'cancelled')));

        /* One row per hall or hostel room: name · office · kind (event|hostel) ·
           events = bookings held · discounted = bookings that got the USeP rate ·
           cancelled = fell through · revenue = money actually collected (an unpaid
           booking inflates nothing) · days, hours (halls) · beds, bed_nights,
           bathroom (hostel rooms).

           One breakdown per quarter, in the same order as quarterLabels, so the
           Reporting Period dropdown can narrow the page without a reload. */
        const venueByQuarter = @json($rpByQuarter);

        /* If there are no quarters at all (a brand-new database), fall back to
           the all-time breakdown so the charts still have something to draw. */
        const allTimeVenues = @json($allTimeRooms);

        /* Several offices: the charts compare the offices. One office: its halls or rooms. */
        const chartByOffice = @json($rpBy === 'Office');

        /* The dropdown opens on the newest quarter, so that is what the venue
           charts and the stat tiles describe on first paint. */
        let periodIndex = venueByQuarter.length - 1;
        let venueReport = venueByQuarter.length ? venueByQuarter[periodIndex].rooms : allTimeVenues;

        const currencyFormatter = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        });

        const numberFormatter = new Intl.NumberFormat('en-PH');

        /* Declared here, filled when the charts are built below: renderPeriod()
           runs once before that (for the tiles and the table) and then again on
           every dropdown change, so it has to tolerate charts not existing yet. */
        let venueRevenueChart = null;
        let eventsByVenueChart = null;
        let discountedBookingsChart = null;

        /* ApexCharts 3.37.1 cannot animate a rounded vertical bar whose value
           is zero: its path has no first point, so the morph reads [0] from
           undefined and emits NaN coordinates. Zero-value venues still belong
           in the table; charts omit only points that have no drawable value.
           An entirely empty metric then uses ApexCharts' real no-data state. */
        function venueChartRows(metric) {
            const sums = new Map();
            venueReport.forEach((item) => {
                const key = chartByOffice ? item.office : item.name;
                sums.set(key, (sums.get(key) || 0) + Number(item[metric]));
            });
            return [...sums].map(([venue, value]) => ({ venue, value }))
                .filter((item) => Number.isFinite(item.value) && item.value > 0);
        }

        /* The selected quarter's status (2026-10-28). The window always ends at
           the CURRENT quarter (ReportQueries::quarters()), which is still running — its
           numbers keep changing; every earlier one is over. Today's quarter
           comes from the server, not the viewer's clock. */
        const currentQuarter = @json($currentQuarter);
        function periodStatus(label) {
            const running = label === currentQuarter;
            const tag = document.querySelector('#rpStatus');
            tag.textContent = running ? 'Status: In progress' : 'Status: Closed';
            tag.className = 'tag ' + (running ? 'tag-progress' : 'tag-closed');
            tag.title = running ? 'This quarter is not over yet, so these figures will still change.'
                                : 'This quarter has ended.';
            document.querySelector('#rpPrintStatus').textContent = running ? 'In progress (figures still changing)' : 'Closed';
        }

        function renderPeriod() {
            const period = venueByQuarter[periodIndex] || null;
            venueReport = period ? period.rooms : allTimeVenues;
            periodStatus(period ? period.label : currentQuarter);

            const currentRevenue = period
                ? period.revenue
                : venueReport.reduce((sum, item) => sum + item.revenue, 0);
            const previousRevenue = periodIndex > 0 && venueByQuarter[periodIndex - 1]
                ? venueByQuarter[periodIndex - 1].revenue
                : 0;
            const revenueGrowth = previousRevenue > 0
                ? ((currentRevenue - previousRevenue) / previousRevenue) * 100
                : 0;
            const currentAverageRevenue = period ? period.avg : 0;
            const totalEvents = venueReport.reduce((sum, item) => sum + item.events, 0);

            /* The oldest quarter in the window has nothing before it to compare
               against — "0.0%" would read as "flat", which is a claim we cannot
               make. */
            document.querySelector('#revenueGrowthRate').textContent = previousRevenue > 0
                ? `${revenueGrowth.toFixed(1)}%`
                : '—';
            document.querySelector('#totalRevenue').textContent = currencyFormatter.format(currentRevenue);
            document.querySelector('#totalEvents').textContent = numberFormatter.format(totalEvents);
            document.querySelector('#averageRevenue').textContent = currencyFormatter.format(currentAverageRevenue);
            const bookings = (n) => `${numberFormatter.format(n)} booking${n === 1 ? '' : 's'}`;
            document.querySelector('#totalBookings').textContent = numberFormatter.format(period ? period.bookings : 0);
            document.querySelector('#totalCustomers').textContent = numberFormatter.format(period ? period.customers : 0);
            document.querySelector('#totalRefunded').textContent = currencyFormatter.format(period ? period.refunded : 0);
            document.querySelector('#totalRefunds').textContent = bookings(period ? period.refunds : 0);
            document.querySelector('#totalWrittenOff').textContent = currencyFormatter.format(period ? period.written_off : 0);
            document.querySelector('#totalWriteOffs').textContent = bookings(period ? period.write_offs : 0);

            /* hall, room and office names are typed by an admin: text, never markup */
            const escHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const shared = (item) => `
                    <td>${numberFormatter.format(item.discounted)}</td>
                    <td>${numberFormatter.format(item.cancelled)}</td>
                    <td>${currencyFormatter.format(item.revenue)}</td>`;
            const hallBody = document.querySelector('#hallRows');
            if (hallBody) {
                hallBody.innerHTML = venueReport.filter((item) => item.kind === 'event').map((item) => `
                <tr>
                    <td><strong>${escHtml(item.name)}</strong></td>
                    ${chartByOffice ? `<td>${escHtml(item.office)}</td>` : ''}
                    <td>${numberFormatter.format(item.events)}</td>
                    <td>${numberFormatter.format(item.days)}</td>
                    <td>${numberFormatter.format(Math.round(item.hours * 10) / 10)}</td>${shared(item)}
                </tr>
            `).join('');
            }
            /* occupancy needs the quarter's length: the all-time fallback shows a dash */
            const days = period ? period.days : 0;
            const occupancy = (nights, beds) => days > 0 && beds > 0 ? `${Math.round((nights / (beds * days)) * 100)}%` : '—';
            const bathroom = (type) => (type === 'private' ? 'Private CR' : 'Communal CR');
            const hostelBody = document.querySelector('#hostelRows');
            if (hostelBody) {
                const rooms = venueReport.filter((item) => item.kind === 'hostel');
                hostelBody.innerHTML = rooms.map((item) => `
                <tr>
                    <td><strong>${escHtml(item.name)}</strong></td>
                    <td>${bathroom(item.bathroom)}</td>
                    <td>${numberFormatter.format(item.events)}</td>
                    <td>${numberFormatter.format(item.bed_nights)}</td>
                    <td>${occupancy(item.bed_nights, item.beds)}</td>${shared(item)}
                </tr>
            `).join('');
                /* the bathroom types, from the same rows */
                const types = ['communal', 'private'].map((type) => {
                    const of = rooms.filter((item) => (item.bathroom || 'communal') === type);
                    const nights = of.reduce((sum, item) => sum + item.bed_nights, 0);
                    const beds = of.reduce((sum, item) => sum + item.beds, 0);
                    const money = of.reduce((sum, item) => sum + item.revenue, 0);
                    return `${bathroom(type)}: ${numberFormatter.format(nights)} bed-nights, ${occupancy(nights, beds)} occupancy, ${currencyFormatter.format(money)}`;
                });
                const allNights = rooms.reduce((sum, item) => sum + item.bed_nights, 0);
                const allBeds = rooms.reduce((sum, item) => sum + item.beds, 0);
                document.querySelector('#bathroomTypes').textContent =
                    `By bathroom type: ${types.join(' · ')}. All rooms: ${occupancy(allNights, allBeds)} occupancy.`;
            }

            const revenueRows = venueChartRows('revenue');
            const eventRows = venueChartRows('events');
            const discountedRows = venueChartRows('discounted');

            if (venueRevenueChart) {
                venueRevenueChart.updateOptions({
                    series: revenueRows.map((item) => item.value),
                    labels: revenueRows.map((item) => item.venue),
                });
            }
            if (eventsByVenueChart) {
                eventsByVenueChart.updateOptions({
                    series: [{ name: 'Events Held', data: eventRows.map((item) => item.value) }],
                    xaxis: { categories: eventRows.map((item) => item.venue) },
                });
            }
            if (discountedBookingsChart) {
                discountedBookingsChart.updateOptions({
                    series: [{ name: 'Discounted Bookings', data: discountedRows.map((item) => item.value) }],
                    xaxis: { categories: discountedRows.map((item) => item.venue) },
                });
            }
        }

        renderPeriod();

        /* The trend charts (revenue growth, cancellations) are deliberately NOT
           narrowed — a trend of one quarter is a single dot, and comparing the
           quarters is the point of the page. */
        const reportPeriodSelect = document.querySelector('#reportPeriod');
        if (reportPeriodSelect) {
            reportPeriodSelect.addEventListener('change', () => {
                const picked = venueByQuarter.findIndex((q) => q.label === reportPeriodSelect.value);
                if (picked === -1) {
                    return;
                }
                periodIndex = picked;
                renderPeriod();
            });
        }

        const chartTextColor = '#6f675d';
        const gridColor = '#ececec'; // chart grid lines — neutral gray, same family as the card borders
        const chartPalette = ['#1f1e1e', '#16a34a', '#d97706', '#2563eb', '#dc2626', '#6f675d'];

        const commonToolbar = {
            toolbar: {
                show: false,
            },
        };

        const initialRevenueRows = venueChartRows('revenue');
        const initialEventRows = venueChartRows('events');
        const initialDiscountedRows = venueChartRows('discounted');

        const revenueGrowthChart = new ApexCharts(document.querySelector('#revenueGrowthChart'), {
            series: [
                {
                    name: 'Total Revenue',
                    type: 'area',
                    data: quarterRevenue,
                },
                {
                    name: 'Avg. Revenue / Booking',
                    type: 'line',
                    data: averageRevenuePerBooking,
                },
            ],
            chart: {
                ...commonToolbar,
                height: 320,
                type: 'line',
            },
            colors: ['#1f1e1e', '#16a34a'],
            dataLabels: {
                enabled: false,
            },
            fill: {
                opacity: [0.16, 1],
                type: ['solid', 'solid'],
            },
            grid: {
                borderColor: gridColor,
            },
            labels: quarterLabels,
            legend: {
                labels: {
                    colors: chartTextColor,
                },
            },
            stroke: {
                curve: 'smooth',
                width: [3, 3],
            },
            tooltip: {
                y: {
                    formatter(value) {
                        return currencyFormatter.format(value);
                    },
                },
            },
            xaxis: {
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
            yaxis: {
                labels: {
                    formatter(value) {
                        return `₱${numberFormatter.format(Math.round(value / 1000))}k`;
                    },
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
        });
        revenueGrowthChart.render();

        venueRevenueChart = new ApexCharts(document.querySelector('#venueRevenueChart'), {
            series: initialRevenueRows.map((item) => item.value),
            chart: {
                height: 320,
                type: 'donut',
            },
            colors: chartPalette,
            dataLabels: {
                enabled: false,
            },
            labels: initialRevenueRows.map((item) => item.venue),
            legend: {
                position: 'bottom',
                labels: {
                    colors: chartTextColor,
                },
            },
            noData: {
                text: 'No revenue for this period',
            },
            tooltip: {
                y: {
                    formatter(value) {
                        return currencyFormatter.format(value);
                    },
                },
            },
        });
        venueRevenueChart.render();

        eventsByVenueChart = new ApexCharts(document.querySelector('#eventsByVenueChart'), {
            series: [
                {
                    name: 'Events Held',
                    data: initialEventRows.map((item) => item.value),
                },
            ],
            chart: {
                ...commonToolbar,
                height: 280,
                type: 'bar',
            },
            colors: ['#1f1e1e'],
            dataLabels: {
                enabled: false,
            },
            grid: {
                borderColor: gridColor,
            },
            noData: {
                text: 'No events for this period',
            },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    horizontal: true,
                },
            },
            xaxis: {
                categories: initialEventRows.map((item) => item.venue),
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
            yaxis: {
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
        });
        eventsByVenueChart.render();

        discountedBookingsChart = new ApexCharts(document.querySelector('#discountedBookingsChart'), {
            series: [
                {
                    name: 'Discounted Bookings',
                    data: initialDiscountedRows.map((item) => item.value),
                },
            ],
            chart: {
                ...commonToolbar,
                height: 280,
                type: 'bar',
            },
            colors: ['#d97706'],
            dataLabels: {
                enabled: false,
            },
            grid: {
                borderColor: gridColor,
            },
            noData: {
                text: 'No discounted bookings for this period',
            },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: '50%',
                },
            },
            xaxis: {
                categories: initialDiscountedRows.map((item) => item.venue),
                labels: {
                    rotate: -35,
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
            yaxis: {
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
        });
        discountedBookingsChart.render();

        const cancelledReservationsChart = new ApexCharts(document.querySelector('#cancelledReservationsChart'), {
            series: [
                {
                    name: 'Cancelled Reservations',
                    data: cancelledByQuarter,
                },
            ],
            chart: {
                ...commonToolbar,
                height: 280,
                type: 'area',
            },
            colors: ['#dc2626'],
            dataLabels: {
                enabled: false,
            },
            fill: {
                opacity: 0.14,
                type: 'solid',
            },
            grid: {
                borderColor: gridColor,
            },
            labels: quarterLabels,
            stroke: {
                curve: 'smooth',
                width: 3,
            },
            xaxis: {
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
            yaxis: {
                labels: {
                    style: {
                        colors: chartTextColor,
                    },
                },
            },
        });
        cancelledReservationsChart.render();

        /* ============================================================
           PRINTING (2026-10-28). An ApexCharts chart is drawn at a fixed
           pixel width, so on paper it kept its screen width and was cut
           off. Before printing, html.rp-print switches the page to its
           paper layout (see the PRINT LAYOUT styles), and every chart is
           redrawn at the width its box has there, with animation off so
           it is drawn at once; afterwards everything goes back.
           Ctrl+P fires beforeprint, which does the same synchronously.
           ============================================================ */
        /* Short charts on paper (the letterhead takes room too): the two whose
           x-axis labels slant on screen print them straight and smaller, or
           the slant would eat most of a 140px chart. `screenX` puts them back. */
        const flatLabels  = { labels: { rotate: 0, rotateAlways: false, hideOverlappingLabels: false, trim: false, style: { fontSize: '8px' } } };
        const slantLabels = (deg) => ({ labels: { rotate: deg, hideOverlappingLabels: true, style: { fontSize: '12px' } } });
        const printCharts = [
            { chart: revenueGrowthChart,         el: '#revenueGrowthChart',         screen: 320, paper: 125 },
            { chart: venueRevenueChart,          el: '#venueRevenueChart',          screen: 320, paper: 125 },
            { chart: eventsByVenueChart,         el: '#eventsByVenueChart',         screen: 280, paper: 115 },
            { chart: discountedBookingsChart,    el: '#discountedBookingsChart',    screen: 280, paper: 115,
              paperX: { xaxis: flatLabels }, screenX: { xaxis: slantLabels(-35) } },
            /* this chart takes its x values from top-level `labels`; they are passed
               again, or changing the axis drops them and it prints "1 2 3 4" */
            { chart: cancelledReservationsChart, el: '#cancelledReservationsChart', screen: 280, paper: 115,
              paperX: { labels: quarterLabels, xaxis: flatLabels }, screenX: { labels: quarterLabels, xaxis: slantLabels(-45) } },
        ];
        let printPrepared = false;
        function prepareForPrint() {
            if (printPrepared) return;
            printPrepared = true;
            const sel = document.querySelector('#reportPeriod');
            document.querySelector('#rpPrintPeriod').textContent = sel ? sel.value : '—';
            document.querySelector('#rpPrintDate').textContent = 'Printed ' + new Date().toLocaleString('en-PH',
                { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
            document.documentElement.classList.add('rp-print');
            printCharts.forEach(({ chart, el, paper, paperX }) => {
                const box = document.querySelector(el);
                chart.updateOptions(Object.assign({ chart: { width: box.clientWidth, height: paper, animations: { enabled: false } } }, paperX || {}), false, false);
            });
        }
        function restoreAfterPrint() {
            if (!printPrepared) return;
            printPrepared = false;
            document.documentElement.classList.remove('rp-print');
            printCharts.forEach(({ chart, screen, screenX }) => {
                chart.updateOptions(Object.assign({ chart: { width: '100%', height: screen, animations: { enabled: true } } }, screenX || {}), false, false);
            });
        }
        function printReport() {
            prepareForPrint();
            /* one frame for the redrawn charts to land before the dialog opens */
            requestAnimationFrame(() => setTimeout(() => window.print(), 50));
        }
        window.addEventListener('beforeprint', prepareForPrint);
        window.addEventListener('afterprint', restoreAfterPrint);
    </script>
@endpush
