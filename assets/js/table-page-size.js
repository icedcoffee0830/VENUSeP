/* =====================================================================
   TYPEABLE PAGE SIZE for a Tabulator table (2026-10-28).

   Replaces Tabulator's own "Page Size" <select> with one control that does
   both:
     - type any number (digits only, up to 4) — it applies after a short
       pause, or at once on Enter;
     - or open the menu (click the box or its chevron, or press ↓) and pick
       10 / 25 / 50 / 100 / All. The current size is ticked.
   Leaving the box empty puts the current size back. ↑ ↓ move through the
   menu, Enter picks, Escape closes.

   The menu is drawn on <body> (fixed, beside the box) rather than inside the
   table, because Tabulator's container clips anything that overflows it; it
   opens upward when there is no room below.

   Usage, after `new Tabulator(...)` with pagination on and NO
   paginationSizeSelector:
       venusepPageSize(table, [10, 25, 50, 100]);
   ===================================================================== */
(function () {
  var styled = false;
  function addStyles() {
    if (styled) return;
    styled = true;
    var css = document.createElement('style');
    css.textContent =
      '.vz-ps{display:inline-flex;align-items:center;gap:8px;margin-right:12px;font:600 13px Inter,system-ui,sans-serif;color:#5c584f;white-space:nowrap}' +
      '.vz-ps-box{display:inline-flex;align-items:stretch;height:32px;border:1px solid #d7d2ca;border-radius:9px;background:#fff;overflow:hidden;transition:border-color .15s,box-shadow .15s}' +
      '.vz-ps-box:hover{border-color:#bdb6ac}' +
      '.vz-ps-box.is-open,.vz-ps-box:focus-within{border-color:#a11626;box-shadow:0 0 0 3px rgba(161,22,38,.14)}' +
      '.vz-ps-box input{width:46px;border:0;outline:0;padding:0 4px 0 11px;background:transparent;color:#1c1b19;font:700 13px Inter,system-ui,sans-serif;cursor:text}' +
      '.vz-ps-box button{width:28px;border:0;border-left:1px solid #ece8e2;background:#faf8f5;color:#5c584f;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0}' +
      '.vz-ps-box button:hover{background:#f2ede7;color:#a11626}' +
      '.vz-ps-box button svg{transition:transform .15s}' +
      '.vz-ps-box.is-open button svg{transform:rotate(180deg)}' +
      '.vz-ps-menu{position:fixed;z-index:3000;min-width:120px;padding:5px;margin:0;list-style:none;background:#fff;border:1px solid #e7e2db;border-radius:11px;box-shadow:0 14px 34px rgba(29,18,20,.18);font:600 13px Inter,system-ui,sans-serif}' +
      '.vz-ps-menu li{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:7px 10px;border-radius:7px;color:#1c1b19;cursor:pointer}' +
      '.vz-ps-menu li:hover,.vz-ps-menu li.is-active{background:#f9eef0;color:#a11626}' +
      '.vz-ps-menu li .vz-ps-tick{visibility:hidden;color:#a11626}' +
      '.vz-ps-menu li[aria-selected="true"]{color:#a11626}' +
      '.vz-ps-menu li[aria-selected="true"] .vz-ps-tick{visibility:visible}' +
      '.vz-ps-menu .vz-ps-hint{padding:6px 10px 4px;margin-top:3px;border-top:1px solid #f0ece6;font-weight:500;font-size:11.5px;color:#8a857d;cursor:default}' +
      '.vz-ps-menu .vz-ps-hint:hover{background:none;color:#8a857d}' +
      '@media print{.vz-ps,.vz-ps-menu{display:none!important}}';
    document.head.appendChild(css);
  }

  var CHEVRON = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
  var TICK = '<svg class="vz-ps-tick" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

  window.venusepPageSize = function (table, presets) {
    presets = presets || [10, 25, 50, 100];
    addStyles();
    var uid = 'vzPs' + Math.random().toString(36).slice(2, 8);
    var choices = presets.map(String).concat(['All']);

    var wrap = document.createElement('div');
    wrap.className = 'vz-ps';
    wrap.innerHTML = '<label for="' + uid + '">Page size</label>' +
      '<span class="vz-ps-box">' +
        '<input id="' + uid + '" type="text" inputmode="numeric" autocomplete="off" maxlength="4" role="combobox"' +
        ' aria-autocomplete="none" aria-expanded="false" aria-controls="' + uid + 'Menu" title="Type a number, or pick one">' +
        '<button type="button" tabindex="-1" aria-label="Show page sizes">' + CHEVRON + '</button>' +
      '</span>';
    var box = wrap.querySelector('.vz-ps-box');
    var input = wrap.querySelector('input');
    var toggle = wrap.querySelector('button');

    var menu = document.createElement('ul');
    menu.className = 'vz-ps-menu';
    menu.id = uid + 'Menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    menu.innerHTML = choices.map(function (c) {
      return '<li role="option" data-size="' + c + '"><span>' + (c === 'All' ? 'All rows' : c + ' rows') + '</span>' + TICK + '</li>';
    }).join('') + '<li class="vz-ps-hint" aria-hidden="true">or type any number</li>';
    document.body.appendChild(menu);
    var items = Array.prototype.slice.call(menu.querySelectorAll('li[data-size]'));
    var active = -1, timer = null;

    function current() { var s = table.getPageSize(); return s === true ? 'All' : String(s); }
    function show() { input.value = current(); }
    function apply(value) {
      clearTimeout(timer);
      if (/^all$/i.test(value)) {
        table.setPageSize(true);
      } else {
        var n = parseInt(value, 10);
        if (!(n > 0)) return false;             /* empty or 0: nothing to apply */
        table.setPageSize(Math.min(n, 9999));
      }
      table.setPage(1);
      return true;
    }

    function place() {
      var r = box.getBoundingClientRect();
      menu.style.minWidth = Math.max(120, r.width) + 'px';
      var h = menu.offsetHeight;
      var below = window.innerHeight - r.bottom;
      menu.style.left = Math.min(r.left, window.innerWidth - menu.offsetWidth - 8) + 'px';
      menu.style.top = (below < h + 10 && r.top > h + 10 ? r.top - h - 6 : r.bottom + 6) + 'px';
    }
    function setActive(i) {
      active = i;
      items.forEach(function (li, k) { li.classList.toggle('is-active', k === i); });
    }
    function open() {
      if (!menu.hidden) return;
      var cur = current();
      items.forEach(function (li) { li.setAttribute('aria-selected', li.getAttribute('data-size') === cur ? 'true' : 'false'); });
      menu.hidden = false;
      box.classList.add('is-open');
      input.setAttribute('aria-expanded', 'true');
      setActive(choices.indexOf(cur));
      place();
    }
    function close() {
      if (menu.hidden) return;
      menu.hidden = true;
      box.classList.remove('is-open');
      input.setAttribute('aria-expanded', 'false');
      setActive(-1);
    }
    function pick(size) {
      apply(size);
      close();
      show();
      input.blur();
    }

    input.addEventListener('focus', function () { input.select(); open(); });
    input.addEventListener('click', open);
    toggle.addEventListener('mousedown', function (e) { e.preventDefault(); });   /* keep the box focused */
    toggle.addEventListener('click', function () {
      if (menu.hidden) { input.focus(); open(); } else { close(); }
    });
    menu.addEventListener('mousedown', function (e) { e.preventDefault(); });
    menu.addEventListener('click', function (e) {
      var li = e.target.closest('li[data-size]');
      if (li) pick(li.getAttribute('data-size'));
    });

    input.addEventListener('input', function () {
      var v = input.value.replace(/\D+/g, '').slice(0, 4);   /* numbers only */
      if (v !== input.value) input.value = v;
      close();
      clearTimeout(timer);
      if (v) timer = setTimeout(function () { apply(v); }, 700);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (menu.hidden) { open(); return; }
        var n = items.length;
        setActive(active < 0 ? (e.key === 'ArrowDown' ? 0 : n - 1) : (active + (e.key === 'ArrowDown' ? 1 : n - 1)) % n);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (!menu.hidden && active >= 0 && input.value === current()) { pick(items[active].getAttribute('data-size')); return; }
        if (!menu.hidden && active >= 0 && input.value === '') { pick(items[active].getAttribute('data-size')); return; }
        if (apply(input.value)) { close(); input.blur(); }
      } else if (e.key === 'Escape') {
        close();
        show();
        input.blur();
      } else if (e.key === 'Tab') {
        close();
      }
    });
    input.addEventListener('blur', function () {
      close();
      if (input.value && input.value !== current()) apply(input.value);
      show();
    });
    window.addEventListener('resize', function () { if (!menu.hidden) place(); });
    window.addEventListener('scroll', function () { if (!menu.hidden) place(); }, true);

    function mount() {
      var paginator = table.element.querySelector('.tabulator-paginator');
      if (!paginator || wrap.isConnected) return;
      paginator.insertBefore(wrap, paginator.firstChild);
      show();
    }
    table.on('tableBuilt', mount);
    table.on('pageSizeChanged', function () { if (document.activeElement !== input) show(); });
    mount();
  };
})();
