<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quarterly Reports - USeP Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <!-- AdminLTE css removed in the merge: this page styles itself, and
         AdminLTE's app-wrapper grid fought the shared fixed header/sidebar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.css">
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

        .tag-finalized {
            background: #1f1e1e;
            color: #ffffff;
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
    </style>
</head>
<body>
    <div class="app-wrapper">
            <!-- shared header: edit ../includes/header.php and every page updates -->
    <?php include __DIR__ . '/../includes/header.php'; ?>

            <!-- shared sidebar: edit ../includes/sidebar.php and every page updates -->
    <?php $active = 'Reports'; include __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="app-container">
            <main class="main">
                <div class="header">
                    <div>
                        <h1>Quarterly Reports</h1>
                        <p>Review revenue, booking counts, cancellations, and venue performance for the current quarter.</p>
                    </div>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer"></i>
                        Print PDF
                    </button>
                </div>

                <div class="report-controls">
                    <div class="report-period-control">
                        <strong>Reporting Period:</strong>
                        <select class="filter-select" aria-label="Reporting period">
                            <option selected>Q2 2026</option>
                            <option>Q1 2026</option>
                            <option>Q4 2025</option>
                            <option>Q3 2025</option>
                        </select>
                    </div>
                    <span class="tag tag-finalized">Status: Finalized</span>
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
                        <div class="info">Current quarter</div>
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
                            <h3>Revenue by Venue</h3>
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
                            <h3>Events Held by Venue</h3>
                            <span class="panel-header-subtitle">Approved bookings</span>
                        </div>
                        <div class="panel-content">
                            <div id="eventsByVenueChart" class="chart-box compact"></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <h3>Discounted Bookings</h3>
                            <span class="panel-header-subtitle">Quarterly count by venue</span>
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

                <div class="panel">
                    <div class="panel-header">
                        <h3>Venue Report Details</h3>
                        <span class="panel-header-subtitle">Same data shown in the venue charts</span>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Venue</th>
                                    <th>Events Held</th>
                                    <th>Discounted Bookings</th>
                                    <th>Cancelled Reservations</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="venueReportRows"></tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- AdminLTE js removed in the merge (its layout code fought the shared shell) -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.37.1/dist/apexcharts.min.js"></script>
    <script>
        /*
            DATABASE INPUT GUIDE:
            Replace the sample data below with values from your database later.

            PHP example:
            $quarterLabels = ['Q3 2025', 'Q4 2025', 'Q1 2026', 'Q2 2026'];
            $quarterRevenue = [210000, 245000, 260000, 315000];
            $averageRevenuePerBooking = [7000, 7903, 8125, 8750];
            $venueReport = [
                ['venue' => 'Social Hall', 'events' => 14, 'discounted' => 4, 'cancelled' => 2, 'revenue' => 126000],
                ['venue' => 'Gymnasium', 'events' => 10, 'discounted' => 2, 'cancelled' => 1, 'revenue' => 98000],
            ];

            Then echo the arrays into JavaScript using json_encode:
            const quarterLabels = PHP_JSON_ENCODE_QUARTER_LABELS;
            const quarterRevenue = PHP_JSON_ENCODE_QUARTER_REVENUE;
            const averageRevenuePerBooking = PHP_JSON_ENCODE_AVERAGE_REVENUE;
            const venueReport = PHP_JSON_ENCODE_VENUE_REPORT;
        */

        const quarterLabels = ['Q3 2025', 'Q4 2025', 'Q1 2026', 'Q2 2026'];
        const quarterRevenue = [210000, 245000, 260000, 315000];
        const averageRevenuePerBooking = [7000, 7903, 8125, 8750];
        const cancelledByQuarter = [5, 4, 6, 3];

        /*
            DATABASE INPUT GUIDE:
            Keep these property names when replacing this array:
            venue = venue name
            events = approved/held bookings for the selected quarter
            discounted = bookings with a discount attached
            cancelled = cancelled reservations for the selected quarter
            revenue = paid revenue for the selected quarter
        */
        const venueReport = [
            { venue: 'Social Hall', events: 14, discounted: 4, cancelled: 2, revenue: 126000 },
            { venue: 'Gymnasium', events: 10, discounted: 2, cancelled: 1, revenue: 98000 },
            { venue: 'Auditorium', events: 7, discounted: 1, cancelled: 0, revenue: 63000 },
            { venue: 'Bahay Alumni', events: 5, discounted: 2, cancelled: 0, revenue: 28000 },
        ];

        const currencyFormatter = new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        });

        const numberFormatter = new Intl.NumberFormat('en-PH');
        const currentRevenue = quarterRevenue[quarterRevenue.length - 1] || 0;
        const previousRevenue = quarterRevenue[quarterRevenue.length - 2] || 0;
        const revenueGrowth = previousRevenue > 0 ? ((currentRevenue - previousRevenue) / previousRevenue) * 100 : 0;
        const currentAverageRevenue = averageRevenuePerBooking[averageRevenuePerBooking.length - 1] || 0;
        const totalEvents = venueReport.reduce((sum, item) => sum + item.events, 0);

        document.querySelector('#revenueGrowthRate').textContent = `${revenueGrowth.toFixed(1)}%`;
        document.querySelector('#totalRevenue').textContent = currencyFormatter.format(currentRevenue);
        document.querySelector('#totalEvents').textContent = numberFormatter.format(totalEvents);
        document.querySelector('#averageRevenue').textContent = currencyFormatter.format(currentAverageRevenue);

        document.querySelector('#venueReportRows').innerHTML = venueReport.map((item) => `
            <tr>
                <td><strong>${item.venue}</strong></td>
                <td>${numberFormatter.format(item.events)}</td>
                <td>${numberFormatter.format(item.discounted)}</td>
                <td>${numberFormatter.format(item.cancelled)}</td>
                <td>${currencyFormatter.format(item.revenue)}</td>
            </tr>
        `).join('');

        const chartTextColor = '#6f675d';
        const gridColor = '#ececec'; // chart grid lines — neutral gray, same family as the card borders
        const chartPalette = ['#1f1e1e', '#16a34a', '#d97706', '#2563eb', '#dc2626', '#6f675d'];

        const commonToolbar = {
            toolbar: {
                show: false,
            },
        };

        new ApexCharts(document.querySelector('#revenueGrowthChart'), {
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
        }).render();

        new ApexCharts(document.querySelector('#venueRevenueChart'), {
            series: venueReport.map((item) => item.revenue),
            chart: {
                height: 320,
                type: 'donut',
            },
            colors: chartPalette,
            dataLabels: {
                enabled: false,
            },
            labels: venueReport.map((item) => item.venue),
            legend: {
                position: 'bottom',
                labels: {
                    colors: chartTextColor,
                },
            },
            tooltip: {
                y: {
                    formatter(value) {
                        return currencyFormatter.format(value);
                    },
                },
            },
        }).render();

        new ApexCharts(document.querySelector('#eventsByVenueChart'), {
            series: [
                {
                    name: 'Events Held',
                    data: venueReport.map((item) => item.events),
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
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    horizontal: true,
                },
            },
            xaxis: {
                categories: venueReport.map((item) => item.venue),
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
        }).render();

        new ApexCharts(document.querySelector('#discountedBookingsChart'), {
            series: [
                {
                    name: 'Discounted Bookings',
                    data: venueReport.map((item) => item.discounted),
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
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: '50%',
                },
            },
            xaxis: {
                categories: venueReport.map((item) => item.venue),
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
        }).render();

        new ApexCharts(document.querySelector('#cancelledReservationsChart'), {
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
        }).render();
    </script>
</body>
</html>
