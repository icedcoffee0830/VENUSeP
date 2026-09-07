<?php ?>
<!DOCTYPE html>

<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta content="text/html; charset=utf-8" http-equiv="Content-Type"/>
<title>Venusep | Profile Settings</title>
  <meta content="width=device-width, initial-scale=1.0, user-scalable=yes" name="viewport"/>
  <meta content="light" name="color-scheme"/>
  <meta content="#007bff" media="(prefers-color-scheme: light)" name="theme-color"/>
  <meta content="#1a1a1a" media="(prefers-color-scheme: dark)" name="theme-color"/>
  <meta content="Venusep | Profile Settings" name="title"/>
  <meta content="ColorlibHQ" name="author"/>
  <meta content="AdminLTE is a Free Bootstrap 5 Admin Dashboard, 30 example pages using Vanilla JS. Fully accessible with WCAG 2.1 AA compliance." name="description"/>
  <meta content="bootstrap 5, bootstrap, bootstrap 5 admin dashboard, bootstrap 5 dashboard, bootstrap 5 charts, bootstrap 5 calendar, bootstrap 5 datepicker, bootstrap 5 tables, bootstrap 5 datatable, vanilla js datatable, colorlibhq, colorlibhq dashboard, colorlibhq admin dashboard, accessible admin panel, WCAG compliant" name="keywords"/>
  <meta content="light" name="supported-color-schemes"/>
  <link crossorigin="anonymous" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/index.css" media="print" onload="this.media = 'all'" rel="stylesheet"/>
  <link crossorigin="anonymous" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" rel="stylesheet"/>
  <link crossorigin="anonymous" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet"/>
<style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }

      * { box-sizing: border-box; }

      html, body { margin: 0; min-height: 100%; }

      body {
        color: var(--venusep-text);
        background: var(--venusep-cream);
        font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: 0.88rem;
      }

      .app-wrapper { --lte-sidebar-width: var(--venusep-sidebar-width); min-height: 100vh; }
      .app-wrapper, .app-main { background: var(--venusep-cream); }

      .app-sidebar {
        --bs-body-bg: var(--venusep-black);
        --bs-secondary-bg: var(--venusep-black);
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 1035;
        width: var(--venusep-sidebar-width) !important;
        min-width: var(--venusep-sidebar-width);
        background: var(--venusep-black) !important;
        border-right: 1px solid #2d2b29;
        box-shadow: none !important;
      }

      .app-header {
        position: fixed;
        top: 0;
        right: 0;
        left: var(--venusep-sidebar-width);
        z-index: 1030;
        min-height: var(--venusep-header-height);
        height: var(--venusep-header-height);
        background: var(--venusep-black);
        border-bottom: 1px solid #111;
        color: #fff;
        display: flex;
        align-items: center;
      }

      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
      }

      .container-fluid { width: 100%; padding-inline: 1.35rem; }
      .navbar-nav { display: flex; align-items: center; gap: 0.45rem; margin: 0; padding: 0; list-style: none; }
      .navbar .container-fluid { display: flex; align-items: center; justify-content: space-between; }
      .ms-auto { margin-left: auto !important; }
      .align-items-center { align-items: center !important; }
      .d-flex { display: flex !important; }

      .app-header .nav-link {
        color: #fff;
        font-size: 0.95rem;
        line-height: 1;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.45rem;
      }

      .sidebar-toggle {
        width: 38px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #34312f;
        border: 1px solid #46413d;
      }

      .user-menu { gap: 0.45rem; margin-left: 0.7rem; color: #fff; font-weight: 700; font-size: 0.82rem; }
      .user-avatar {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #6f7983;
        border: 1px solid #9aa2aa;
        color: #d8dde2;
        font-size: 0.32rem;
      }

      .sidebar-brand {
        height: var(--venusep-header-height);
        display: flex;
        align-items: center;
        border-bottom: 1px solid #2d2b29;
      }

      .sidebar-brand .brand-link {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.8rem;
        padding-inline: 1.3rem;
        color: #f8f9fa;
        text-decoration: none;
      }

      .brand-mark {
        width: 31px;
        height: 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 3px;
        background: #1a1919;
        color: #fff;
        font-size: 1.35rem;
        flex: 0 0 auto;
      }

      .sidebar-brand .brand-text { font-size: 1.08rem; font-weight: 600 !important; line-height: 1; }
      .sidebar-wrapper { padding-top: 0.7rem; }
      .sidebar-menu { margin: 0; padding: 0; list-style: none; }
      .sidebar-menu .nav-item { margin: 0; padding: 0; list-style: none; }

      .sidebar-menu .nav-link {
        min-height: 40px;
        margin: 0.22rem 0.75rem;
        padding: 0.45rem 0.7rem;
        border-radius: 10px;
        color: #e5e5e5;
        font-size: 0.82rem;
        font-weight: 500;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        white-space: nowrap;
      }

      .sidebar-menu .nav-link:hover, .sidebar-menu .nav-link.active { background: #343333; color: #fff; }
      .sidebar-menu .nav-link.active { border: 1px solid #e9e3dc; }
      .sidebar-menu .nav-icon { width: 18px; flex: 0 0 18px; color: inherit; font-size: 0.9rem; text-align: center; }
      .sidebar-menu .nav-link p { margin: 0; flex: 1; display: flex; align-items: center; min-width: 0; }
      .sidebar-menu .nav-arrow { margin-left: auto; font-size: 0.8rem; }
      .booking-status-dot { width: 0.38rem; height: 0.38rem; border-radius: 50%; background: #111; margin-left: auto; }

      .profile-content { min-height: calc(100vh - var(--venusep-header-height)); padding: 1.2rem 1.55rem 3rem; background: var(--venusep-cream); }
      .page-heading { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 2.2rem; }
      .page-heading h1 { margin: 0; font-size: 1.35rem; font-weight: 800; }
      .breadcrumb { display: flex; gap: 0.4rem; margin: 0; padding: 0; list-style: none; font-size: 0.82rem; }
      .breadcrumb-item + .breadcrumb-item::before { content: "/"; margin-right: 0.4rem; color: #777; }
      .breadcrumb a { color: #005dff; text-decoration: none; }

      .profile-layout { display: grid; grid-template-columns: minmax(210px, 270px) 1fr; gap: 1.35rem; align-items: start; }
      .profile-card, .settings-panel { background: #fff; border: 1px solid var(--venusep-border); border-radius: 10px; box-shadow: 0 18px 34px rgba(31, 30, 30, 0.06); }
      .profile-card { min-height: 174px; display: flex; align-items: center; justify-content: center; flex-direction: column; padding: 1.6rem 1rem; text-align: center; }
      .profile-avatar { width: 70px; height: 70px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; border-radius: 50%; background: #1d1b1b; color: #fff5db; font-size: 1.45rem; font-weight: 800; }
      .profile-card h2 { margin: 0; font-size: 1rem; font-weight: 800; }
      .profile-card p { margin: 0.25rem 0 0; color: #67605a; font-size: 0.78rem; }
      .settings-panel { overflow: hidden; }
      .settings-panel-header { padding: 0.9rem 1rem; background: #fff; border-bottom: 1px solid var(--venusep-border); }
      .settings-panel-header h2 { margin: 0; font-size: 0.95rem; font-weight: 800; }
      .settings-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem 1rem; padding: 1rem; }
      .form-field-full, .form-actions { grid-column: 1 / -1; }
      .form-label { display: inline-block; margin-bottom: 0.35rem; color: #111; font-weight: 700; font-size: 0.78rem; }
      .form-control { width: 100%; min-height: 34px; padding: 0.42rem 0.55rem; border: 1px solid var(--venusep-border); border-radius: 6px; font: inherit; background: #fff; color: #111; }
      textarea.form-control { min-height: 78px; resize: vertical; }
      .form-control:focus { outline: none; border-color: #1d1b1b; box-shadow: 0 0 0 0.15rem rgba(29, 27, 27, 0.14); }
      .form-actions { display: flex; gap: 0.55rem; margin-top: 0.1rem; }
      .btn-primary-dark, .btn-outline-dark { min-height: 34px; padding: 0.35rem 0.75rem; border-radius: 6px; font-weight: 800; font-size: 0.78rem; }
      .btn-primary-dark { background: #1d1b1b; border: 1px solid #000; color: #fff; }
      .btn-primary-dark:hover, .btn-primary-dark:focus { background: #000; color: #fff; }
      .btn-outline-dark { background: var(--venusep-cream); border: 1px solid #d8c7ae; color: #111; }
      .btn-outline-dark:hover, .btn-outline-dark:focus { background: #fff; border-color: #111; color: #111; }



      /* FIX: make sidebar labels fit and align like the reference */
      .sidebar-menu .nav-link {
        position: relative;
        width: calc(100% - 1.5rem);
        overflow: hidden;
      }

      .sidebar-menu .nav-link p {
        overflow: hidden;
        text-overflow: clip;
        white-space: nowrap;
        padding-right: 1rem;
      }

      .sidebar-menu .nav-arrow {
        position: absolute;
        right: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 0;
      }

      .booking-status-dot {
        position: absolute;
        right: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 0;
      }

      .profile-content .container-fluid {
        max-width: none !important;
        padding-left: 0 !important;  /* inset owned by .profile-content below (uniform 30px sides) */
        padding-right: 0 !important;
      }

      .profile-layout {
        grid-template-columns: 300px minmax(0, 1fr);
      }



      /* STATIC PAGE FIX: no AdminLTE sidebar expand/collapse behavior */
      body { overflow-x: hidden; }

      .app-wrapper {
        display: block;
        width: 100%;
        min-height: 100vh;
      }

      .app-main {
        margin-left: var(--venusep-sidebar-width) !important;
        padding-top: var(--venusep-header-height) !important;
        width: calc(100vw - var(--venusep-sidebar-width)) !important;
        min-height: 100vh;
      }

      .profile-content {
        padding: 26px 30px 40px !important; /* uniform content inset — 26px top, 30px sides, same as every admin page */
      }

      .profile-content > .container-fluid {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      .page-heading {
        margin-bottom: 24px !important;
      }

      .profile-layout {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        display: grid !important;
        grid-template-columns: 300px minmax(0, 1fr) !important;
        gap: 20px !important;
        align-items: start !important;
      }

      .profile-card,
      .settings-panel {
        width: 100%;
      }

      .sidebar-toggle {
        cursor: default;
        pointer-events: none;
      }

            @media (max-width: 1100px) { .profile-layout { grid-template-columns: 1fr; } }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .app-sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .profile-content { padding: 1.1rem 1rem 3rem; }
        .page-heading { align-items: flex-start; flex-direction: column; margin-bottom: 1.5rem; }
        .settings-form { grid-template-columns: 1fr; gap: 0.85rem; }
        .form-actions { flex-direction: column; }
        .user-name { display: none; }
      }
    
.sidebar{position:fixed;left:0;top:0;width:235px;height:100vh;background:#1f1e1e;color:#fff;z-index:1035}
.logo{height:60px;display:flex;align-items:center;justify-content:center;padding:14px 20px;border-bottom:1px solid #333}
.logo img{display:block;max-width:150px;max-height:36px;width:auto;height:auto}
.menu{list-style:none;padding:15px 10px}.menu li{margin-bottom:8px}
.menu a{display:flex;align-items:center;gap:15px;height:42px;padding:0 15px;color:#e5e5e5;text-decoration:none;border-radius:10px;transition:.2s}
.menu a:hover{background:#343333}.menu a.active{background:#343333;border:1px solid #ffffff40}
.menu i{width:20px;text-align:center;font-size:18px}.menu span{font-size:14px}
</style>
</head>
<body>
  <div class="app-wrapper">
        <!-- shared header: edit ../includes/header.php and every page updates -->
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <!-- shared sidebar: edit ../includes/sidebar.php and every page updates -->
    <?php $active = 'Settings'; include __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-content profile-content">
      <div class="container-fluid">
        <div class="page-heading">
          <h1>Settings</h1>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
              <li class="breadcrumb-item">Dashboard</li>
              <li aria-current="page" class="breadcrumb-item active">Settings</li>
            </ol>
          </nav>
        </div>
        <section class="profile-layout">
          <aside class="profile-card">
            <div aria-hidden="true" class="profile-avatar">JD</div>
            <h2>Jane Doe</h2>
            <p>Product Designer</p>
          </aside>
          <article class="settings-panel">
            <div class="settings-panel-header">
            <h2>Settings</h2>
            </div>
          <form class="settings-form">
            <div class="form-field">
              <label class="form-label" for="profile-first">First name</label>
              <input class="form-control" id="profile-first" type="text" value="Jane"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-last">Last name</label>
              <input class="form-control" id="profile-last" type="text" value="Doe"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-email">Email</label>
              <input class="form-control" id="profile-email" type="email" value="jane@example.com"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-number">Number</label>
              <input class="form-control" id="profile-number" type="text" value="123-456-7890"/>
            </div>
            <div class="form-field form-field-full">
              <label class="form-label" for="profile-bio">Bio</label>
              <textarea class="form-control" id="profile-bio" rows="4">Designer with a soft spot for design tokens and accessibility.</textarea>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary-dark" type="submit">Save changes</button>
              <button class="btn btn-outline-dark" type="reset">Cancel</button>
            </div>
          </form>
        </article>
        </section>
      </div>
    </div>
  </main>
  </div>
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js"></script>
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script crossorigin="anonymous" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script>
      const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
      const Default = {
        scrollbarTheme: 'os-theme-light',
        scrollbarAutoHide: 'leave',
        scrollbarClickScroll: true,
      };
      document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
        const isMobile = window.innerWidth <= 992;

        if (
          sidebarWrapper &&
          OverlayScrollbarsGlobal?.OverlayScrollbars !== undefined &&
          !isMobile
        ) {
          OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
            scrollbars: {
              theme: Default.scrollbarTheme,
              autoHide: Default.scrollbarAutoHide,
              clickScroll: true,
            },
          });
        }
      });
    </script>
</body>
</html>
