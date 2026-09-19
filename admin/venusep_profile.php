<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* THE SIGNED-IN STAFF MEMBER'S OWN ACCOUNT. This page shipped as the stock
   template's placeholder — "Jane Doe", "jane@example.com", a designer's bio —
   so it never showed anyone their real details and the form saved nothing.

   The seeded admin has no `staff` row (only staff@gmail.com gets one), so the
   name falls back to the username until they save, at which point
   profile-save.php creates the row. Better than refusing to let an admin edit
   their own name because a row is missing. */
require_once __DIR__ . '/../includes/db.php';

$vpRow = [];
try {
    $vpStmt = venusep_db_or_fail()->prepare(
        'SELECT u.email, u.username, s.full_name, s.phone, s.position_role, s.employee_no, s.photo_path
           FROM users u LEFT JOIN staff s ON s.user_id = u.id
          WHERE u.id = :u'
    );
    $vpStmt->execute([':u' => (int) $_SESSION['user_id']]);
    $vpRow = $vpStmt->fetch() ?: [];
} catch (PDOException $e) {
    $vpRow = [];
}
$vpName  = (string) ($vpRow['full_name'] ?: ($vpRow['username'] ?? ''));
$vpParts = preg_split('/\s+/', trim($vpName), 2);
$vpFirst = $vpParts[0] ?? '';
$vpLast  = $vpParts[1] ?? '';
$vpEmail = (string) ($vpRow['email'] ?? '');
$vpPhone = (string) ($vpRow['phone'] ?? '');
$vpRole  = (string) ($vpRow['position_role'] ?? '');
$vpEmp   = (string) ($vpRow['employee_no'] ?? '');
$vpCsrf  = csrf_token();
/* DEMO MODE — the card below is the refund switch's twin (see
   admin/payment-settings.php): same card, same warning window, same password
   re-entry, same lockout. Everything shown here is re-checked by
   admin/demo-mode-switch.php; this page only reports the state. */
require_once __DIR__ . '/../includes/demo-mode.php';
$dmDetails = demo_setting_details();        // null = the database cannot be read
$dmDbOk    = $dmDetails !== null;
$dmOn      = $dmDbOk ? $dmDetails['enabled'] : demo_mode_on();
$dmIsAdmin = admin_is_admin();

/* A lock still running from an earlier wrong password — the countdown has to
   resume after a reload, or a reload would look like a way around it. */
$dmLockSeconds = 0;
if ($dmDbOk && $dmIsAdmin) {
    try {
        $dmStmt = venusep_db()->prepare(
            'SELECT CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END
               FROM users WHERE id = :id'
        );
        $dmStmt->execute([':id' => (int) $_SESSION['user_id']]);
        $dmLockSeconds = (int) $dmStmt->fetchColumn();
    } catch (PDOException $e) { $dmLockSeconds = 0; }
}
$dmJustSaved = isset($_GET['demo']) && $_GET['demo'] === 'saved';
function vp_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
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

/* ---- DEMO MODE card + confirmation window ------------------------------
   Deliberately the same shape as the refund switch on payment-settings.php:
   both are admin-only settings that change what the whole system does, both
   re-ask for the password, and both are recorded. A setting that dangerous
   should not have to be re-learned because it lives on a different page.
   The class names are dm- rather than rs- only because this page has its own
   stylesheet; the values are the refund card's. */
.dm-card { grid-column: 1 / -1;   /* a third grid item would otherwise drop into the 270px sidebar column */
           background:#fff; border:1px solid var(--venusep-border); border-radius:14px; box-shadow:0 18px 34px rgba(31,30,30,.06); margin-top:1.2rem; max-width:780px; padding:1.1rem 1.15rem 1rem; }
.dm-top { align-items:flex-start; display:flex; flex-wrap:wrap; gap:.9rem; justify-content:space-between; }
.dm-title { align-items:center; display:flex; flex-wrap:wrap; gap:.55rem; }
.dm-title h2 { font-size:1rem; font-weight:680; letter-spacing:-.01em; margin:0; }
.dm-pill { border-radius:999px; font-size:.68rem; font-weight:700; letter-spacing:.04em; padding:.16rem .55rem; }
.dm-pill-on { background:#fdf3e6; color:#8a5a12; }     /* ON is the DANGEROUS state here — amber, not green */
.dm-pill-off { background:#e7f5ee; color:#1c7a4f; }
.dm-state { color:#4a463f; font-size:.86rem; line-height:1.55; margin:.35rem 0 0; max-width:58ch; }
.dm-error { color:#b23a3a; }
.dm-lock { align-items:center; color:#6b675f; display:inline-flex; font-size:.78rem; font-weight:600; gap:.4rem; }
.dm-btn { background:#fff; border:1px solid #d8d4cc; border-radius:8px; color:#1f1e1e; cursor:pointer; font-size:.82rem; font-weight:650; padding:.5rem .9rem; }
.dm-btn:hover { border-color:#1f1e1e; }
.dm-btn-danger { background:#8a5a12; border-color:#8a5a12; color:#fff; }
.dm-btn-danger:hover { background:#6f4a11; border-color:#6f4a11; }
.dm-btn-primary { background:#1c7a4f; border-color:#1c7a4f; color:#fff; }
.dm-btn-primary:hover { background:#166340; border-color:#166340; }
.dm-btn[disabled] { cursor:not-allowed; opacity:.5; }
.dm-rules { color:#6b675f; font-size:.76rem; line-height:1.55; margin:.8rem 0 0; padding-left:1.05rem; }
.dm-meta { border-top:1px solid #f0efec; color:#a5a19a; font-size:.74rem; margin-top:.8rem; padding-top:.6rem; }
.dm-meta strong { color:#6b675f; font-weight:600; }
.dm-flash { align-items:center; color:#1c7a4f; display:flex; font-size:.8rem; font-weight:600; gap:.4rem; margin-bottom:.6rem; }

.dm-backdrop { align-items:center; background:rgba(15,12,10,.5); display:flex; inset:0; justify-content:center; padding:16px; position:fixed; z-index:2000; }
.dm-backdrop[hidden], .dm-msg[hidden] { display:none; }   /* display:flex would otherwise beat the hidden attribute */
.dm-modal { background:#fff; border-radius:16px; box-shadow:0 24px 60px rgba(0,0,0,.3); max-height:calc(100vh - 32px); max-width:480px; overflow-y:auto; width:100%; }
.dm-modal-head { align-items:flex-start; border-bottom:1px solid #f0efec; display:flex; gap:.7rem; padding:1.1rem 1.2rem .9rem; }
.dm-modal-head i { color:#c2540a; flex:none; font-size:1.3rem; line-height:1.2; }
.dm-modal-head h3 { font-size:1.02rem; font-weight:700; margin:0; }
.dm-modal-head p { color:#6b675f; font-size:.8rem; margin:.2rem 0 0; }
.dm-modal-body { padding:.9rem 1.2rem 0; }
.dm-list { font-size:.84rem; line-height:1.55; margin:0 0 1rem; padding-left:1.1rem; }
.dm-list li { margin-bottom:.45rem; }
.dm-pw-label { display:block; font-size:.78rem; font-weight:650; margin-bottom:.1rem; }
.dm-input { border:1px solid #d8d4cc; border-radius:8px; font-size:.86rem; padding:.5rem .65rem; width:100%; }
.dm-msg { border-radius:8px; font-size:.78rem; line-height:1.45; margin-top:.55rem; padding:.5rem .65rem; }
.dm-msg-bad { background:#fcecec; color:#b23a3a; }
.dm-msg-lock { background:#fdf3e6; color:#8a5a12; }
.dm-modal-foot { border-top:1px solid #f0efec; display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; padding:.8rem 1.2rem; }
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
            <h2><?php echo vp_e($vpName ?: "Staff"); ?></h2>
            <p>Product Designer</p>
          </aside>
          <article class="settings-panel">
            <div class="settings-panel-header">
            <h2>Settings</h2>
            </div>
          <form class="settings-form" id="vpForm" novalidate>
            <div class="form-field">
              <label class="form-label" for="profile-first">First name</label>
              <input class="form-control" id="profile-first" type="text" value="<?php echo vp_e($vpFirst); ?>"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-last">Last name</label>
              <input class="form-control" id="profile-last" type="text" value="<?php echo vp_e($vpLast); ?>"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-email">Email</label>
              <input class="form-control" id="profile-email" type="email" value="<?php echo vp_e($vpEmail); ?>"/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-number">Number</label>
              <input class="form-control" id="profile-number" type="text" value="<?php echo vp_e($vpPhone); ?>" placeholder="09XX XXX XXXX"/>
            </div>
            <!-- Employee number and role are set by an ADMIN in Staff Management,
                 not by the person themselves: they are what the organisation says
                 about you, not what you say about you. Shown read-only. -->
            <div class="form-field">
              <label class="form-label" for="profile-role">Role</label>
              <input class="form-control" id="profile-role" type="text" value="<?php echo vp_e($vpRole ?: '—'); ?>" readonly/>
            </div>
            <div class="form-field">
              <label class="form-label" for="profile-emp">Employee no.</label>
              <input class="form-control" id="profile-emp" type="text" value="<?php echo vp_e($vpEmp ?: '—'); ?>" readonly/>
            </div>
            <div class="form-field form-field-full">
              <div id="vpMessage" role="status" aria-live="polite" style="font-size:.85rem;min-height:1.2em"></div>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary-dark" type="submit">Save changes</button>
              <button class="btn btn-outline-dark" type="reset">Cancel</button>
            </div>
          </form>
          <script>
            /* Saves to the same profile-save.php the customer portal uses — it
               works out WHICH profile row from the session, so neither side can
               edit the other's. The bio field was dropped: there is no column
               for it and nothing displays one. */
            (function () {
              const form = document.getElementById('vpForm');
              const out = document.getElementById('vpMessage');
              if (!form) return;
              form.addEventListener('submit', function (e) {
                e.preventDefault();
                const name = (document.getElementById('profile-first').value.trim() + ' ' +
                              document.getElementById('profile-last').value.trim()).trim();
                if (!name) { out.style.color = '#b23a3a'; out.textContent = 'Enter your name.'; return; }
                const body = new URLSearchParams({
                  csrf: <?php echo json_encode($vpCsrf); ?>,
                  action: 'details',
                  full_name: name,
                  email: document.getElementById('profile-email').value.trim(),
                  contact_number: document.getElementById('profile-number').value.trim()
                });
                out.style.color = '#6b675f'; out.textContent = 'Saving…';
                fetch('../profile-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
                  .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
                  .then(function (res) {
                    out.style.color = res.ok ? '#1c7a4f' : '#b23a3a';
                    out.textContent = res.message || (res.ok ? 'Saved.' : 'Nothing was saved.');
                    if (res.ok) { const h = document.querySelector('.settings-identity h2, aside h2'); if (h) h.textContent = name; }
                  })
                  .catch(function () { out.style.color = '#b23a3a'; out.textContent = 'Could not reach the server.'; });
              });
            })();
          </script>
        </article>

<?php if ($dmIsAdmin): ?>
        <!-- DEMO MODE. Admin only, because turning it ON in production is the
             most damaging single action here: customers would book, receive a
             reference, and have nothing recorded. Built as the refund switch's
             twin so the two most consequential settings in the system are
             operated the same way. -->
        <section class="dm-card" id="dmCard" aria-labelledby="dmTitle">
          <?php if ($dmJustSaved && $dmDbOk): ?>
            <div class="dm-flash" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Saved. Demo mode is now <?php echo $dmOn ? 'ON' : 'OFF'; ?>.</div>
          <?php endif; ?>
          <div class="dm-top">
            <div>
              <div class="dm-title">
                <h2 id="dmTitle">Demo Mode</h2>
                <?php if ($dmDbOk): ?>
                  <span class="dm-pill <?php echo $dmOn ? 'dm-pill-on' : 'dm-pill-off'; ?>"><?php echo $dmOn ? 'ON' : 'OFF'; ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$dmDbOk): ?>
                <p class="dm-state dm-error">The database cannot be reached, so this setting cannot be read or changed right now. Until it is back, the system behaves normally and every booking is recorded.</p>
              <?php elseif ($dmOn): ?>
                <p class="dm-state"><strong>Nothing is being saved.</strong> Bookings, payments and refunds go through the screens normally but never reach the database, so the same showcase can be run any number of times without changing anything or needing a reset. <strong>A real customer booking right now would be given a reference for a booking that does not exist.</strong></p>
              <?php else: ?>
                <p class="dm-state"><strong>The system is live.</strong> Every booking, payment and refund is recorded for real. Turn this on only to showcase the system.</p>
              <?php endif; ?>
            </div>
            <div>
              <?php if (!$dmDbOk): ?>
                <span class="dm-lock"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Unavailable</span>
              <?php else: ?>
                <button type="button" class="dm-btn <?php echo $dmOn ? 'dm-btn-primary' : 'dm-btn-danger'; ?>" id="dmOpen">
                  <?php echo $dmOn ? 'Turn demo mode OFF' : 'Turn demo mode ON'; ?>
                </button>
              <?php endif; ?>
            </div>
          </div>
          <ul class="dm-rules">
            <li>Setting the system up still saves for real: venues, rooms, rates, staff, accounts and settings. Only <strong>bookings, payments and refunds</strong> are held back.</li>
            <li>Registration also saves for real &mdash; an account that vanishes is not an account.</li>
            <li>Every page shows a warning banner while it is on, and turning it off discards whatever the demo pretended to book.</li>
            <li>Changing it needs your password, and every change is recorded.</li>
          </ul>
          <?php if ($dmDbOk): ?>
            <div class="dm-meta">
              <?php if ($dmDetails['updated_by']): ?>
                Last changed by <strong><?php echo vp_e($dmDetails['updated_by']); ?></strong> on <?php echo vp_e(date('M j, Y \a\t g:i A', strtotime($dmDetails['updated_at']))); ?>
              <?php else: ?>
                Not changed since the system was set up (default: OFF).
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>

<?php if ($dmDbOk): ?>
        <!-- The warning + password window. Same two steps as the refund switch:
             read what changes, then prove who you are. -->
        <div class="dm-backdrop" id="dmModal" role="dialog" aria-modal="true" aria-labelledby="dmModalTitle" hidden>
          <div class="dm-modal">
            <div class="dm-modal-head">
              <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              <div>
                <h3 id="dmModalTitle"></h3>
                <p>Read this before you confirm.</p>
              </div>
            </div>
            <form id="dmForm" novalidate>
              <div class="dm-modal-body">
                <ul class="dm-list" id="dmModalList"></ul>
                <label class="dm-pw-label" for="dmPassword">Enter your admin password to confirm</label>
                <input class="dm-input" type="password" id="dmPassword" autocomplete="current-password" />
                <div class="dm-msg" id="dmMsg" role="alert" hidden></div>
              </div>
              <div class="dm-modal-foot">
                <button type="button" class="dm-btn" id="dmCancel">Cancel</button>
                <button type="submit" class="dm-btn dm-btn-primary" id="dmConfirm"></button>
              </div>
            </form>
          </div>
        </div>

        <script>
          /* Opens the right warning, posts to admin/demo-mode-switch.php, runs
             the lockout countdown. The server decides everything; this only
             reports what it says. Lifted from the refund switch so the two
             behave identically, down to the wording of the errors. */
          (function () {
            const ON = <?php echo $dmOn ? 'true' : 'false'; ?>;
            const CSRF = <?php echo json_encode($vpCsrf); ?>;
            let lockUntil = Date.now() + <?php echo (int) $dmLockSeconds; ?> * 1000;
            let timer = null, busy = false;

            const modal = document.getElementById('dmModal');
            const title = document.getElementById('dmModalTitle');
            const list = document.getElementById('dmModalList');
            const form = document.getElementById('dmForm');
            const pw = document.getElementById('dmPassword');
            const msg = document.getElementById('dmMsg');
            const confirmBtn = document.getElementById('dmConfirm');
            const openBtn = document.getElementById('dmOpen');

            const plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
            function show(kind, text) { msg.className = 'dm-msg ' + (kind === 'lock' ? 'dm-msg-lock' : 'dm-msg-bad'); msg.textContent = text; msg.hidden = false; }
            function hideMsg() { msg.hidden = true; }

            function tick() {
              const left = Math.ceil((lockUntil - Date.now()) / 1000);
              if (left > 0) {
                pw.disabled = true; confirmBtn.disabled = true;
                show('lock', 'Too many wrong passwords. Try again in ' + (left >= 60 ? Math.floor(left / 60) + 'm ' + (left % 60) + 's' : left + 's') + '.');
                return;
              }
              clearInterval(timer); timer = null;
              pw.disabled = false; confirmBtn.disabled = false; hideMsg();
              if (!modal.hidden) pw.focus();
            }
            function startCountdown(seconds) {
              lockUntil = Date.now() + seconds * 1000;
              if (!timer) timer = setInterval(tick, 250);
              tick();
            }

            function openWindow() {
              const turningOn = !ON;
              title.textContent = turningOn ? 'Turn demo mode ON?' : 'Turn demo mode OFF?';
              confirmBtn.textContent = turningOn ? 'Turn demo mode on' : 'Turn demo mode off';
              confirmBtn.className = 'dm-btn ' + (turningOn ? 'dm-btn-danger' : 'dm-btn-primary');
              const items = turningOn ? [
                '<strong>No booking, payment or refund will be saved</strong> while this is on. The screens behave normally and nothing reaches the database.',
                'A real customer booking during a demo would be given a reference for <strong>a booking that does not exist</strong>. Do not leave this on.',
                'Setting the system up still saves for real: venues, rooms, rates, staff, accounts and settings. Registration does too.',
                'A booking made during a demo <strong>never reaches the staff queue</strong>, because it was never written. Use a seeded booking to show that step.',
                'Every page will show a warning banner while it is on.'
              ] : [
                'Bookings, payments and refunds are <strong>recorded for real</strong> again.',
                'Whatever this browser pretended to book during the demo is <strong>discarded</strong>.',
                'Nothing the demo did was ever written, so there is nothing to clean up.',
                'The warning banner disappears from every page.'
              ];
              list.innerHTML = items.map(function (t) { return '<li>' + t + '</li>'; }).join('');
              pw.value = ''; hideMsg();
              modal.hidden = false;
              if (lockUntil > Date.now()) startCountdown(Math.ceil((lockUntil - Date.now()) / 1000));
              else pw.focus();
            }
            function closeWindow() {
              if (busy) return;
              modal.hidden = true; pw.value = ''; hideMsg();
              if (openBtn) openBtn.focus();
            }

            if (openBtn) openBtn.addEventListener('click', openWindow);
            document.getElementById('dmCancel').addEventListener('click', closeWindow);
            modal.addEventListener('click', function (e) { if (e.target === modal) closeWindow(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeWindow(); });

            form.addEventListener('submit', function (e) {
              e.preventDefault();
              if (busy || lockUntil > Date.now()) return;
              if (!pw.value) { show('bad', 'Enter your admin password to confirm.'); pw.focus(); return; }

              const body = new URLSearchParams({ csrf: CSRF, enable: ON ? '0' : '1', password: pw.value });
              busy = true; confirmBtn.disabled = true; pw.disabled = true; hideMsg();
              fetch('demo-mode-switch.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server.' }; }); })
                .then(function (res) {
                  busy = false;
                  if (res.ok) { location.replace('venusep_profile.php?demo=saved'); return; }
                  pw.value = '';
                  if (res.error === 'locked') { startCountdown(res.seconds || 10); return; }
                  confirmBtn.disabled = false; pw.disabled = false;
                  if (res.error === 'wrong_password') {
                    show('bad', 'Wrong password. ' + plural(res.attemptsLeft, 'attempt', 'attempts') + ' left before a temporary lock.');
                  } else if (res.error === 'not_admin') {
                    show('bad', res.message); setTimeout(function () { location.href = 'admin-login.php'; }, 1500);
                  } else {
                    show('bad', res.message || 'The setting was not changed.');
                  }
                  pw.focus();
                })
                .catch(function () {
                  busy = false; confirmBtn.disabled = false; pw.disabled = false;
                  show('bad', 'Could not reach the server. The setting was not changed.');
                });
            });
          })();
        </script>
<?php endif; ?>
<?php endif; ?>
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
