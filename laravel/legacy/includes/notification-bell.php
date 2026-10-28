<?php
/* =====================================================================
   THE NOTIFICATION BELL (2026-10-28) — in both shared top bars, so it is
   on every signed-in page: includes/header.php (admin/staff pages and the
   customer account pages) and includes/customer-nav.php (landing, venue
   list, booking pages, FAQ; staff in counter mode too).

   Usage:  <?php $nbLook = 'admin' | 'nav'; include __DIR__ . '/notification-bell.php'; ?>

   Unread count on the bell; the panel lists the newest 30, unread ones bold
   with a dot. A notification stays unread until it is CLICKED — that marks
   it read and opens what it is about. "Mark all as read" clears the lot.
   Checks notifications.php about once a minute, paused while the tab is in
   the background. Staff and admins also see live "about to expire"
   reminders at the top; those have no read state and vanish once handled.
   Every page that includes this is one folder below the app root, so the
   endpoint and the links are reached through '../'.
   ===================================================================== */
if (!session()->has('user_id') || !session()->has('account_type')) {
    return;                                 /* signed out: no bell */
}
$nbLook = isset($nbLook) ? $nbLook : 'admin';
$nbFirst = empty($GLOBALS['nbRendered']);
$GLOBALS['nbRendered'] = true;
?>
<span class="nb-wrap nb-<?php echo $nbLook === 'nav' ? 'nav' : 'admin'; ?>">
  <button type="button" class="nb-bell" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" onclick="nbToggle(event)">
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <span class="nb-count" hidden></span>
  </button>
  <div class="nb-panel" role="dialog" aria-label="Notifications" hidden></div>
</span>
<?php if ($nbFirst): ?>
<style>
  .nb-wrap{position:relative;display:inline-flex}
  .nb-bell{position:relative;width:38px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;cursor:pointer;color:#fff;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);transition:background 180ms ease;padding:0}
  .nb-bell:hover,.nb-bell[aria-expanded="true"]{background:rgba(255,255,255,.2)}
  .nb-nav .nb-bell{width:36px;height:34px;border-radius:999px}
  .nb-count{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#ffd166;color:#3a0c14;font:700 11px/18px Inter,system-ui,sans-serif;text-align:center;box-shadow:0 0 0 2px #3a0c14}
  .nb-panel{position:absolute;top:calc(100% + 10px);right:0;z-index:1100;width:min(370px,calc(100vw - 16px));max-height:min(480px,calc(100vh - 90px));overflow:auto;background:#fff;color:#1c1b19;border-radius:14px;box-shadow:0 18px 50px rgba(10,4,5,.35);border:1px solid rgba(0,0,0,.08);font-family:Inter,system-ui,sans-serif;text-align:left}
  .nb-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #f0efec;position:sticky;top:0;background:#fff}
  .nb-head b{font-size:14px}
  .nb-all{background:none;border:none;color:#a11626;font:600 12px Inter,system-ui,sans-serif;cursor:pointer;padding:0}
  .nb-item{display:flex;gap:10px;align-items:flex-start;width:100%;padding:11px 14px;border:none;border-bottom:1px solid #f4f2ef;background:#fff;text-align:left;cursor:pointer;font:inherit;color:inherit}
  .nb-item:hover{background:#faf8f6}
  .nb-item .nb-dot{flex:none;width:8px;height:8px;border-radius:999px;margin-top:6px;background:transparent}
  .nb-item.is-unread{background:#fdf6f6}
  .nb-item.is-unread .nb-dot{background:#a11626}
  .nb-item.is-unread .nb-text{font-weight:650;color:#1c1b19}
  .nb-text{display:block;font-size:13px;line-height:1.45;color:#4a463f}
  .nb-ago{display:block;font-size:11.5px;color:#8a857d;margin-top:2px}
  .nb-remind{background:#fdf7ee}
  .nb-remind .nb-dot{background:#d9930d}
  .nb-remind .nb-text{color:#7a4d0f}
  .nb-empty{padding:26px 14px;text-align:center;font-size:13px;color:#8a857d}
  @media (max-width:767.98px){.nb-panel{position:fixed;top:62px;right:8px}}
  @media print{.nb-wrap{display:none!important}}
</style>
<script>
  /* The bell — one script for however many bells the page has (normally one). */
  (function () {
    var BASE = <?php echo json_encode(url('/') . '/'); ?>;   /* Laravel: the app root */
    /* notifications.php still writes the old page paths ('admin/booking-request.php?id=..'); map them to the Laravel URLs */
    function nbUrl(link) { return String(link).replace('admin/venusep_staffManagement.php', 'admin/staff').replace(/\.php(?=$|\?)/, ''); }
    var CSRF = <?php echo json_encode(csrf_token()); ?>;
    var data = { unread: 0, items: [], reminders: [] };
    var open = false;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function each(sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

    function paint() {
      each('.nb-count', function (c) { c.hidden = !data.unread; c.textContent = data.unread > 99 ? '99+' : data.unread; });
      each('.nb-bell', function (b) {
        b.setAttribute('aria-label', data.unread ? 'Notifications, ' + data.unread + ' unread' : 'Notifications');
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      each('.nb-panel', function (p) {
        p.hidden = !open;
        if (!open) return;
        var html = '<div class="nb-head"><b>Notifications</b>' + (data.unread ? '<button type="button" class="nb-all" onclick="nbReadAll()">Mark all as read</button>' : '') + '</div>';
        data.reminders.forEach(function (r) {
          html += '<button type="button" class="nb-item nb-remind" onclick="nbGo(null, \'' + esc(r.link) + '\')"><span class="nb-dot"></span><span><span class="nb-text">' + esc(r.text) + '</span><span class="nb-ago">Reminder</span></span></button>';
        });
        data.items.forEach(function (n) {
          html += '<button type="button" class="nb-item' + (n.unread ? ' is-unread' : '') + '" onclick="nbGo(' + n.id + ', \'' + esc(n.link) + '\')"><span class="nb-dot"></span><span><span class="nb-text">' + esc(n.text) + '</span><span class="nb-ago">' + esc(n.ago) + '</span></span></button>';
        });
        if (!data.items.length && !data.reminders.length) html += '<div class="nb-empty">You are all caught up.</div>';
        p.innerHTML = html;
      });
    }
    function load() {
      return fetch(BASE + 'notifications', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (out) { if (out && out.ok) { data = out; paint(); } })
        .catch(function () {});
    }
    function post(fields) {
      var body = new URLSearchParams(Object.assign({ _token: CSRF }, fields));
      return fetch(BASE + 'notifications', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true })
        .then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    window.nbToggle = function (e) {
      e.stopPropagation();
      open = !open;
      paint();
      if (open) load();
    };
    /* Clicking one marks it read, then opens what it is about. */
    window.nbGo = function (id, link) {
      var go = function () { if (link) location.href = BASE + nbUrl(link); };
      if (id === null) { go(); return; }
      data.items.forEach(function (n) { if (n.id === id && n.unread) { n.unread = false; data.unread = Math.max(0, data.unread - 1); } });
      paint();
      post({ action: 'read', id: id }).then(go, go);
    };
    window.nbReadAll = function () {
      data.items.forEach(function (n) { n.unread = false; });
      data.unread = 0;
      paint();
      post({ action: 'read_all' });
    };

    document.addEventListener('click', function (e) {
      if (open && !e.target.closest('.nb-wrap')) { open = false; paint(); }
    });
    document.addEventListener('keydown', function (e) {
      if (open && e.key === 'Escape') { open = false; paint(); }
    });
    /* About once a minute while the page is in view; at once when it comes back. */
    setInterval(function () { if (!document.hidden) load(); }, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
  })();
</script>
<?php endif; ?>
