/* =====================================================================
   LIVE — pages that update without a reload (Aron 2026-11-05). ALL of it
   is in this file; a page only says which parts of it may change. Loaded
   by the notification bell, so it is on every signed-in page.

   HOW A PAGE MARKS A PART. In the Blade view:
       @fragment('br-rows')<div data-live="br-rows">...</div>@endfragment
   and its controller returns live_view() instead of view(). The same URL with
   ?part=1 then answers with those parts only. A part that a script
   draws from data is a <script type="application/json" data-live="..."> tag;
   read it with Live.data(name).

   Live.post(url, fields)        POST with the CSRF token. Always resolves to
                                 the JSON reply; a network failure or an
                                 unreadable reply becomes { ok: false, message }.
   Live.send(url, fields, then)  Live.post, then the usual ending: a refusal
                                 is shown, a success is said and the parts are
                                 fetched again; `then` redraws from them.
   Live.refresh(url)             fetches the page's parts and swaps them in.
                                 url: another address for them (default: this page).
   Live.say(text, tone)          a short message at the corner; tone 'bad' = red.
   Live.watch(kind, onChange)    the 30-second check for this page: kind 'queue'
                                 (staff booking lists) or 'mine' (the customer's
                                 own bookings). onChange fetches and redraws;
                                 returning false means "not now, ask again".
   Live.onBell(fn)               the bell's own reload, when the notifications change.

   THE 30-SECOND CHECK. GET /live-check answers two tiny version strings: one
   for the bell, one for the page (app/Queries/LiveQueries.php). Only a changed
   version fetches anything. It waits while the tab is hidden, never runs twice
   at once (the built-in PHP server takes one request at a time), and leaves a
   page alone while a dialog is open or a field in a part is in use: that
   version stays unread, so the next check tries again.
   ===================================================================== */
(function () {
  'use strict';
  if (window.Live) return;

  var me = document.currentScript;
  var CHECK_URL = me.dataset.check, CSRF = me.dataset.csrf, EVERY = 30000;

  /* ---- the action helper ---- */
  function post(url, fields) {
    var body = fields instanceof FormData ? fields : new URLSearchParams(fields);
    body.set('_token', CSRF);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
      .catch(function () { return { ok: false, message: 'Could not reach the server, so nothing was changed.' }; });
  }

  function send(url, fields, then) {
    return post(url, fields).then(function (res) {
      if (!res.ok) {
        (window.venusepAlert || say)(res.message || 'Nothing was changed.', 'bad');
        return res;
      }
      say(res.message || 'Saved.');
      return refresh().then(then).then(function () { return res; });
    });
  }

  var box = document.createElement('div'), sayTimer = null;
  box.id = 'liveSay';
  box.setAttribute('role', 'status');
  box.hidden = true;
  box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:3000;max-width:min(420px,calc(100vw - 32px));' +
    'padding:.7rem 1rem;border-radius:10px;color:#fff;font:600 .85rem/1.4 Inter,system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25)';
  document.body.appendChild(box);
  function say(text, tone) {
    box.style.background = tone === 'bad' ? '#b23a3a' : '#1f1e1e';
    box.textContent = text;
    box.hidden = false;
    clearTimeout(sayTimer);
    sayTimer = setTimeout(function () { box.hidden = true; }, 5000);
  }

  /* ---- fetching the parts ---- */
  function swap(html) {
    var parsed = document.createElement('template');
    parsed.innerHTML = html;
    var x = window.scrollX, y = window.scrollY;
    parsed.content.querySelectorAll('[data-live]').forEach(function (fresh) {
      var old = document.querySelector('[data-live="' + fresh.dataset.live + '"]');
      if (old) old.replaceWith(fresh);
    });
    window.scrollTo(x, y);
  }

  function pull(url) {
    var to = new URL(url || location.href);
    to.searchParams.set('part', '1');
    return fetch(to, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(swap)
      .catch(function () {});
  }

  /* one at a time: an action's refresh waits for one already running */
  var line = Promise.resolve();
  function refresh(url) {
    line = line.then(function () { return pull(url); });
    return line;
  }

  function data(name) {
    return JSON.parse(document.querySelector('[data-live="' + name + '"]').textContent);
  }

  /* ---- the 30-second check ---- */
  var watching = null, onBell = null, seen = {}, timer = null, checking = false, stopped = false;

  function touched(field) {
    if (field.type === 'file') return field.files.length > 0;
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
    if (field.tagName === 'SELECT') return Array.prototype.some.call(field.options, function (o) { return o.selected !== o.defaultSelected; });
    return field.value !== field.defaultValue;
  }

  /* someone is in the middle of something a redraw would lose */
  function busy() {
    var openDialog = Array.prototype.some.call(document.querySelectorAll('dialog[open], [aria-modal="true"]'),
      function (d) { return d.getClientRects().length > 0; });
    if (openDialog) return true;
    return Array.prototype.some.call(document.querySelectorAll('[data-live] input, [data-live] textarea, [data-live] select'),
      function (f) { return f === document.activeElement || touched(f); });
  }

  function read(now) {
    if (seen.bell !== undefined && now.bell !== seen.bell && onBell) onBell();
    seen.bell = now.bell;
    if (!watching) return null;
    if (seen.page === undefined) { seen.page = now.page; return null; }   // the first look only sets the mark
    if (now.page === seen.page || busy()) return null;
    return Promise.resolve(watching.onChange()).then(function (result) {
      if (result !== false) seen.page = now.page;
    });
  }

  function check() {
    clearTimeout(timer);
    if (checking || stopped || document.hidden) return;   // coming back into view starts it again
    checking = true;
    fetch(CHECK_URL + (watching ? '?page=' + watching.kind : ''), {
      credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15000),
    })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) stopped = true;   // signed out: the next action says so
        return r.ok ? r.json() : null;
      })
      .then(function (now) { return now ? read(now) : null; })
      .catch(function () {})
      .then(function () {
        checking = false;
        timer = setTimeout(check, EVERY);
      });
  }

  document.addEventListener('visibilitychange', check);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check); else check();

  window.Live = {
    post: post, send: send, refresh: refresh, data: data, say: say,
    watch: function (kind, onChange) { watching = { kind: kind, onChange: onChange }; },
    onBell: function (fn) { onBell = fn; },
  };
})();
