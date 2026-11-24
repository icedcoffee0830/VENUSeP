/* The copy button beside a booking reference (VB-…), on both Transaction History pages.
   refWithCopy(ref) is the reference (escaped) followed by its button; one click handler
   serves every such button.
   Styles: assets/css/copy-ref.css. */
(function () {
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  window.refWithCopy = (ref) => `${esc(ref)}<button type="button" class="copy-ref" data-copy-ref="${esc(ref)}" aria-label="Copy reference number" title="Copy reference number"><i class="bi bi-copy" aria-hidden="true"></i></button>`;

  /* One small label over the button that was pressed: seen, and read out (role="status"). */
  const tip = document.createElement('div');
  tip.className = 'copy-ref-tip';
  tip.setAttribute('role', 'status');
  document.body.appendChild(tip);
  let timer = 0;
  const say = (btn, text, ms) => {
    const r = btn.getBoundingClientRect();
    tip.textContent = text;
    tip.style.left = (r.left + r.width / 2) + 'px';
    tip.style.top = (r.top - 6) + 'px';
    tip.classList.add('is-on');
    clearTimeout(timer);
    timer = setTimeout(() => { tip.classList.remove('is-on'); tip.textContent = ''; }, ms);
  };

  /* No clipboard API on a page served over plain http (not localhost): the old way. */
  const fallback = (text) => {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  };
  const copy = (text) => (navigator.clipboard && window.isSecureContext
    ? navigator.clipboard.writeText(text).then(() => true, () => fallback(text))
    : Promise.resolve(fallback(text)));

  /* Capture phase: the click is ours alone, so it never reaches the table row behind it. */
  document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-copy-ref]');
    if (!btn) return;
    event.stopPropagation();
    event.preventDefault();
    copy(btn.dataset.copyRef).then((ok) => (ok ? say(btn, 'Copied', 1600) : say(btn, 'Couldn’t copy. Select it and press Ctrl+C', 4000)));
  }, true);
})();
