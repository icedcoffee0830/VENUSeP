/* Terms and Conditions in a pop-up on the same page (Aron 2026-11-26), not a new tab.
   Any <a data-terms-popup href="/terms"> opens a <dialog> with the terms page's own text
   (#pv-main), fetched once, so the wording lives in one place: resources/views/terms.blade.php.
   Without JavaScript, or if the fetch fails, the link just opens the page as before.
   "I agree" ticks the checkbox named in data-terms-for. Needs assets/css/terms-popup.css. */
(function () {
  let dialog = null;
  let loaded = null;   // the fetch, once per page

  function build() {
    dialog = document.createElement('dialog');
    dialog.className = 'tp-dialog';
    dialog.setAttribute('aria-labelledby', 'tpTitle');
    dialog.innerHTML =
      '<div class="tp-head"><h2 id="tpTitle">Terms and Conditions</h2>' +
      '<button type="button" class="tp-x" data-tp-close aria-label="Close">&times;</button></div>' +
      '<div class="tp-body" tabindex="0"><p class="tp-wait">Loading…</p></div>' +
      '<div class="tp-foot"><button type="button" class="tp-btn" data-tp-close>Close</button>' +
      '<button type="button" class="tp-btn tp-btn-primary" data-tp-agree>I agree</button></div>';
    document.body.appendChild(dialog);
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog || e.target.closest('[data-tp-close]')) dialog.close();   // the backdrop, ×, Close
    });
    return dialog;
  }

  function load(href) {
    loaded = loaded || fetch(href, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        const main = new DOMParser().parseFromString(html, 'text/html').querySelector('#pv-main .pv-wrap, #pv-main');
        if (!main) throw new Error('no terms text');
        main.querySelectorAll('h1, .pv-back').forEach(function (el) { el.remove(); });   // the pop-up has its own title; no "back" link
        return main.innerHTML;
      });
    return loaded;
  }

  document.addEventListener('click', function (e) {
    const link = e.target.closest('a[data-terms-popup]');
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey || typeof HTMLDialogElement === 'undefined') return;   // a new tab when asked for
    e.preventDefault();
    const d = dialog || build();
    const box = document.getElementById(link.getAttribute('data-terms-for') || '');
    d.querySelector('[data-tp-agree]').hidden = !box;
    d.querySelector('[data-tp-agree]').onclick = function () {
      if (box && !box.checked) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
      d.close();
    };
    d.showModal();
    const body = d.querySelector('.tp-body');
    load(link.href).then(function (html) { body.innerHTML = html; body.scrollTop = 0; })
      .catch(function () { d.close(); loaded = null; window.open(link.href, '_blank', 'noopener'); });
  });
})();
