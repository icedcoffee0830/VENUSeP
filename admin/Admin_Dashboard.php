<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USeP Venue Booking - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <!-- AdminLTE css/js removed in the merge: this page styles every component
         itself, and AdminLTE's app-wrapper grid + layout JS fought the shared
         fixed header/sidebar (content jumped after load). -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

    /* Specific styling for the New Booking Modal on this page only */
    #newBookingModal .modal {
        border-radius: 0; /* Makes it square */
        max-height: 80vh; /* Sets a maximum height */
        display: flex;
        flex-direction: column;
    }

    #newBookingModal .modal-body {
        overflow-y: auto; /* Enables vertical scrolling */
        flex: 1; /* Allows body to take up remaining space */
    }

    /* Optional: Custom scrollbar for a cleaner look */
    #newBookingModal .modal-body::-webkit-scrollbar {
        width: 8px;
    }
    #newBookingModal .modal-body::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    #newBookingModal .modal-body::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }
    #newBookingModal .modal-body::-webkit-scrollbar-thumb:hover {
        background: #555;
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

/* --- BUTTONS --- */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn:hover {
    transform: translateY(-1px);
}

.btn-primary {
    background: var(--primary);
    color: var(--text-white);
}

.btn-primary:hover {
    background: var(--primary-dark);
}

.btn-success {
    background: var(--success);
    color: var(--text-white);
}

.btn-danger {
    background: var(--danger);
    color: var(--text-white);
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-small-action {
    padding: 6px 10px;
    font-size: 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.btn-outline {
    background: var(--bg-white);
    border: 1px solid var(--border);
    color: var(--text-primary);
}

.btn-outline:hover {
    background: var(--bg-hover);
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

    /* Search Dropdown Styling */
.search-container {
    position: relative;
}

.search-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1000;
    background: #fff;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.search-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    transition: background 0.2s;
}

.search-item:hover {
    background-color: #f8f9fa;
}

.search-item strong {
    display: block;
    color: #333;
}

.search-item span {
    font-size: 0.85em;
    color: #666;
}

/* Dashboard redesign inspired by the AdminLTE reference, scoped to this page */
:root {
    --primary: #1f1e1e;
    --primary-light: #4b4742;
    --primary-dark: #111010;
    --accent: #ffffff;
    --accent-light: rgba(255, 245, 232, 0.18);
    --bg-page: #ffffff;
    --bg-white: #ffffff;
    --bg-sidebar: #1f1e1e;
    --bg-hover: #ffffff;
    --bg-input: #ffffff;
    --text-primary: #1f1e1e;
    --text-secondary: #6f675d;
    --text-muted: #9a9083;
    --text-white: #ffffff;
    --text-white-muted: rgba(255, 255, 255, 0.8);
    --border: #e5e5e5; /* neutral gray hairline — same as venue-management / booking-requests */
    --border-light: rgba(255, 245, 232, 0.18);
    --success: #16a34a;
    --danger: #dc2626;
    --shadow: 0 10px 30px rgba(31, 30, 30, 0.08);
    --shadow-lg: 0 24px 70px rgba(31, 30, 30, 0.22);
    --sidebar-width: 250px;
    --sidebar-collapsed: 80px;
}

body {
    background: #ffffff;
    color: var(--text-primary);
    font-family: "Inter", "Segoe UI", Arial, sans-serif;
    line-height: 1.5;
}

.app-container {
    background: transparent;
    display: flex;
    min-height: 100vh;
    width: 100%;
}

.sidebar {
    background: #1f1e1e;
    border-right: 1px solid rgba(255, 245, 232, 0.12);
    box-shadow: 10px 0 30px rgba(31, 30, 30, 0.12);
    padding: 18px;
}

.brand {
    border-bottom-color: rgba(255, 245, 232, 0.16);
    margin-bottom: 22px;
}

.brand h2 {
    color: #ffffff;
    font-size: 21px;
    letter-spacing: 0;
}

.brand p,
.admin-text span {
    color: rgba(255, 245, 232, 0.66);
}

.avatar {
    background: #ffffff;
    color: #1f1e1e;
    box-shadow: inset 0 0 0 1px rgba(31, 30, 30, 0.12);
}

.admin-info {
    background: rgba(255, 245, 232, 0.08);
    border: 1px solid rgba(255, 245, 232, 0.12);
    border-radius: 8px;
    margin-bottom: 24px;
    padding: 12px;
}

.nav-menu a {
    border-radius: 6px;
    color: rgba(255, 245, 232, 0.72);
    padding: 11px 13px;
}

.nav-menu a:hover,
.nav-menu a.active {
    background: #ffffff;
    color: #1f1e1e;
}

.badge {
    background: #ffffff;
    color: #1f1e1e;
}

.main {
    padding: 24px 28px;
    flex: 1;
    transition: margin-left 0.3s;
}

.header {
    background: #ffffff;
    margin-bottom: 12px;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header h1 {
    color: #1f1e1e;
    font-weight: 700;
    letter-spacing: 0;
    font-size: 24px;
}

.header p {
    color: var(--text-secondary);
    font-size: 14px;
    margin-top: 5px;
}

.btn {
    border-radius: 6px;
    box-shadow: none;
    font-weight: 700;
}

.btn-primary {
    background: #1f1e1e;
    border: 1px solid #1f1e1e;
    color: #ffffff;
}

.btn-primary:hover {
    background: #353230;
}

.btn-outline {
    background: #ffffff;
    border-color: #d9cbb7;
    color: #1f1e1e;
}

.btn-outline:hover {
    background: #1f1e1e;
    border-color: #1f1e1e;
    color: #ffffff;
}

/* Approve / Reject — soft tints per the team palette (matches the b-green /
   b-red status badges on booking-request.php: no loud fills, no bold) */
.btn-success,
.btn-danger {
    border-radius: 9px;
    font-weight: 600;
}

.btn-success {
    background: #eaf6ef;
    border: 1px solid #bfe0cd;
    color: #1c7a4f;
}

.btn-success:hover {
    background: #dff0e7;
    border-color: #9fd1b6;
    transform: none;
}

.btn-danger {
    background: #fcecec;
    border: 1px solid #f0c9c9;
    color: #b23a3a;
}

.btn-danger:hover {
    background: #f8e2e2;
    border-color: #e4b1b1;
    transform: none;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card {
    border: 1px solid var(--border);
    min-height: 126px;
    overflow: hidden;
    position: relative;
    background: var(--bg-white);
    padding: 20px;
    border-radius: 10px;
    box-shadow: var(--shadow);
}

.stat-card::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: #1f1e1e;
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
    letter-spacing: 0.02em;
    padding-right: 58px;
    text-transform: uppercase;
    font-size: 14px;
    margin-bottom: 10px;
    font-weight: 700    ;
}

.stat-card .number {
    color: #1f1e1e;
    font-size: 31px;
    line-height: 1.1;
    margin-top: 12px;
    font-weight: bold;
}

.stat-card .info {
    color: var(--text-secondary);
    font-size: 12px;
    margin-top: 5px;
}

.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    align-items: start;
    gap: 20px;
}

.panel {
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.panel-header {
    background: #ffffff;
    border-bottom-color: var(--border);
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.panel-header h3 {
    color: #1f1e1e;
    font-size: 17px;
    font-weight: 700;
}

table {
    background: #ffffff;
    width: 100%;
    border-collapse: collapse;
}

th {
    background: #ffffff;
    border-bottom: 1px solid var(--border);
    color: #6b6258;
    font-size: 11px;
    letter-spacing: 0.05em;
    text-align: left;
    padding: 15px 20px;
    text-transform: uppercase;
    font-weight: 600;
}

td {
    border-bottom-color: #f0e5d6;
    color: #2b2928;
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    vertical-align: middle;
}

tr:hover {
    background: #ffffff;
}

.tag {
    border-radius: 999px;
    font-size: 11px;
    letter-spacing: 0.01em;
    padding: 5px 10px;
    font-weight: bold;
    display: inline-block;
}

.tag-online,
.tag-walkin {
    background: #1f1e1e;
    color: #ffffff;
}

.tag-pending {
    background: #fff2cf;
    color: #715111;
}

.tag-approved,
.tag-paid,
.tag-active {
    background: #e6f4df;
    color: #245b21;
}

.tag-rejected,
.tag-cancelled {
    background: #f8ded9;
    color: #81281f;
}

.user-info span {
    color: var(--text-secondary);
}

.user-cell,
.user-profile {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-info p,
.u-info p {
    font-weight: bold;
    margin-bottom: 2px;
}

.modal-overlay {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(31, 30, 30, 0.58);
    backdrop-filter: blur(2px);
    align-items: center;
    justify-content: center;
}

.modal-overlay.show {
    display: flex !important;
}

#newBookingModal .modal,
.modal {
    background: var(--bg-white);
    width: 90%;
    max-width: 500px;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
    animation: slideUp 0.3s ease-out;
}

.modal-header {
    background: #1f1e1e;
    border-bottom: none;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    font-size: 18px;
}

.modal-header h2,
.close-btn {
    color: #ffffff;
}

.close-btn:hover {
    color: #ffffff;
}

.modal-body,
.modal-footer {
    background: #ffffff;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 0 24px 24px 24px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.close-btn {
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    line-height: 20px;
    background: none;
    border: none;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.form-group input,
.form-group select,
.form-group textarea,
.form-control {
    width: 100%;
    padding: 10px 12px;
    background: #ffffff;
    border: 1px solid #d9cbb7;
    border-color: #d9cbb7;
    border-radius: 6px;
    color: #1f1e1e;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus,
.form-control:focus {
    outline: none;
    border-color: #1f1e1e;
    box-shadow: 0 0 0 3px rgba(31, 30, 30, 0.12);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.search-dropdown {
    border-color: #d9cbb7;
    border-radius: 0 0 8px 8px;
}

.search-item:hover {
    background: #ffffff;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 16px 20px;
    border-radius: 10px;
    color: var(--text-white);
    font-weight: 600;
    z-index: 4000;
    display: none;
    animation: slideInRight 0.3s ease-out;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }
.toast.info { background: var(--primary); }

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

.brand-text {
    color: #ffffff;
    font-weight: 700;
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

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .content-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .main {
        padding: 16px;
        margin-left: var(--sidebar-collapsed);
    }

    .header {
        padding: 18px;
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }

    .stat-card .stat-icon {
        height: 40px;
        width: 40px;
    }

    .stats-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    table {
        font-size: 12px;
    }

    th,
    td {
        padding: 10px;
    }
}
</style>
    <style>
      /* layout patch: the shared sidebar (../includes/sidebar.php) is a fixed
         235px column and a fixed 58px top bar; shift the content beside/below them */
      /* plain block layout — nothing may re-grid the wrapper */
      .app-wrapper { display: block !important; width: auto !important; }
      /* width:auto is the horizontal-scroll fix — the old width:100% PLUS the
         235px margin made the page exactly one sidebar wider than the screen */
      .app-container { margin-left: 235px !important; padding-top: 58px !important; width: auto !important; }
      @media (max-width: 767.98px) { .app-container { margin-left: 0 !important; padding-top: 56px !important; } }
      body { overflow-x: hidden; }
      .table-container { overflow-x: auto; }
      .content-grid > .panel { min-width: 0; } /* wide badges can't blow the grid open */

      /* ============================================================
         DASHBOARD = OVERVIEW ONLY. Cards + rows just LINK into
         booking-requests.php (queue) and booking-request.php (detail);
         all actions happen there, next to the evidence.
         ============================================================ */
      .stats-grid { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)) !important; }
      a.stat-card { display: block; text-decoration: none; color: inherit; cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s; }
      a.stat-card:hover { border-color: #1f1e1e; box-shadow: 0 2px 8px rgba(31, 30, 30, 0.08); }

      /* status badges — same look + vocabulary as booking-requests.php */
      .br-badge { border: 1px solid transparent; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.38rem; font-size: 0.72rem; font-weight: 400; padding: 0.28rem 0.66rem; white-space: nowrap; }
      .br-badge::before { border-radius: 999px; content: ''; height: 6px; width: 6px; }
      .b-gray  { background: #ffffff; border-color: #e6e2db; color: #6b675f; }
      .b-gray::before  { background: #b9b5ad; }
      .b-green { background: #eaf6ef; color: #1c7a4f; }
      .b-green::before { background: #2f9e63; }
      .b-amber { background: #fdf3e6; color: #8a5a12; }
      .b-amber::before { background: #d9930d; }
      .b-red   { background: #fcecec; color: #b23a3a; }
      .b-red::before   { background: #cf4a4a; }
      .b-navy  { background: #eef1f8; color: #1f2a44; }
      .b-navy::before  { background: #1f2a44; }

      /* urgent-first preview rows — each row is ONE link to the detail page */
      a.ad-row { display: grid; grid-template-columns: 1.15fr 0.9fr 1.6fr 14px; gap: 12px; align-items: center; padding: 12px 8px; border-top: 1px solid #eee9e2; text-decoration: none; color: inherit; }
      a.ad-row:first-child { border-top: none; }
      a.ad-row:hover { background: #faf9f7; }
      .ad-row .r-name { font-weight: 600; font-size: 13.5px; color: #1f1e1e; }
      .ad-row .r-room { font-weight: 500; font-size: 13px; color: #1f1e1e; }
      .ad-row .r-sub { font-size: 11.5px; color: #8a857d; margin-top: 2px; }
      .ad-row .r-badges { display: flex; flex-wrap: wrap; gap: 6px; }
      .ad-row .r-act { font-size: 11.5px; color: #6b675f; margin-top: 4px; }
      .ad-row .r-act.warn { color: #8a5a12; }
      .ad-row .r-act.late { color: #b23a3a; }
      .ad-row .r-chev { color: #b9b5ad; font-size: 16px; }
      @media (max-width: 900px) { a.ad-row { grid-template-columns: 1fr 1.4fr 14px; } .ad-row .r-roomcol { display: none; } }

      /* venue overview rows (venue = location container — no price here) */
      .ad-venue { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 8px; border-top: 1px solid #eee9e2; }
      .ad-venue:first-child { border-top: none; }

      /* ============================================================
         VISUAL POLISH — final pass, matching the team's minimal
         style (white cards, hairline borders, quiet typography).
         Loads last so it wins over the older look above — if you
         want to tweak how the dashboard LOOKS, do it here.
         ============================================================ */
      body { background: #ffffff; } /* pure white, same as every other page */
      .main { padding: 26px 30px; }

      /* page title row — open, no white strip behind it */
      .header { background: transparent; padding: 0 2px 4px; margin-bottom: 16px; }
      .header h1 { font-size: 22px; letter-spacing: -0.01em; }
      .header p { font-size: 13px; color: #8a857d; margin-top: 3px; }

      /* buttons — calmer: semibold, rounder, no jump on hover */
      .btn { font-weight: 600; font-size: 13.5px; border-radius: 10px; }
      .btn:hover { transform: none; }
      .btn-outline { border: 1px solid #ddd7ce; }
      .btn-outline:hover { background: #f4f2ee; border-color: #c9c2b6; color: #1f1e1e; }

      /* overview cards — hairline borders, soft shadow, quiet labels,
         icon as a small muted mark instead of a boxed badge */
      .stats-grid { gap: 14px; margin-bottom: 22px; }
      .stat-card { border: 1px solid #e5e5e5; border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); padding: 16px 18px; min-height: 116px; }
      .stat-card::before { display: none; } /* the black accent bar is gone */
      .stat-card .stat-icon { position: absolute; top: 14px; right: 16px; width: auto; height: auto; border: none; background: none; box-shadow: none; color: #c4bfb5; font-size: 17px; padding: 0; }
      .stat-card h3 { font-size: 10.5px; font-weight: 600; letter-spacing: 0.07em; color: #8a857d; margin-bottom: 0; padding-right: 30px; }
      .stat-card .number { font-size: 27px; font-weight: 700; letter-spacing: -0.01em; margin-top: 10px; }
      .stat-card .info { font-size: 11.5px; color: #8a857d; margin-top: 4px; }
      a.stat-card:hover { border-color: #1f1e1e; box-shadow: 0 2px 10px rgba(31, 30, 30, 0.07); }

      /* panels — same card language as the rest of the system */
      .content-grid { gap: 16px; }
      .panel { border: 1px solid #e5e5e5; border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
      .panel-header { padding: 14px 18px; border-bottom: 1px solid #f0efec; }
      .panel-header h3 { font-size: 14.5px; font-weight: 650; }

      /* list rows breathe the same as the panel header */
      a.ad-row { padding: 13px 18px; border-top: 1px solid #f0efec; }
      .ad-venue { padding: 14px 18px; border-top: 1px solid #f0efec; }
    </style>
</head>
<body>
        <div class="app-wrapper">
          <!-- shared header: edit ../includes/header.php and every page updates -->
    <?php include __DIR__ . '/../includes/header.php'; ?>

          <!-- shared sidebar: edit ../includes/sidebar.php and every page updates -->
    <?php $active = 'Dashboard'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <div class="app-container">
        <main class="main">
            <div class="header">
                <div>
                    <h1>Admin Dashboard</h1>
                    <p>Welcome, Admin | Managing: USeP Venues And Bahay Alumni</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('newBookingModal')">+ New Booking</button>
            </div>

            <!-- Overview cards: the FIRST FOUR are the exact same needs-action
                 buckets as the tabs on booking-requests.php — clicking one opens
                 the queue pre-filtered to that bucket, so dashboard numbers and
                 queue tabs can never disagree.
                 [SIM] counts are computed from the demo data in the page script. -->
            <div class="stats-grid">
                <a class="stat-card" href="booking-requests.php?tab=id">
                    <span class="stat-icon"><i class="bi bi-person-vcard"></i></span>
                    <h3>Pending ID Review</h3>
                    <div class="number" id="statId">–</div>
                    <div class="info">Approve ID + reservation →</div>
                </a>
                <a class="stat-card" href="booking-requests.php?tab=confirm">
                    <span class="stat-icon"><i class="bi bi-receipt"></i></span>
                    <h3>Receipts to Confirm</h3>
                    <div class="number" id="statConfirm">–</div>
                    <div class="info">Match the ref in GCash →</div>
                </a>
                <a class="stat-card" href="booking-requests.php?tab=review">
                    <span class="stat-icon"><i class="bi bi-eye"></i></span>
                    <h3>Manual Review</h3>
                    <div class="number" id="statReview">–</div>
                    <div class="info">Flagged receipts →</div>
                </a>
                <a class="stat-card" href="booking-requests.php?tab=overdue">
                    <span class="stat-icon"><i class="bi bi-alarm"></i></span>
                    <h3>Payment Overdue</h3>
                    <div class="number" id="statOverdue">–</div>
                    <div class="info">Slots releasable →</div>
                </a>
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-calendar2-check"></i></span>
                    <h3>Today's Bookings</h3>
                    <div class="number">2</div> <!-- [SIM] demo value -->
                    <div class="info">Rooms in use today</div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon"><i class="bi bi-cash-stack"></i></span>
                    <h3>Revenue</h3>
                    <div class="number">₱315,000</div> <!-- [SIM] matches Quarterly_Reports Q2 2026 -->
                    <div class="info">Current quarter</div>
                </div>
            </div>

            <div class="content-grid">
                <div class="panel">
                    <div class="panel-header">
                        <h3>Booking Requests — most urgent first</h3>
                        <a href="booking-requests.php" class="btn btn-small btn-outline">View All Requests</a>
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
                        <a href="venue-management.php" class="btn btn-small btn-outline">Manage Venues</a>
                    </div>
                    <!-- Venue = location container in the new model (no base rate,
                         venues aren't bookable). [SIM] hard-coded to match the
                         venue-management.php cards; comes from the database later. -->
                    <div>
                        <div class="ad-venue">
                            <div>
                                <div class="r-name" style="font-weight:600;font-size:13.5px;color:#1f1e1e">Bahay Alumni</div>
                                <div class="r-sub" style="font-size:11.5px;color:#8a857d;margin-top:2px">Heritage location for alumni events</div>
                            </div>
                            <span style="font-size:12px;color:#6b675f;white-space:nowrap">8 rooms</span>
                        </div>
                        <div class="ad-venue">
                            <div>
                                <div class="r-name" style="font-weight:600;font-size:13.5px;color:#1f1e1e">USeP Venues</div>
                                <div class="r-sub" style="font-size:11.5px;color:#8a857d;margin-top:2px">Main campus halls and function rooms</div>
                            </div>
                            <span style="font-size:12px;color:#6b675f;white-space:nowrap">12 rooms</span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="newBookingModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2>Create New Booking</h2>
            <button class="close-btn" onclick="closeModal('newBookingModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="newBookingForm">
                <div class="form-group">
                    <label>Walk-in Type</label>
                    <select id="walkinType" onchange="toggleWalkinFields()" class="form-control">
                        <option value="pure">Pure Walk-in (No Account)</option>
                        <option value="acc">Walk-in (With Account)</option>
                    </select>
                </div>

               <div class="form-group" id="accountSearchField" style="display: none;">
    <label>Search User (Email or Full Name)</label>
    <div class="search-container"> <input type="text" id="userSearch" class="form-control" placeholder="Start typing name or email..." onkeyup="searchAccounts(this.value)" autocomplete="off">
        <div id="searchResults" class="search-dropdown"></div> </div>
    <input type="hidden" id="selectedUserId" name="user_id">
</div>

                <div id="pureWalkinFields">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" id="wName" class="form-control" placeholder="Guest Name" required>
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="tel" id="wContact" class="form-control" placeholder="09XXXXXXXXX" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="wEmail" class="form-control" placeholder="guest@example.com" required>
                    </div>
                    

                </div>
                

                <div class="form-grid">

                                    <div class="form-group">
                            <label>Applied Discount</label>
    <select id="discountSelect" class="form-control">
            <option value="">
                
                (%)
            </option>
        
    </select>
</div>

                    <div class="form-group">
                        <label>Venue</label>
                        <select id="venueSelect" class="form-control" onchange="fetchRooms(this.value)" required>
                            <option value="">Select Venue</option>
                            
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Room</label>
                        <select id="roomSelect" class="form-control" required>
                            <option value="">Select Room</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Event Name</label>
                    <input type="text" id="eventName" class="form-control" required>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="bookingDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" id="startTime" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <input type="time" id="endTime" class="form-control" required>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="submitWalkin()">Confirm Booking</button>
        </div>
        <div id="toast" class="toast"></div>
    </div>
</div>

<script>
/* ============================================================
   DASHBOARD SCRIPT (demo only, no backend):
   · RES / PAY / BR [SIM] — copied from booking-requests.php so the
     dashboard speaks the SAME status vocabulary; keep them in sync
   · fills the four needs-action counts (same buckets as queue tabs)
   · builds the urgent-first preview rows (each row links to detail)
   · minimal "+ New Booking" modal wiring (open/close/demo submit)
   ============================================================ */
const RES = {
  pending:  { t: 'Pending review', c: 'b-amber' },
  approved: { t: 'Approved',       c: 'b-green' },
  released: { t: 'Released',       c: 'b-gray'  },
};
const PAY = {
  locked:      { t: 'Payment locked',              c: 'b-gray'  },
  await_gcash: { t: 'Awaiting payment · GCash',    c: 'b-amber' },
  await_cash:  { t: 'Awaiting payment · Cash',     c: 'b-amber' },
  auto_pass:   { t: 'Receipt passed auto-check',   c: 'b-navy'  },
  review:      { t: 'Receipt · manual review',     c: 'b-amber' },
  rejected:    { t: 'Receipt auto-rejected',       c: 'b-red'   },
  confirmed:   { t: 'Payment confirmed',           c: 'b-green' },
  paid_cash:   { t: 'Paid at cashier',             c: 'b-green' },
  overdue:     { t: 'Payment overdue',             c: 'b-red'   },
  refund_req:  { t: 'Refund · under verification', c: 'b-navy'  },
};
const BR = [
  { id: 'BRQ-2431', name: 'Juan Miguel Dela Cruz', type: 'Student · CIC', room: 'Alumni Grand Ballroom', venue: 'Bahay Alumni', dates: 'Jul 23 – 25, 2026', res: 'pending', pay: 'locked', act: 'Review the submitted ID', cls: 'warn', cat: 'id' },
  { id: 'BRQ-2430', name: 'Maria Santos', type: 'Faculty · CBA', room: 'Heritage Function Room', venue: 'Bahay Alumni', dates: 'Jul 20, 2026', res: 'approved', pay: 'await_gcash', act: 'Pay by Jul 19 · customer notified', cls: '', cat: '' },
  { id: 'BRQ-2429', name: 'Rafael Lim', type: 'Org · JPIA', room: 'CIC Audio-Visual Room', venue: 'USeP Venues', dates: 'Jul 18, 2026', res: 'approved', pay: 'auto_pass', act: 'Match ref 3042 137 089838 in GCash', cls: 'warn', cat: 'confirm' },
  { id: 'BRQ-2428', name: 'Ana Reyes', type: 'Student · CoE', room: 'Alumni Boardroom', venue: 'Bahay Alumni', dates: 'Jul 21, 2026', res: 'approved', pay: 'review', act: '3 flags need a human look', cls: 'warn', cat: 'review' },
  { id: 'BRQ-2427', name: 'Leo Garcia', type: 'Staff · OSAS', room: 'Obrero Function Hall', venue: 'USeP Venues', dates: 'Jul 24 – 27, 2026', res: 'approved', pay: 'rejected', act: 'Resubmit window ends Jul 16 · 2:10 PM', cls: 'warn', cat: '' },
  { id: 'BRQ-2426', name: 'Carmen Uy', type: 'Faculty · CAS', room: 'Admin Conference Hall', venue: 'USeP Venues', dates: 'Jul 22, 2026', res: 'approved', pay: 'await_cash', act: 'Pay at cashier by Jul 21', cls: '', cat: '' },
  { id: 'BRQ-2425', name: 'Paolo Mendoza', type: 'Org · Honor Society', room: 'USeP Gymnasium', venue: 'USeP Venues', dates: 'Aug 2, 2026', res: 'approved', pay: 'confirmed', act: 'Confirmed by M. Robles · Jul 13', cls: '', cat: '' },
  { id: 'BRQ-2424', name: 'Grace Tan', type: 'Student · CIC', room: 'Garden Pavilion', venue: 'Bahay Alumni', dates: 'Jul 17, 2026', res: 'approved', pay: 'overdue', act: 'Deadline passed Jul 16 · slot releasable', cls: 'late', cat: 'overdue' },
  { id: 'BRQ-2423', name: 'Diego Cruz', type: 'Alumni', room: 'Heritage Function Room', venue: 'Bahay Alumni', dates: 'Jul 12, 2026', res: 'approved', pay: 'refund_req', act: 'Both receipts submitted · verify', cls: 'warn', cat: '' },
];

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

/* the four needs-action counts = the queue's tab definitions */
const countOf = (k) => BR.filter((r) => r.cat === k).length;
document.getElementById('statId').textContent = countOf('id');
document.getElementById('statConfirm').textContent = countOf('confirm');
document.getElementById('statReview').textContent = countOf('review');
document.getElementById('statOverdue').textContent = countOf('overdue');

/* urgent-first preview: overdue > manual review > receipts to confirm >
   pending ID > rejected-resubmit > everything else; top 5 shown */
function rank(r) {
  if (r.cat === 'overdue') return 0;
  if (r.cat === 'review') return 1;
  if (r.cat === 'confirm') return 2;
  if (r.cat === 'id') return 3;
  if (r.pay === 'rejected') return 4;
  if (r.pay === 'refund_req') return 5;
  return 9;
}
document.getElementById('adRows').innerHTML = BR.slice()
  .sort((a, b) => rank(a) - rank(b))
  .slice(0, 5)
  .map((r) => `
    <a class="ad-row" href="booking-request.php?id=${r.id}">
      <span><div class="r-name">${esc(r.name)}</div><div class="r-sub">${r.id} · ${esc(r.type)}</div></span>
      <span class="r-roomcol"><div class="r-room">${esc(r.room)}</div><div class="r-sub">${esc(r.dates)}</div></span>
      <span>
        <span class="r-badges">
          <span class="br-badge ${RES[r.res].c}">${RES[r.res].t}</span>
          <span class="br-badge ${PAY[r.pay].c}">${PAY[r.pay].t}</span>
        </span>
        <div class="r-act ${r.cls}">${esc(r.act)}</div>
      </span>
      <span class="r-chev">&rsaquo;</span>
    </a>`).join('');

/* ---- "+ New Booking" modal (demo wiring so nothing throws) ---- */
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.getElementById('newBookingModal').addEventListener('click', function (e) {
  if (e.target === this) closeModal('newBookingModal');
});
function toggleWalkinFields() {
  const withAccount = document.getElementById('walkinType').value === 'acc';
  document.getElementById('accountSearchField').style.display = withAccount ? '' : 'none';
  document.getElementById('pureWalkinFields').style.display = withAccount ? 'none' : '';
}
/* [SIM] stubs — the real versions query the database (user accounts, rooms per
   venue) and must apply the booking rules: booked date = unavailable, 12-hour
   lead time, and a new walk-in starts as "Awaiting payment · Cash". */
function searchAccounts() {}
function fetchRooms() {}
function submitWalkin() {
  alert('Demo only — the walk-in form is not wired to the database yet.');
  closeModal('newBookingModal');
}
</script>
</body>
</html>
