/* Script for legacy/includes/notification-bell.php. */
  /* The bell — one script for however many bells the page has (normally one). */
  (function () {
    var BASE = notificationBell.appRoot;   /* Laravel: the app root */
    /* notifications.php still writes the old page paths ('admin/booking-request.php?id=..'); map them to the Laravel URLs */
    function nbUrl(link) { return String(link).replace('admin/venusep_staffManagement.php', 'admin/staff').replace(/\.php(?=$|\?)/, ''); }
    var CSRF = notificationBell.csrf;
    var data = { unread: 0, items: [], reminders: [] };
    var open = false;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function each(sel, fn) { Array.prototype.forEach.call(document.querySelectorAll(sel), fn); }

    function paint() {
      each('.nb-count', function (c) { c.hidden = !data.unread; c.textContent = data.unread > 99 ? '99+' : data.unread; });
      each('.ct-dot', function (d) { d.hidden = !data.needsYou; });   /* the phone tab bar's Bookings tab */
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
        var top = p.scrollTop;   /* a reload under an open panel keeps its place */
        p.innerHTML = html;
        p.scrollTop = top;
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
    /* Once now; after that only when the 30-second check says something changed. The
       reminders (no read state) reload with every opening of the panel. */
    Live.onBell(load);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
  })();
