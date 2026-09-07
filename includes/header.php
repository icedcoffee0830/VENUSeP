<?php
/* =====================================================================
   SHARED HEADER BAR — the ONE top bar every admin page uses.
   Usage (from a page inside admin/):
       <?php include __DIR__ . '/../includes/header.php'; ?>
   Fixed dark bar, sits right of the 235px sidebar. The buttons are
   decorative for now (no JS yet). hd-* class names are unique on
   purpose so no page stylesheet (incl. AdminLTE's CDN css) restyles it.

   $portal ('admin' default | 'customer') only swaps the user chip label.
   ===================================================================== */
$portal   = isset($portal) ? $portal : 'admin';
$hdName   = $portal === 'customer' ? 'Juan Miguel Dela Cruz' : 'Administrator';
$hdAvatar = $portal === 'customer' ? 'JM' : 'AD';
?>
<style>
  /* header styles live HERE so every page gets them with the include */
  nav.app-header{position:fixed;top:0;right:0;left:235px;z-index:1030;height:58px;min-height:58px;background:#1f1e1e;border-bottom:1px solid #111;color:#fff;display:flex;align-items:center;margin:0;padding:0}
  nav.app-header .hd-wrap{width:100%;padding:0 1.35rem;display:flex;align-items:center;justify-content:space-between}
  nav.app-header .hd-nav{display:flex;align-items:center;gap:.45rem;margin:0;padding:0;list-style:none}
  nav.app-header .hd-nav li{margin:0;padding:0;list-style:none}
  nav.app-header .hd-link{color:#fff;font-size:.95rem;line-height:1;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;padding:.45rem}
  nav.app-header .hd-toggle{width:38px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;background:#34312f;border:1px solid #46413d;cursor:default}
  nav.app-header .hd-user{display:flex;align-items:center;gap:.45rem;margin-left:.7rem;color:#fff;font-weight:700;font-size:.82rem}
  nav.app-header .hd-avatar{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#6f7983;border:1px solid #9aa2aa;color:#fff;font-size:.72rem;font-weight:700}
  @media (max-width:767.98px){nav.app-header{left:0;height:56px;min-height:56px}nav.app-header .hd-username{display:none}}
</style>
<nav class="app-header">
  <div class="hd-wrap">
    <ul class="hd-nav">
      <li><span class="hd-link hd-toggle" aria-hidden="true"><i class="bi bi-list"></i></span></li>
    </ul>
    <ul class="hd-nav">
      <li><a class="hd-link" aria-label="Search" href="#"><i class="bi bi-search"></i></a></li>
      <li><a class="hd-link" aria-label="Fullscreen" href="#"><i class="bi bi-arrows-fullscreen"></i></a></li>
      <li><a class="hd-link" aria-label="Theme" href="#"><i class="bi bi-sun-fill"></i></a></li>
      <li class="hd-user"><span class="hd-avatar" aria-hidden="true"><?php echo $hdAvatar; ?></span><span class="hd-username"><?php echo $hdName; ?></span></li>
    </ul>
  </div>
</nav>
